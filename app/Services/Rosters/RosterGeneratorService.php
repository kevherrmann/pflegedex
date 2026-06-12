<?php

namespace App\Services\Rosters;

use App\Enums\ShiftSource;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterGeneratorService
{
    public function __construct(private readonly RosterDateService $rosterDateService) {}

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

            foreach ($this->plannableSlots($roster, $context, $result) as $slot) {
                $this->fillMissingSpecialists($context, $roster, $slot, $result);
                $this->fillMissingStaff($context, $roster, $slot, $result);
            }

            return $result;
        });
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
     * Baut die zu planenden Slots auf und ordnet sie nach Schwierigkeit:
     * Urlaubssperren-Tage (hoher Bedarf) zuerst, dann Schichten mit dem
     * kleinsten Kandidatenkreis, damit knappe Besetzungen (z. B. Nachtdienst)
     * nicht von leicht besetzbaren Slots leergeplant werden.
     *
     * @return array<int, array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}>
     */
    private function plannableSlots(Roster $roster, PlanningContext $context, RosterGenerationResult $result): array
    {
        $capablePoolSizes = [];

        foreach ($context->shiftTemplates as $shiftTemplate) {
            $capablePoolSizes[$shiftTemplate->id] = $context->employees
                ->filter(fn (User $employee): bool => $this->employeeCanWorkShiftTemplate($employee, $shiftTemplate))
                ->count();
        }

        $slots = [];

        foreach ($this->rosterDateService->datesForRosterMonth($roster) as $date) {
            foreach ($context->shiftTemplates as $shiftTemplate) {
                $staffingRule = $this->findStaffingRule($shiftTemplate, $date);

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
                $capablePoolSizes[$first['template']->id],
                $first['date']->toDateString(),
                (string) $first['template']->starts_at,
                $first['template']->id,
            ] <=> [
                $context->isBlackoutDate($second['date']) ? 0 : 1,
                $capablePoolSizes[$second['template']->id],
                $second['date']->toDateString(),
                (string) $second['template']->starts_at,
                $second['template']->id,
            ];
        });

        return $slots;
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function fillMissingSpecialists(
        PlanningContext $context,
        Roster $roster,
        array $slot,
        RosterGenerationResult $result,
    ): void {
        while ($context->slotSpecialistCount($slot['date'], $slot['template']) < $slot['rule']->required_specialists) {
            $candidate = $this->nextCandidate($context, $slot, needSpecialist: true, result: $result);

            if ($candidate === null) {
                return;
            }

            $this->createAutoShift($context, $roster, $candidate, $slot, $result);
        }
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function fillMissingStaff(
        PlanningContext $context,
        Roster $roster,
        array $slot,
        RosterGenerationResult $result,
    ): void {
        while ($context->slotTotalStaff($slot['date'], $slot['template']) < $slot['rule']->required_total_staff) {
            $candidate = $this->nextCandidate($context, $slot, needSpecialist: false, result: $result);

            if ($candidate === null) {
                return;
            }

            $this->createAutoShift($context, $roster, $candidate, $slot, $result);
        }
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function nextCandidate(
        PlanningContext $context,
        array $slot,
        bool $needSpecialist,
        RosterGenerationResult $result,
    ): ?User {
        $rejections = [];

        foreach ($this->sortedCandidates($context) as $employee) {
            $failedConstraint = $this->firstFailedConstraint($context, $employee, $slot, $needSpecialist);

            if ($failedConstraint === null) {
                return $employee;
            }

            $rejections[$failedConstraint] = ($rejections[$failedConstraint] ?? 0) + 1;
        }

        $result->addSkipped('no_candidate', 'Es wurde kein geeigneter Mitarbeiter gefunden.', [
            'date' => $slot['date']->toDateString(),
            'shiftTemplateId' => $slot['template']->id,
            'shiftTemplateCode' => $slot['template']->code,
            'needSpecialist' => $needSpecialist,
            'reason' => $needSpecialist ? 'no_available_specialist' : 'no_available_employee',
            'rejections' => $rejections,
        ]);

        return null;
    }

    /**
     * Liefert den Code der ersten verletzten Regel oder null, wenn der
     * Mitarbeiter den Dienst übernehmen kann.
     *
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function firstFailedConstraint(
        PlanningContext $context,
        User $employee,
        array $slot,
        bool $needSpecialist,
    ): ?string {
        if ($needSpecialist && ! ($employee->employeeProfile?->is_nursing_specialist ?? false)) {
            return 'not_specialist';
        }

        if (! $this->employeeCanWorkShiftTemplate($employee, $slot['template'])) {
            return 'shift_capability';
        }

        if ($context->isAlreadyAssigned($employee, $slot['template'], $slot['date'])) {
            return 'already_assigned';
        }

        if ($context->hasApprovedAbsenceOverlap($employee, $slot['startsAt'], $slot['endsAt'])) {
            return 'absence';
        }

        if ($context->hasRestConflict($employee, $slot['startsAt'], $slot['endsAt'])) {
            return 'rest_period';
        }

        if ($context->wouldExceedConsecutiveWorkDays($employee, $slot['date'])) {
            return 'consecutive_days';
        }

        if ($context->wouldExceedWeekendLoad($employee, $slot['date'])) {
            return 'weekend_limit';
        }

        $shiftMinutes = (int) $slot['startsAt']->diffInMinutes($slot['endsAt'], true);

        if ($context->wouldExceedWeeklyMaxMinutes($employee, $slot['date'], $shiftMinutes)) {
            return 'weekly_hours_cap';
        }

        return null;
    }

    /**
     * @param  array{date: CarbonImmutable, template: ShiftTemplate, rule: ShiftStaffingRule, startsAt: CarbonImmutable, endsAt: CarbonImmutable}  $slot
     */
    private function createAutoShift(
        PlanningContext $context,
        Roster $roster,
        User $employee,
        array $slot,
        RosterGenerationResult $result,
    ): Shift {
        $shift = Shift::query()->create([
            'roster_id' => $roster->id,
            'location_id' => $roster->location_id,
            'user_id' => $employee->id,
            'shift_template_id' => $slot['template']->id,
            'date' => $slot['date']->toDateString(),
            'starts_at' => $slot['startsAt'],
            'ends_at' => $slot['endsAt'],
            'source' => ShiftSource::Auto,
            'note' => null,
        ]);

        $context->addAssignment($employee, $slot['template'], $slot['date'], $slot['startsAt'], $slot['endsAt']);
        $result->addCreatedShift();

        if ($context->hasRequestedAbsenceOverlap($employee, $slot['startsAt'], $slot['endsAt'])) {
            $result->addWarning(
                'pending_absence_overlap',
                'Der Mitarbeiter hat eine noch offene Abwesenheitsanfrage an diesem Tag.',
                [
                    'date' => $slot['date']->toDateString(),
                    'shiftTemplateId' => $slot['template']->id,
                    'shiftTemplateCode' => $slot['template']->code,
                    'userId' => $employee->id,
                    'employeeName' => $employee->name,
                ],
            );
        }

        return $shift;
    }

    private function findStaffingRule(ShiftTemplate $shiftTemplate, CarbonImmutable $date): ?ShiftStaffingRule
    {
        $weekday = $date->dayOfWeekIso;

        return $shiftTemplate->staffingRules
            ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === $weekday)
            ?? $shiftTemplate->staffingRules
                ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === null);
    }

    private function employeeCanWorkShiftTemplate(User $employee, ShiftTemplate $shiftTemplate): bool
    {
        return match ($shiftTemplate->code) {
            'early' => $employee->employeeProfile?->can_work_early ?? false,
            'late' => $employee->employeeProfile?->can_work_late ?? false,
            'night' => $employee->employeeProfile?->can_work_night ?? false,
            default => true,
        };
    }

    /**
     * Fairness-Sortierung: am wenigsten ausgelastete Mitarbeiter zuerst.
     *
     * @return Collection<int, User>
     */
    private function sortedCandidates(PlanningContext $context): Collection
    {
        return $context->employees
            ->sortBy([
                fn (User $employee): int => $context->utilizationPermilleFor($employee),
                fn (User $employee): int => $context->plannedMinutesFor($employee),
                fn (User $employee): int => $context->shiftCountFor($employee),
                fn (User $employee): string => $employee->name,
            ])
            ->values();
    }
}
