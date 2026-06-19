<?php

namespace App\Services\Rosters;

use App\Enums\ShiftSource;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\DeterministicRandom;
use App\Services\Rosters\Planning\HardConstraintChecker;
use App\Services\Rosters\Planning\PlanImprover;
use App\Services\Rosters\Planning\PlannedAssignment;
use App\Services\Rosters\Planning\PlanningContext;
use App\Services\Rosters\Planning\SoftPenaltyEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Zweiphasiger Dienstplangenerator: Konstruktion (schwierigste Slots zuerst,
 * Kandidat mit der geringsten Strafe der weichen Ziele) und anschließende
 * lokale Suche, die den Plan per Tausch- und Verschiebezügen verbessert.
 */
class RosterGeneratorService
{
    public function __construct(
        private readonly RosterDateService $rosterDateService,
        private readonly HardConstraintChecker $hardConstraints,
        private readonly SoftPenaltyEvaluator $evaluator,
        private readonly PlanImprover $improver,
    ) {}

    public function generate(Roster $roster): RosterGenerationResult
    {
        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können automatisch geplant werden.',
            ]);
        }

        return DB::transaction(function () use ($roster): RosterGenerationResult {
            $result = new RosterGenerationResult;

            $roster->load(['location']);

            $deletedAutoShifts = Shift::query()
                ->where('roster_id', $roster->id)
                ->where('source', ShiftSource::Auto->value)
                ->delete();

            $result->addDeletedAutoShifts($deletedAutoShifts);

            $context = new PlanningContext($roster);
            $assignments = $this->plan($roster, $context, $result);

            foreach ($assignments as $assignment) {
                Shift::query()->create([
                    'roster_id' => $roster->id,
                    'location_id' => $roster->location_id,
                    'user_id' => $assignment->employee->id,
                    'shift_template_id' => $assignment->shiftTemplate->id,
                    'date' => $assignment->date->toDateString(),
                    'starts_at' => $assignment->startsAt,
                    'ends_at' => $assignment->endsAt,
                    'source' => ShiftSource::Auto,
                    'note' => null,
                ]);
            }

            return $result;
        });
    }

    /**
     * Vorschau: identische Planung ohne Persistenz. Bestehende Auto-Dienste
     * werden ignoriert (als ob sie ersetzt würden), manuelle bleiben fix.
     *
     * @return array{0: RosterGenerationResult, 1: Collection<int, Shift>}
     */
    public function preview(Roster $roster): array
    {
        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können automatisch geplant werden.',
            ]);
        }

        $result = new RosterGenerationResult;

        $roster->load(['location']);

        $result->addDeletedAutoShifts(Shift::query()
            ->where('roster_id', $roster->id)
            ->where('source', ShiftSource::Auto->value)
            ->count());

        $context = new PlanningContext($roster, ignoreAutoShifts: true);
        $assignments = $this->plan($roster, $context, $result);

        foreach ($assignments as $assignment) {
            $result->plannedAssignments[] = [
                'userId' => $assignment->employee->id,
                'employeeName' => $assignment->employee->name,
                'shiftTemplateId' => $assignment->shiftTemplate->id,
                'shiftTemplateName' => $assignment->shiftTemplate->name,
                'shiftTemplateCode' => $assignment->shiftTemplate->code,
                'date' => $assignment->date->toDateString(),
                'startsAt' => $assignment->startsAt->toDateTimeString(),
                'endsAt' => $assignment->endsAt->toDateTimeString(),
            ];
        }

        foreach ($context->employees as $employee) {
            $result->employeeStats[] = [
                'userId' => $employee->id,
                'employeeName' => $employee->name,
                'plannedMinutes' => $context->plannedMinutesFor($employee),
                'targetMinutes' => $context->targetMinutesFor($employee),
                'utilizationPermille' => $context->utilizationPermilleFor($employee),
                'nightShifts' => $context->nightShiftCountFor($employee),
                'weekends' => $context->weekendCountFor($employee),
                'shiftCount' => $context->shiftCountFor($employee),
            ];
        }

        $previewShifts = $this->previewShiftModels($roster, $assignments);

        return [$result, $previewShifts];
    }

    public function deleteAutoShifts(Roster $roster): RosterGenerationResult
    {
        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können automatisch geplante Dienste zurücksetzen.',
            ]);
        }

        $result = new RosterGenerationResult;

        $deletedAutoShifts = Shift::query()
            ->where('roster_id', $roster->id)
            ->where('source', ShiftSource::Auto->value)
            ->delete();

        $result->addDeletedAutoShifts($deletedAutoShifts);

        return $result;
    }

    /**
     * Konstruktion, Verbesserung und Nachbesetzung. Liefert die geplanten
     * Zuweisungen; Skips und Hinweise landen im Ergebnis.
     *
     * @return array<int, PlannedAssignment>
     */
    private function plan(Roster $roster, PlanningContext $context, RosterGenerationResult $result): array
    {
        $slots = $this->plannableSlots($roster, $context, $result);

        $assignments = [];
        $unfilledDemands = [];

        $seed = config('rostering.improvement.seed');
        $random = new DeterministicRandom($seed !== null ? (int) $seed : crc32($roster->id));

        // Phase 1: Urlaubssperren- und Wochenend-Slots planen und sofort
        // verbessern — jetzt blockieren noch keine Werktagsketten (Ruhezeit,
        // Folgetage), nur jetzt lässt sich die Wochenendlast frei verteilen.
        $prioritySlots = array_filter($slots, fn (array $slot): bool => $context->isBlackoutDate($slot['date'])
            || $context->weekendStartKeyFor($slot['date']) !== null);
        $regularSlots = array_filter($slots, fn (array $slot): bool => ! $context->isBlackoutDate($slot['date'])
            && $context->weekendStartKeyFor($slot['date']) === null);

        foreach ($prioritySlots as $slot) {
            foreach ([true, false] as $needSpecialist) {
                if (! $this->fillDemand($context, $slot, $needSpecialist, $assignments)) {
                    $unfilledDemands[] = ['slot' => $slot, 'needSpecialist' => $needSpecialist];
                }
            }
        }

        $result->penaltyTotal += $this->improver->improve($context, $assignments, $random);

        // Phase 2: restliche Slots planen, dann den Gesamtplan verbessern.
        foreach ($regularSlots as $slot) {
            foreach ([true, false] as $needSpecialist) {
                if (! $this->fillDemand($context, $slot, $needSpecialist, $assignments)) {
                    $unfilledDemands[] = ['slot' => $slot, 'needSpecialist' => $needSpecialist];
                }
            }
        }

        $result->penaltyTotal += $this->improver->improve($context, $assignments, $random);

        // Nachbesetzung: Die Verbesserungsphase kann Kapazitaeten freigeraeumt
        // haben; zur Not raeumt die Reparatur blockierende Dienste gezielt um.
        foreach ($unfilledDemands as $index => $demand) {
            if ($this->fillDemand($context, $demand['slot'], $demand['needSpecialist'], $assignments)
                || $this->repairDemand($context, $demand['slot'], $demand['needSpecialist'], $assignments)) {
                unset($unfilledDemands[$index]);
            }
        }

        foreach ($unfilledDemands as $demand) {
            $this->addNoCandidateSkip($context, $demand['slot'], $demand['needSpecialist'], $result);
        }

        foreach ($assignments as $assignment) {
            $result->addCreatedShift();
            $this->collectAssignmentWarnings($context, $assignment, $result);
        }

        return $assignments;
    }

    /**
     * Reparatur für unbesetzbare Bedarfe (Ejection-Kette der Tiefe 1):
     * Findet einen Mitarbeiter, den nur ein einzelner umplanbarer Dienst
     * blockiert (Ruhezeit oder Folgetage), verschiebt diesen Dienst auf einen
     * anderen Mitarbeiter und besetzt den offenen Slot. Besetzung steht
     * lexikographisch über den weichen Zielen, daher zählt hier nur
     * Machbarkeit, nicht die Strafänderung.
     *
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     * @param  array<int, PlannedAssignment>  $assignments
     */
    private function repairDemand(
        PlanningContext $context,
        array $slot,
        bool $needSpecialist,
        array &$assignments,
    ): bool {
        foreach ($this->sortedCandidates($context) as $employee) {
            $failed = $this->hardConstraints->firstFailedConstraint(
                $context,
                $employee,
                $slot['template'],
                $slot['date'],
                $slot['startsAt'],
                $slot['endsAt'],
                $needSpecialist,
                relaxWeekendLimit: true,
            );

            if ($failed === null || ! in_array($failed, ['rest_period', 'consecutive_days'], true)) {
                continue;
            }

            // Umplanbare Blocker: eigene Auto-Dienste am Vortag, Tag oder Folgetag.
            $blockers = array_filter($assignments, fn (PlannedAssignment $assignment): bool => $assignment->employee->id === $employee->id
                && abs($assignment->date->diffInDays($slot['date'], false)) <= 1);

            foreach ($blockers as $blocker) {
                $context->removeAssignment($blocker);

                $stillFailed = $this->hardConstraints->firstFailedConstraint(
                    $context,
                    $employee,
                    $slot['template'],
                    $slot['date'],
                    $slot['startsAt'],
                    $slot['endsAt'],
                    $needSpecialist,
                    relaxWeekendLimit: true,
                );

                $receiver = $stillFailed === null
                    ? $this->receiverForBlocker($context, $blocker)
                    : null;

                if ($receiver === null) {
                    $context->addAssignment($blocker);

                    continue;
                }

                $blocker->employee = $receiver;
                $context->addAssignment($blocker);

                $replacement = new PlannedAssignment(
                    $employee,
                    $slot['template'],
                    $slot['date'],
                    $slot['startsAt'],
                    $slot['endsAt'],
                );

                $context->addAssignment($replacement);
                $assignments[] = $replacement;

                // Restbedarf des Slots normal weiter auffuellen.
                $this->fillDemand($context, $slot, $needSpecialist, $assignments);

                return true;
            }
        }

        return false;
    }

    /**
     * Sucht einen Mitarbeiter, der den umzuplanenden Blocker-Dienst regelkonform
     * übernehmen kann (inklusive Fachkraftquote seines Slots).
     */
    private function receiverForBlocker(PlanningContext $context, PlannedAssignment $blocker): ?User
    {
        $requiredSpecialists = $context
            ->staffingRuleFor($blocker->shiftTemplate, $blocker->date)
            ?->required_specialists ?? 0;

        foreach ($this->sortedCandidates($context) as $receiver) {
            if ($receiver->id === $blocker->employee->id) {
                continue;
            }

            $specialistsAfter = $context->slotSpecialistCount($blocker->date, $blocker->shiftTemplate)
                + ($context->isSpecialist($receiver) ? 1 : 0);

            if ($specialistsAfter < $requiredSpecialists) {
                continue;
            }

            $failed = $this->hardConstraints->firstFailedConstraint(
                $context,
                $receiver,
                $blocker->shiftTemplate,
                $blocker->date,
                $blocker->startsAt,
                $blocker->endsAt,
                needSpecialist: false,
                relaxWeekendLimit: true,
            );

            if ($failed === null) {
                return $receiver;
            }
        }

        return null;
    }

    /**
     * Baut die zu planenden Slots auf und ordnet sie nach Schwierigkeit:
     * Urlaubssperren-Tage (hoher Bedarf) zuerst, dann Wochenenden (solange
     * alle Mitarbeiter noch freie Folgetage- und Ruhezeit-Budgets haben —
     * nur so lässt sich die Wochenendlast fair verteilen), dann Schichten
     * mit dem kleinsten Kandidatenkreis (z. B. Nachtdienst), damit knappe
     * Besetzungen nicht von leicht besetzbaren Slots leergeplant werden.
     *
     * @return array<int, array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}>
     */
    private function plannableSlots(Roster $roster, PlanningContext $context, RosterGenerationResult $result): array
    {
        $capablePoolSizes = [];

        foreach ($context->shiftTemplates as $shiftTemplate) {
            $capablePoolSizes[$shiftTemplate->id] = $context->employees
                ->filter(fn (User $employee): bool => $this->hardConstraints->employeeCanWorkShiftTemplate($employee, $shiftTemplate))
                ->count();
        }

        $slots = [];

        foreach ($this->rosterDateService->datesForRosterMonth($roster) as $date) {
            foreach ($context->shiftTemplates as $shiftTemplate) {
                $staffingRule = $context->staffingRuleFor($shiftTemplate, $date);

                if ($staffingRule === null) {
                    $result->addSkipped('missing_staffing_rule', 'Für diese Schichtvorlage gibt es keine Besetzungsregel.', [
                        'date' => $date->toDateString(),
                        'shiftTemplateId' => $shiftTemplate->id,
                        'shiftTemplateCode' => $shiftTemplate->code,
                    ]);

                    continue;
                }

                [$startsAt, $endsAt] = $this->rosterDateService->buildShiftTimes($date, $shiftTemplate);

                $slots[] = [
                    'date' => $date,
                    'template' => $shiftTemplate,
                    'rule' => $staffingRule,
                    'startsAt' => $startsAt,
                    'endsAt' => $endsAt,
                ];
            }
        }

        usort($slots, function (array $first, array $second) use ($context, $capablePoolSizes): int {
            return [
                $context->isBlackoutDate($first['date']) ? 0 : 1,
                $context->weekendStartKeyFor($first['date']) === null ? 1 : 0,
                $capablePoolSizes[$first['template']->id],
                $first['date']->toDateString(),
                (string) $first['template']->starts_at,
                $first['template']->id,
            ] <=> [
                $context->isBlackoutDate($second['date']) ? 0 : 1,
                $context->weekendStartKeyFor($second['date']) === null ? 1 : 0,
                $capablePoolSizes[$second['template']->id],
                $second['date']->toDateString(),
                (string) $second['template']->starts_at,
                $second['template']->id,
            ];
        });

        return $slots;
    }

    /**
     * Besetzt den Bedarf eines Slots vollständig. Liefert false, wenn der
     * Bedarf mangels Kandidaten offen bleibt.
     *
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     * @param  array<int, PlannedAssignment>  $assignments
     */
    private function fillDemand(
        PlanningContext $context,
        array $slot,
        bool $needSpecialist,
        array &$assignments,
    ): bool {
        $required = $needSpecialist
            ? $slot['rule']->required_specialists
            : $slot['rule']->required_total_staff;

        while ($this->currentSlotCount($context, $slot, $needSpecialist) < $required) {
            $candidate = $this->bestCandidate($context, $slot, $needSpecialist);

            if ($candidate === null) {
                return false;
            }

            $assignment = new PlannedAssignment(
                $candidate,
                $slot['template'],
                $slot['date'],
                $slot['startsAt'],
                $slot['endsAt'],
            );

            $context->addAssignment($assignment);
            $assignments[] = $assignment;
        }

        return true;
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function currentSlotCount(PlanningContext $context, array $slot, bool $needSpecialist): int
    {
        return $needSpecialist
            ? $context->slotSpecialistCount($slot['date'], $slot['template'])
            : $context->slotTotalStaff($slot['date'], $slot['template']);
    }

    /**
     * Wählt unter allen regelkonformen Kandidaten den mit der geringsten
     * Strafänderung der weichen Ziele; bei Gleichstand entscheidet die
     * Fairness-Sortierung (Auslastung, Minuten, Dienste, Name).
     *
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function bestCandidate(PlanningContext $context, array $slot, bool $needSpecialist): ?User
    {
        $candidate = $this->bestCandidateWithConstraints($context, $slot, $needSpecialist, relaxWeekendLimit: false);

        if ($candidate !== null || ! (bool) config('rostering.relax_weekend_limit_for_coverage')) {
            return $candidate;
        }

        // Bliebe der Slot sonst unbesetzt, darf als einzige Regel das
        // Wochenend-Limit weichen (Besetzung schlaegt Empfehlung).
        return $this->bestCandidateWithConstraints($context, $slot, $needSpecialist, relaxWeekendLimit: true);
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function bestCandidateWithConstraints(
        PlanningContext $context,
        array $slot,
        bool $needSpecialist,
        bool $relaxWeekendLimit,
    ): ?User {
        $best = null;
        $bestDelta = null;

        foreach ($this->sortedCandidates($context) as $employee) {
            $failedConstraint = $this->hardConstraints->firstFailedConstraint(
                $context,
                $employee,
                $slot['template'],
                $slot['date'],
                $slot['startsAt'],
                $slot['endsAt'],
                $needSpecialist,
                $relaxWeekendLimit,
            );

            if ($failedConstraint !== null) {
                continue;
            }

            $delta = $this->evaluator->deltaForAdd(
                $context,
                $employee,
                $slot['template'],
                $slot['date'],
                $slot['startsAt'],
                $slot['endsAt'],
            );

            if ($bestDelta === null || $delta < $bestDelta) {
                $best = $employee;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function addNoCandidateSkip(
        PlanningContext $context,
        array $slot,
        bool $needSpecialist,
        RosterGenerationResult $result,
    ): void {
        $rejections = [];

        foreach ($context->employees as $employee) {
            $failedConstraint = $this->hardConstraints->firstFailedConstraint(
                $context,
                $employee,
                $slot['template'],
                $slot['date'],
                $slot['startsAt'],
                $slot['endsAt'],
                $needSpecialist,
            );

            if ($failedConstraint !== null) {
                $rejections[$failedConstraint] = ($rejections[$failedConstraint] ?? 0) + 1;
            }
        }

        $result->addSkipped('no_candidate', 'Es wurde kein geeigneter Mitarbeiter gefunden.', [
            'date' => $slot['date']->toDateString(),
            'shiftTemplateId' => $slot['template']->id,
            'shiftTemplateCode' => $slot['template']->code,
            'needSpecialist' => $needSpecialist,
            'reason' => $needSpecialist ? 'no_available_specialist' : 'no_available_employee',
            'rejections' => $rejections,
        ]);
    }

    private function collectAssignmentWarnings(
        PlanningContext $context,
        PlannedAssignment $assignment,
        RosterGenerationResult $result,
    ): void {
        $dateKey = $assignment->date->toDateString();

        if ($context->hasRequestedAbsenceOverlap($assignment->employee, $assignment->startsAt, $assignment->endsAt)) {
            $result->addWarning(
                'pending_absence_overlap',
                'Der Mitarbeiter hat eine noch offene Abwesenheitsanfrage an diesem Tag.',
                [
                    'date' => $dateKey,
                    'shiftTemplateId' => $assignment->shiftTemplate->id,
                    'shiftTemplateCode' => $assignment->shiftTemplate->code,
                    'userId' => $assignment->employee->id,
                    'employeeName' => $assignment->employee->name,
                ],
            );
        }

        if ($context->hasWishFree($assignment->employee, $dateKey)) {
            $result->addWarning(
                'wish_free_overridden',
                'Der Wunschfrei-Tag konnte wegen der Besetzung nicht berücksichtigt werden.',
                [
                    'date' => $dateKey,
                    'shiftTemplateId' => $assignment->shiftTemplate->id,
                    'shiftTemplateCode' => $assignment->shiftTemplate->code,
                    'userId' => $assignment->employee->id,
                    'employeeName' => $assignment->employee->name,
                ],
            );
        }
    }

    /**
     * Fairness-Sortierung: am wenigsten ausgelastete Mitarbeiter zuerst.
     *
     * @return Collection<int, User>
     */
    private function sortedCandidates(PlanningContext $context): Collection
    {
        // Hinweis: Collection::sortBy([...Closures]) ruft jede Closure als
        // Komparator ($a, $b) auf (sortByMany), nicht als Schlüssel — einargumentige
        // Schlüsselfunktionen liefern dort eine willkürliche Reihenfolge. Daher ein
        // expliziter Vergleich über das lexikographische Array der Fairness-Schlüssel.
        return $context->employees
            ->sort(fn (User $a, User $b): int => [
                $context->utilizationPermilleFor($a),
                $context->plannedMinutesFor($a),
                $context->shiftCountFor($a),
                $a->name,
            ] <=> [
                $context->utilizationPermilleFor($b),
                $context->plannedMinutesFor($b),
                $context->shiftCountFor($b),
                $b->name,
            ])
            ->values();
    }

    /**
     * Baut nicht persistierte Shift-Modelle für die Vorschau-Validierung:
     * manuelle Dienste aus der Datenbank plus die geplanten Zuweisungen.
     *
     * @param  array<int, PlannedAssignment>  $assignments
     * @return Collection<int, Shift>
     */
    private function previewShiftModels(Roster $roster, array $assignments): Collection
    {
        $manualShifts = $roster->shifts()
            ->with(['user.employeeProfile', 'shiftTemplate'])
            ->where('source', '!=', ShiftSource::Auto->value)
            ->get();

        $plannedShifts = collect($assignments)->map(function (PlannedAssignment $assignment) use ($roster): Shift {
            $shift = new Shift([
                'roster_id' => $roster->id,
                'location_id' => $roster->location_id,
                'user_id' => $assignment->employee->id,
                'shift_template_id' => $assignment->shiftTemplate->id,
                'date' => $assignment->date->toDateString(),
                'starts_at' => $assignment->startsAt,
                'ends_at' => $assignment->endsAt,
                'source' => ShiftSource::Auto,
            ]);

            $shift->setRelation('user', $assignment->employee);
            $shift->setRelation('shiftTemplate', $assignment->shiftTemplate);

            return $shift;
        });

        return $manualShifts->toBase()->concat($plannedShifts)->values();
    }
}
