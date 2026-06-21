<?php

namespace App\Services\Rosters;

use App\Enums\ShiftSource;
use App\Models\AbsenceRequest;
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

            // Selbstheilung: Dienste entfernen, die mit einer genehmigten
            // Abwesenheit kollidieren (auch manuelle) – niemand kann im Urlaub
            // arbeiten. So lösen sich auch Alt-Konflikte beim Neugenerieren.
            $this->removeApprovedAbsenceConflicts($roster);

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
     * Entfernt Dienste, die mit einer genehmigten Abwesenheit kollidieren
     * (jede Quelle). So kann niemand im Urlaub eingeplant sein und Alt-Konflikte
     * lösen sich beim Neugenerieren von selbst.
     */
    private function removeApprovedAbsenceConflicts(Roster $roster): void
    {
        $monthStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth();

        $absences = AbsenceRequest::query()
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $monthEnd->toDateString())
            ->whereDate('ends_on', '>=', $monthStart->toDateString())
            ->get(['user_id', 'starts_on', 'ends_on']);

        foreach ($absences as $absence) {
            Shift::query()
                ->where('roster_id', $roster->id)
                ->where('user_id', $absence->user_id)
                ->whereDate('date', '>=', $absence->starts_on->toDateString())
                ->whereDate('date', '<=', $absence->ends_on->toDateString())
                ->delete();
        }
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

        // Phase 3 (Aufstockung): Über die Mindestbesetzung hinaus bis zur
        // Idealbesetzung (target_total_staff) auffüllen, solange Mitarbeitende
        // ihr Monats-Soll noch nicht erreicht haben. Die Mindestbesetzung ist
        // damit ein Boden, kein Deckel; die Verteilung Früh>Spät>Nacht ergibt
        // sich aus den Idealzahlen je Schicht.
        $this->fillTargets($context, $slots, $assignments);

        $result->penaltyTotal += $this->improver->improve($context, $assignments, $random);

        // Garantie: kein Mitarbeiter über Monats-Soll. Die Verbesserungsphase kann
        // Dienste so verschoben haben, dass jemand sein Soll überschreitet —
        // solche Aufstock-Dienste werden wieder entfernt, solange der Slot dabei
        // nicht unter die Mindestbesetzung fällt (Mindestbesetzung schlägt Soll).
        $this->trimOverTarget($context, $assignments);

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
            foreach ($slot['templates'] as $entry) {
                $failed = $this->hardConstraints->firstFailedConstraint(
                    $context,
                    $employee,
                    $entry['template'],
                    $slot['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
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
                        $entry['template'],
                        $slot['date'],
                        $entry['startsAt'],
                        $entry['endsAt'],
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
                        $entry['template'],
                        $slot['date'],
                        $entry['startsAt'],
                        $entry['endsAt'],
                    );

                    $context->addAssignment($replacement);
                    $assignments[] = $replacement;

                    // Restbedarf der Kategorie normal weiter auffuellen.
                    $this->fillDemand($context, $slot, $needSpecialist, $assignments);

                    return true;
                }
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
            ->categoryStaffingFor($blocker->shiftTemplate->category, $blocker->date)
            ?->required_specialists ?? 0;

        foreach ($this->sortedCandidates($context) as $receiver) {
            if ($receiver->id === $blocker->employee->id) {
                continue;
            }

            $specialistsAfter = $context->slotCategorySpecialistCount($blocker->date, $blocker->shiftTemplate->category)
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
        // Kandidatenkreis je Kategorie (die Fähigkeit can_work_* gilt pro Kategorie).
        $capablePoolSizes = [];

        foreach ($context->categoriesWithTemplates() as $category) {
            $sample = $context->templatesForCategory($category)->first();
            $capablePoolSizes[$category] = $sample === null ? 0 : $context->employees
                ->filter(fn (User $employee): bool => $this->hardConstraints->employeeCanWorkShiftTemplate($employee, $sample))
                ->count();
        }

        $slots = [];

        foreach ($this->rosterDateService->datesForRosterMonth($roster) as $date) {
            foreach ($context->categoriesWithTemplates() as $category) {
                $rule = $context->categoryStaffingFor($category, $date);

                if ($rule === null) {
                    $result->addSkipped('missing_staffing_rule', 'Für diese Schicht-Kategorie gibt es keine Besetzungsregel.', [
                        'date' => $date->toDateString(),
                        'category' => $category,
                        'shiftTemplateCode' => $category,
                    ]);

                    continue;
                }

                $entries = [];

                foreach ($context->templatesForCategory($category) as $shiftTemplate) {
                    [$startsAt, $endsAt] = $this->rosterDateService->buildShiftTimes($date, $shiftTemplate);
                    $entries[] = ['template' => $shiftTemplate, 'startsAt' => $startsAt, 'endsAt' => $endsAt];
                }

                if ($entries === []) {
                    continue;
                }

                $slots[] = [
                    'date' => $date,
                    'category' => $category,
                    'rule' => $rule,
                    'templates' => $entries,
                ];
            }
        }

        usort($slots, function (array $first, array $second) use ($context, $capablePoolSizes): int {
            return [
                $context->isBlackoutDate($first['date']) ? 0 : 1,
                $context->weekendStartKeyFor($first['date']) === null ? 1 : 0,
                $capablePoolSizes[$first['category']] ?? 0,
                $first['date']->toDateString(),
                $this->categoryRank($first['category']),
            ] <=> [
                $context->isBlackoutDate($second['date']) ? 0 : 1,
                $context->weekendStartKeyFor($second['date']) === null ? 1 : 0,
                $capablePoolSizes[$second['category']] ?? 0,
                $second['date']->toDateString(),
                $this->categoryRank($second['category']),
            ];
        });

        return $slots;
    }

    private function categoryRank(string $category): int
    {
        return match ($category) {
            'early' => 0,
            'late' => 1,
            'night' => 2,
            default => 3,
        };
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

        // Bedarf gilt pro Kategorie/Tag und wird durch die SUMME über alle
        // Schichten der Kategorie erfüllt (Früh1 + Früh2 …), nicht pro Einzelschicht.
        while ($this->currentSlotCount($context, $slot, $needSpecialist) < $required) {
            $pick = $this->bestCandidate($context, $slot, $needSpecialist);

            if ($pick === null) {
                return false;
            }

            $assignment = new PlannedAssignment(
                $pick['employee'],
                $pick['entry']['template'],
                $slot['date'],
                $pick['entry']['startsAt'],
                $pick['entry']['endsAt'],
            );

            $context->addAssignment($assignment);
            $assignments[] = $assignment;
        }

        return true;
    }

    /**
     * Aufstockung über die Mindestbesetzung hinaus: Setzt wiederholt den am
     * stärksten unter Monats-Soll liegenden Mitarbeiter auf den besten
     * regelkonformen Slot, dessen Idealbesetzung (target_total_staff) noch
     * nicht erreicht ist. Nach jeder Zuweisung wird neu sortiert, damit die
     * Last fair zu den am wenigsten ausgelasteten Mitarbeitern fließt. Endet,
     * sobald niemand mehr unter Soll regelkonform untergebracht werden kann.
     *
     * @param  array<int, array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}>  $slots
     * @param  array<int, PlannedAssignment>  $assignments
     */
    private function fillTargets(PlanningContext $context, array $slots, array &$assignments): void
    {
        do {
            $placed = false;

            foreach ($this->underTargetCandidates($context) as $employee) {
                $pick = $this->bestTopUpSlot($context, $employee, $slots);

                if ($pick === null) {
                    continue;
                }

                $entry = $pick['entry'];

                $assignment = new PlannedAssignment(
                    $employee,
                    $entry['template'],
                    $pick['slot']['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
                );

                $context->addAssignment($assignment);
                $assignments[] = $assignment;
                $placed = true;

                // Nach jeder Zuweisung neu sortieren (am wenigsten Ausgelastete zuerst).
                break;
            }
        } while ($placed);
    }

    /**
     * Entfernt überschüssige Dienste von Mitarbeitenden über ihrem Monats-Soll,
     * den größten Überschuss zuerst. Ein Dienst wird nur entfernt, wenn sein
     * Slot dadurch nicht unter die Mindestbesetzung (gesamt bzw. Fachkräfte)
     * fällt — die Mindestbesetzung hat Vorrang vor der Soll-Obergrenze.
     *
     * @param  array<int, PlannedAssignment>  $assignments
     */
    private function trimOverTarget(PlanningContext $context, array &$assignments): void
    {
        while (true) {
            $overEmployees = $context->employees
                ->filter(fn (User $employee): bool => $context->targetMinutesFor($employee) > 0
                    && $context->plannedMinutesFor($employee) > $context->targetMinutesFor($employee))
                ->sort(fn (User $a, User $b): int => ($context->plannedMinutesFor($b) - $context->targetMinutesFor($b))
                    <=> ($context->plannedMinutesFor($a) - $context->targetMinutesFor($a)))
                ->values();

            $removed = false;

            foreach ($overEmployees as $employee) {
                $removable = $this->removableAssignmentFor($context, $employee, $assignments);

                if ($removable === null) {
                    continue;
                }

                $context->removeAssignment($removable);

                foreach ($assignments as $index => $assignment) {
                    if ($assignment === $removable) {
                        unset($assignments[$index]);

                        break;
                    }
                }

                $assignments = array_values($assignments);
                $removed = true;

                break;
            }

            if (! $removed) {
                return;
            }
        }
    }

    /**
     * Findet einen entfernbaren Dienst des Mitarbeiters, dessen Slot über der
     * Mindestbesetzung liegt (bei Fachkräften auch über der Fachkraft-Mindestzahl).
     *
     * @param  array<int, PlannedAssignment>  $assignments
     */
    private function removableAssignmentFor(PlanningContext $context, User $employee, array $assignments): ?PlannedAssignment
    {
        foreach ($assignments as $assignment) {
            if ($assignment->employee->id !== $employee->id) {
                continue;
            }

            $category = $assignment->shiftTemplate->category;
            $rule = $context->categoryStaffingFor($category, $assignment->date);
            $minTotal = $rule?->required_total_staff ?? 0;
            $minSpecialists = $rule?->required_specialists ?? 0;

            if ($context->slotCategoryTotal($assignment->date, $category) <= $minTotal) {
                continue;
            }

            if ($context->isSpecialist($employee)
                && $context->slotCategorySpecialistCount($assignment->date, $category) <= $minSpecialists) {
                continue;
            }

            return $assignment;
        }

        return null;
    }

    /**
     * Mitarbeitende unter ihrem Monats-Soll, am wenigsten ausgelastete zuerst.
     * Wer kein Soll hat (z. B. Reinigung/PDL), bleibt außen vor.
     *
     * @return Collection<int, User>
     */
    private function underTargetCandidates(PlanningContext $context): Collection
    {
        return $this->sortedCandidates($context)
            ->filter(fn (User $employee): bool => $context->utilizationPermilleFor($employee) < 1000)
            ->values();
    }

    /**
     * Bester Slot für die Aufstockung eines Mitarbeiters: regelkonform und mit
     * Restbedarf bis zur Idealbesetzung. Auswahl primär nach geringster Strafe
     * der weichen Ziele, dann früher Dienstbeginn (Früh vor Spät vor Nacht),
     * dann größter Restbedarf des Slots.
     *
     * @param  array<int, array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}>  $slots
     * @return array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}|null
     */
    private function bestTopUpSlot(PlanningContext $context, User $employee, array $slots): ?array
    {
        $best = null;
        $bestScore = null;

        foreach ($slots as $slot) {
            $target = $slot['rule']->target_total_staff ?? $slot['rule']->required_total_staff;
            $current = $context->slotCategoryTotal($slot['date'], $slot['category']);

            if ($current >= $target) {
                continue; // Ideal-Kategoriebesetzung erreicht – kein weiterer Bedarf.
            }

            foreach ($slot['templates'] as $entry) {
                $failed = $this->hardConstraints->firstFailedConstraint(
                    $context,
                    $employee,
                    $entry['template'],
                    $slot['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
                    needSpecialist: false,
                );

                if ($failed !== null) {
                    continue;
                }

                // Leichtes Über-Soll ist erlaubt (wird als Überstunden verbucht);
                // die Aufstockung bevorzugt aber Dienste, die noch ins Soll passen.
                $shiftMinutes = (int) $entry['startsAt']->diffInMinutes($entry['endsAt'], true);
                $wouldExceed = $context->plannedMinutesFor($employee) + $shiftMinutes
                    > $context->targetMinutesFor($employee);

                if ($wouldExceed) {
                    continue;
                }

                $delta = $this->evaluator->deltaForAdd(
                    $context,
                    $employee,
                    $entry['template'],
                    $slot['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
                );

                $score = [
                    $delta,
                    (string) $entry['template']->starts_at,
                    -($target - $current),
                    $context->slotTotalStaff($slot['date'], $entry['template']),
                ];

                if ($bestScore === null || $score < $bestScore) {
                    $bestScore = $score;
                    $best = ['slot' => $slot, 'entry' => $entry];
                }
            }
        }

        return $best;
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function currentSlotCount(PlanningContext $context, array $slot, bool $needSpecialist): int
    {
        return $needSpecialist
            ? $context->slotCategorySpecialistCount($slot['date'], $slot['category'])
            : $context->slotCategoryTotal($slot['date'], $slot['category']);
    }

    /**
     * Wählt unter allen regelkonformen Kandidaten den mit der geringsten
     * Strafänderung der weichen Ziele; bei Gleichstand entscheidet die
     * Fairness-Sortierung (Auslastung, Minuten, Dienste, Name).
     *
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function bestCandidate(PlanningContext $context, array $slot, bool $needSpecialist): ?array
    {
        $pick = $this->bestCandidateWithConstraints($context, $slot, $needSpecialist, relaxWeekendLimit: false);

        if ($pick !== null || ! (bool) config('rostering.relax_weekend_limit_for_coverage')) {
            return $pick;
        }

        // Bliebe der Bedarf sonst unbesetzt, darf als einzige Regel das
        // Wochenend-Limit weichen (Besetzung schlaegt Empfehlung).
        return $this->bestCandidateWithConstraints($context, $slot, $needSpecialist, relaxWeekendLimit: true);
    }

    /**
     * Wählt das beste Paar (Mitarbeiter, Schicht innerhalb der Kategorie): primär
     * der Mitarbeiter mit der geringsten Strafänderung (bei Gleichstand fair
     * nach Auslastung dank sortedCandidates), für ihn die Schicht mit der
     * geringsten Strafe und – als Ausgleich – mit den wenigsten bereits
     * eingeplanten Personen, damit sich die Leute über Früh1/Früh2 verteilen.
     *
     * @return array{employee: User, entry: array{template: ShiftTemplate, startsAt: CarbonImmutable, endsAt: CarbonImmutable}}|null
     */
    private function bestCandidateWithConstraints(
        PlanningContext $context,
        array $slot,
        bool $needSpecialist,
        bool $relaxWeekendLimit,
    ): ?array {
        // Schichten der Kategorie ausbalancieren: zuerst die mit den wenigsten
        // Personen besetzen (so landen z. B. Früh 1 und Früh 2 je 1 Person, statt
        // alle in einer). Für die gewählte Schicht den fairsten Kandidaten nehmen.
        $entries = $slot['templates'];

        usort($entries, fn (array $a, array $b): int => [
            $context->slotTotalStaff($slot['date'], $a['template']),
            (string) $a['template']->starts_at,
        ] <=> [
            $context->slotTotalStaff($slot['date'], $b['template']),
            (string) $b['template']->starts_at,
        ]);

        foreach ($entries as $entry) {
            $best = null;
            $bestDelta = null;

            foreach ($this->sortedCandidates($context) as $employee) {
                $failedConstraint = $this->hardConstraints->firstFailedConstraint(
                    $context,
                    $employee,
                    $entry['template'],
                    $slot['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
                    $needSpecialist,
                    $relaxWeekendLimit,
                );

                if ($failedConstraint !== null) {
                    continue;
                }

                $delta = $this->evaluator->deltaForAdd(
                    $context,
                    $employee,
                    $entry['template'],
                    $slot['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
                );

                // Strikt <, damit bei gleicher Strafe der fairere (zuerst sortierte) bleibt.
                if ($bestDelta === null || $delta < $bestDelta) {
                    $bestDelta = $delta;
                    $best = ['employee' => $employee, 'entry' => $entry];
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return null;
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
            // Über alle Schichten der Kategorie das jeweils erste Hindernis sammeln;
            // passt eine Schicht, ist der Mitarbeiter grundsätzlich einsetzbar.
            $failedForAll = null;

            foreach ($slot['templates'] as $entry) {
                $failedConstraint = $this->hardConstraints->firstFailedConstraint(
                    $context,
                    $employee,
                    $entry['template'],
                    $slot['date'],
                    $entry['startsAt'],
                    $entry['endsAt'],
                    $needSpecialist,
                );

                if ($failedConstraint === null) {
                    $failedForAll = null;

                    break;
                }

                $failedForAll = $failedConstraint;
            }

            if ($failedForAll !== null) {
                $rejections[$failedForAll] = ($rejections[$failedForAll] ?? 0) + 1;
            }
        }

        $result->addSkipped('no_candidate', 'Es wurde kein geeigneter Mitarbeiter gefunden.', [
            'date' => $slot['date']->toDateString(),
            'category' => $slot['category'],
            'shiftTemplateCode' => $slot['category'],
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
