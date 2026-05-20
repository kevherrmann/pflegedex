<?php

namespace App\Services\Rosters;

use App\Enums\AbsenceRequestStatus;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class RosterValidator
{
    private const REQUIRED_REST_MINUTES = 660;
    private const PLANNED_WORKING_HOURS_TOLERANCE_MINUTES = 60;
    private const MAX_ALLOWED_CONSECUTIVE_WORK_DAYS = 6;
    private const MAX_ALLOWED_WEEKENDS = 2;

    public function validate(Roster $roster): RosterValidationResult
    {
        $result = new RosterValidationResult();

        $roster->loadMissing([
            'location',
            'shifts.user.employeeProfile',
            'shifts.shiftTemplate',
        ]);

        $shiftTemplates = ShiftTemplate::query()
            ->with('staffingRules')
            ->where('location_id', $roster->location_id)
            ->where('active', true)
            ->orderBy('starts_at')
            ->get();

        $this->validateStaffing($roster, $shiftTemplates, $result);
        $this->validateAbsenceConflicts($roster, $result);
        $this->validatePlannedWorkingHours($roster, $result);
        $this->validateConsecutiveWorkDays($roster, $result);
        $this->validateWeekendLoad($roster, $result);
        $this->validateRestPeriods($roster, $result);

        return $result;
    }

    /**
     * @param  EloquentCollection<int, ShiftTemplate>  $shiftTemplates
     */
    private function validateStaffing(
        Roster $roster,
        EloquentCollection $shiftTemplates,
        RosterValidationResult $result,
    ): void {
        foreach ($this->datesForRosterMonth($roster) as $date) {
            foreach ($shiftTemplates as $shiftTemplate) {
                $staffingRule = $this->findStaffingRule($shiftTemplate, $date);

                if ($staffingRule === null) {
                    $result->addWarning(
                        'missing_staffing_rule',
                        'Für diese Schicht ist keine Mindestbesetzung hinterlegt.',
                        [
                            'date' => $date->toDateString(),
                            'shiftTemplateId' => $shiftTemplate->id,
                            'shiftTemplateCode' => $shiftTemplate->code,
                        ],
                    );

                    continue;
                }

                $shifts = $this->shiftsForDateAndTemplate($roster, $date, $shiftTemplate);
                $actualTotalStaff = $shifts->count();

                if ($actualTotalStaff < $staffingRule->required_total_staff) {
                    $result->addError(
                        'understaffed_shift',
                        'Die Mindestbesetzung für diese Schicht ist unterschritten.',
                        [
                            'date' => $date->toDateString(),
                            'shiftTemplateId' => $shiftTemplate->id,
                            'shiftTemplateCode' => $shiftTemplate->code,
                            'requiredTotalStaff' => $staffingRule->required_total_staff,
                            'actualTotalStaff' => $actualTotalStaff,
                        ],
                    );
                }

                $actualSpecialists = $shifts
                    ->filter(fn (Shift $shift): bool => (bool) $shift->user?->employeeProfile?->active
                        && (bool) $shift->user?->employeeProfile?->is_nursing_specialist)
                    ->count();

                if ($actualSpecialists < $staffingRule->required_specialists) {
                    $result->addError(
                        'missing_specialist',
                        'Die Mindestanzahl an Fachkräften für diese Schicht ist unterschritten.',
                        [
                            'date' => $date->toDateString(),
                            'shiftTemplateId' => $shiftTemplate->id,
                            'shiftTemplateCode' => $shiftTemplate->code,
                            'requiredSpecialists' => $staffingRule->required_specialists,
                            'actualSpecialists' => $actualSpecialists,
                        ],
                    );
                }
            }
        }
    }

    private function findStaffingRule(ShiftTemplate $shiftTemplate, CarbonImmutable $date): ?ShiftStaffingRule
    {
        // ISO weekday: 1 = Montag, 7 = Sonntag.
        $weekday = $date->dayOfWeekIso;

        return $shiftTemplate->staffingRules
            ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === $weekday)
            ?? $shiftTemplate->staffingRules
                ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === null);
    }

    /**
     * @return Collection<int, Shift>
     */
    private function shiftsForDateAndTemplate(
        Roster $roster,
        CarbonImmutable $date,
        ShiftTemplate $shiftTemplate,
    ): Collection {
        return $roster->shifts
            ->filter(fn (Shift $shift): bool => $shift->date->toDateString() === $date->toDateString()
                && $shift->shift_template_id === $shiftTemplate->id)
            ->values();
    }

    private function validateRestPeriods(Roster $roster, RosterValidationResult $result): void
    {
        $roster->shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($result): void {
                $orderedShifts = $shifts
                    ->sortBy(fn (Shift $shift): int => $shift->starts_at->getTimestamp())
                    ->values();

                for ($index = 1; $index < $orderedShifts->count(); $index++) {
                    /** @var Shift $previousShift */
                    $previousShift = $orderedShifts[$index - 1];
                    /** @var Shift $nextShift */
                    $nextShift = $orderedShifts[$index];
                    $restMinutes = (int) $previousShift->ends_at->diffInMinutes($nextShift->starts_at);

                    if ($restMinutes < self::REQUIRED_REST_MINUTES) {
                        $result->addError(
                            'rest_period_violation',
                            'Die Ruhezeit zwischen zwei Diensten ist zu kurz.',
                            [
                                'userId' => $userId,
                                'previousShiftId' => $previousShift->id,
                                'nextShiftId' => $nextShift->id,
                                'restMinutes' => $restMinutes,
                                'requiredRestMinutes' => self::REQUIRED_REST_MINUTES,
                            ],
                        );
                    }
                }
            });
    }

    private function validateConsecutiveWorkDays(Roster $roster, RosterValidationResult $result): void
    {
        $roster->shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($roster, $result): void {
                $workDates = $shifts
                    ->map(fn (Shift $shift): string => $shift->date->toDateString())
                    ->unique()
                    ->sort()
                    ->values();

                if ($workDates->isEmpty()) {
                    return;
                }

                $sequenceStart = CarbonImmutable::parse($workDates->first())->startOfDay();
                $previousDate = $sequenceStart;
                $consecutiveDays = 1;

                for ($index = 1; $index < $workDates->count(); $index++) {
                    $currentDate = CarbonImmutable::parse($workDates[$index])->startOfDay();

                    if ($currentDate->isSameDay($previousDate->addDay())) {
                        $consecutiveDays++;
                    } else {
                        $this->addConsecutiveWorkDaysWarning(
                            $result,
                            $userId,
                            $roster,
                            $sequenceStart,
                            $previousDate,
                            $consecutiveDays,
                        );

                        $sequenceStart = $currentDate;
                        $consecutiveDays = 1;
                    }

                    $previousDate = $currentDate;
                }

                $this->addConsecutiveWorkDaysWarning(
                    $result,
                    $userId,
                    $roster,
                    $sequenceStart,
                    $previousDate,
                    $consecutiveDays,
                );
            });
    }

    private function addConsecutiveWorkDaysWarning(
        RosterValidationResult $result,
        string $userId,
        Roster $roster,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        int $consecutiveDays,
    ): void {
        if ($consecutiveDays <= self::MAX_ALLOWED_CONSECUTIVE_WORK_DAYS) {
            return;
        }

        $result->addWarning(
            'employee_too_many_consecutive_work_days',
            'Der Mitarbeiter ist an zu vielen Tagen am Stück eingeplant.',
            [
                'userId' => $userId,
                'consecutiveDays' => $consecutiveDays,
                'maxAllowedConsecutiveDays' => self::MAX_ALLOWED_CONSECUTIVE_WORK_DAYS,
                'startsOn' => $startsOn->toDateString(),
                'endsOn' => $endsOn->toDateString(),
                'month' => $roster->month,
                'year' => $roster->year,
            ],
        );
    }

    private function validateWeekendLoad(Roster $roster, RosterValidationResult $result): void
    {
        $roster->shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($roster, $result): void {
                $weekendStartsOn = $shifts
                    ->map(function (Shift $shift): ?string {
                        $date = CarbonImmutable::parse($shift->date)->startOfDay();

                        if ($date->dayOfWeekIso === 6) {
                            return $date->toDateString();
                        }

                        if ($date->dayOfWeekIso === 7) {
                            return $date->subDay()->toDateString();
                        }

                        return null;
                    })
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                if ($weekendStartsOn->count() <= self::MAX_ALLOWED_WEEKENDS) {
                    return;
                }

                $result->addWarning(
                    'employee_too_many_weekends',
                    'Der Mitarbeiter ist an zu vielen Wochenenden eingeplant.',
                    [
                        'userId' => $userId,
                        'workedWeekends' => $weekendStartsOn->count(),
                        'maxAllowedWeekends' => self::MAX_ALLOWED_WEEKENDS,
                        'weekendStartsOn' => $weekendStartsOn->all(),
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                );
            });
    }

    private function validateAbsenceConflicts(Roster $roster, RosterValidationResult $result): void
    {
        if ($roster->shifts->isEmpty()) {
            return;
        }

        $userIds = $roster->shifts
            ->pluck('user_id')
            ->unique()
            ->values();

        $shiftStartDate = $roster->shifts
            ->min(fn (Shift $shift): string => $shift->starts_at->toDateString());
        $shiftEndDate = $roster->shifts
            ->max(fn (Shift $shift): string => $shift->ends_at->toDateString());

        $absenceRequests = AbsenceRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', AbsenceRequestStatus::Approved->value)
            ->whereDate('starts_on', '<=', $shiftEndDate)
            ->whereDate('ends_on', '>=', $shiftStartDate)
            ->get()
            ->groupBy('user_id');

        foreach ($roster->shifts as $shift) {
            $absenceRequest = $absenceRequests
                ->get($shift->user_id, collect())
                ->first(fn (AbsenceRequest $absenceRequest): bool => $absenceRequest->starts_on->toDateString() <= $shift->ends_at->toDateString()
                    && $absenceRequest->ends_on->toDateString() >= $shift->starts_at->toDateString());

            if ($absenceRequest === null) {
                continue;
            }

            $result->addError(
                'employee_absent',
                'Der Mitarbeiter ist während dieser Schicht abwesend.',
                [
                    'userId' => $shift->user_id,
                    'shiftId' => $shift->id,
                    'date' => $shift->date->toDateString(),
                    'absenceRequestId' => $absenceRequest->id,
                    'absenceType' => $absenceRequest->type->value,
                    'absenceStartsOn' => $absenceRequest->starts_on->toDateString(),
                    'absenceEndsOn' => $absenceRequest->ends_on->toDateString(),
                ],
            );
        }
    }

    private function validatePlannedWorkingHours(Roster $roster, RosterValidationResult $result): void
    {
        $daysInMonth = CarbonImmutable::create($roster->year, $roster->month, 1)->daysInMonth;

        $roster->shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($daysInMonth, $roster, $result): void {
                /** @var Shift|null $firstShift */
                $firstShift = $shifts->first();
                $employeeProfile = $firstShift?->user?->employeeProfile;

                if ($employeeProfile === null || $employeeProfile->weekly_hours === null) {
                    return;
                }

                $plannedMinutes = (int) $shifts->sum(
                    fn (Shift $shift): int => (int) $shift->starts_at->diffInMinutes($shift->ends_at),
                );
                $weeklyHours = (float) $employeeProfile->weekly_hours;
                $targetMinutes = (int) round($weeklyHours * 60 / 7 * $daysInMonth);

                if ($plannedMinutes <= $targetMinutes + self::PLANNED_WORKING_HOURS_TOLERANCE_MINUTES) {
                    return;
                }

                $result->addWarning(
                    'employee_over_planned_hours',
                    'Der Mitarbeiter ist über seiner Soll-Arbeitszeit geplant.',
                    [
                        'userId' => $userId,
                        'plannedMinutes' => $plannedMinutes,
                        'targetMinutes' => $targetMinutes,
                        'overtimeMinutes' => $plannedMinutes - $targetMinutes,
                        'weeklyHours' => $weeklyHours,
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                );
            });
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function datesForRosterMonth(Roster $roster): array
    {
        $firstDay = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $days = [];

        for ($date = $firstDay; $date->month === $roster->month; $date = $date->addDay()) {
            $days[] = $date;
        }

        return $days;
    }
}
