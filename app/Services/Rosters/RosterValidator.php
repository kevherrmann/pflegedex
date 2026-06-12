<?php

namespace App\Services\Rosters;

use App\Enums\AbsenceRequestStatus;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class RosterValidator
{
    public function __construct(private readonly RosterDateService $rosterDateService) {}

    private const REQUIRED_REST_MINUTES = 660;

    private const PLANNED_WORKING_HOURS_TOLERANCE_MINUTES = 60;

    private const MAX_ALLOWED_CONSECUTIVE_WORK_DAYS = 6;

    private const MAX_ALLOWED_WEEKENDS = 2;

    private const DEFAULT_SHIFT_MINUTES = 480;

    // Unter diesem Anteil der Soll-Kapazitaet gilt ein Mitarbeiter als deutlich unter Soll geplant.
    private const UNDER_PLANNED_FACTOR = 0.5;

    public function validate(Roster $roster): RosterValidationResult
    {
        $result = new RosterValidationResult;

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
        $this->validatePlannedWorkingHours($roster, $shiftTemplates, $result);
        $this->validateConsecutiveWorkDays($roster, $result);
        $this->validateWeekendLoad($roster, $result);
        $this->validateMonthlyFreeDays($roster, $result);
        $this->validateSundayCompensationRestDays($roster, $result);
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
        foreach ($this->rosterDateService->datesForRosterMonth($roster) as $date) {
            foreach ($shiftTemplates as $shiftTemplate) {
                $staffingRule = $this->findStaffingRule($shiftTemplate, $date);

                if ($staffingRule === null) {
                    $result->addWarning(
                        'missing_staffing_rule',
                        'Für diese Schicht ist keine Mindestbesetzung hinterlegt.',
                        [
                            'date' => $date->toDateString(),
                            'shiftTemplateId' => $shiftTemplate->id,
                            'shiftTemplateName' => $shiftTemplate->name,
                            'shiftTemplateCode' => $shiftTemplate->code,
                        ],
                        'Mindestbesetzung fehlt',
                        'Für diese aktive Schichtvorlage ist keine Mindestbesetzung hinterlegt.',
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
                            'shiftTemplateName' => $shiftTemplate->name,
                            'shiftTemplateCode' => $shiftTemplate->code,
                            'requiredTotalStaff' => $staffingRule->required_total_staff,
                            'actualTotalStaff' => $actualTotalStaff,
                        ],
                        'Mindestbesetzung unterschritten',
                        'Für diese Schicht sind weniger Mitarbeiter eingeplant als in der Mindestbesetzung hinterlegt.',
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
                            'shiftTemplateName' => $shiftTemplate->name,
                            'shiftTemplateCode' => $shiftTemplate->code,
                            'requiredSpecialists' => $staffingRule->required_specialists,
                            'actualSpecialists' => $actualSpecialists,
                        ],
                        'Fachkraft fehlt',
                        'Für diese Schicht sind weniger Fachkräfte eingeplant als erforderlich.',
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
                                'employeeName' => $previousShift->user?->name,
                                'previousShiftId' => $previousShift->id,
                                'previousShiftDate' => $previousShift->date->toDateString(),
                                'previousShiftTemplateName' => $previousShift->shiftTemplate?->name,
                                'previousShiftEndsAt' => $previousShift->ends_at->toDateTimeString(),
                                'nextShiftId' => $nextShift->id,
                                'nextShiftDate' => $nextShift->date->toDateString(),
                                'nextShiftTemplateName' => $nextShift->shiftTemplate?->name,
                                'nextShiftStartsAt' => $nextShift->starts_at->toDateTimeString(),
                                'restMinutes' => $restMinutes,
                                'requiredRestMinutes' => self::REQUIRED_REST_MINUTES,
                            ],
                            'Ruhezeit unterschritten',
                            'Zwischen zwei Diensten liegt weniger als die erforderliche Ruhezeit.',
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
                            $shifts->first()?->user?->name,
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
                    $shifts->first()?->user?->name,
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
        ?string $employeeName,
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
                'employeeName' => $employeeName,
                'consecutiveDays' => $consecutiveDays,
                'maxAllowedConsecutiveDays' => self::MAX_ALLOWED_CONSECUTIVE_WORK_DAYS,
                'startsOn' => $startsOn->toDateString(),
                'endsOn' => $endsOn->toDateString(),
                'month' => $roster->month,
                'year' => $roster->year,
            ],
            'Zu viele Arbeitstage am Stück',
            'Der Mitarbeiter ist länger als empfohlen ohne freien Tag eingeplant.',
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
                        'employeeName' => $shifts->first()?->user?->name,
                        'workedWeekends' => $weekendStartsOn->count(),
                        'maxAllowedWeekends' => self::MAX_ALLOWED_WEEKENDS,
                        'weekendStartsOn' => $weekendStartsOn->all(),
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                    'Zu viele Wochenenden geplant',
                    'Der Mitarbeiter ist an mehr Wochenenden eingeplant als empfohlen.',
                );
            });
    }

    private function validateMonthlyFreeDays(Roster $roster, RosterValidationResult $result): void
    {
        $monthDates = collect($this->rosterDateService->datesForRosterMonth($roster))
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();
        $daysInMonth = $monthDates->count();

        $roster->shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($monthDates, $daysInMonth, $roster, $result): void {
                $workedDays = $shifts
                    ->map(fn (Shift $shift): string => $shift->date->toDateString())
                    ->unique()
                    ->intersect($monthDates)
                    ->count();

                if ($workedDays < $daysInMonth) {
                    return;
                }

                $result->addWarning(
                    'employee_has_no_free_day_in_month',
                    'Der Mitarbeiter hat im Dienstplanmonat keinen freien Tag.',
                    [
                        'userId' => $userId,
                        'employeeName' => $shifts->first()?->user?->name,
                        'workedDays' => $workedDays,
                        'daysInMonth' => $daysInMonth,
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                    'Kein freier Tag im Monat',
                    'Der Mitarbeiter hat im Dienstplanmonat keinen freien Kalendertag.',
                );
            });
    }

    private function validateSundayCompensationRestDays(Roster $roster, RosterValidationResult $result): void
    {
        $monthDates = collect($this->rosterDateService->datesForRosterMonth($roster))
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        $roster->shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($monthDates, $roster, $result): void {
                $workedDays = $shifts
                    ->map(fn (Shift $shift): string => $shift->date->toDateString())
                    ->unique()
                    ->values();
                $workedSundays = $workedDays
                    ->filter(fn (string $date): bool => CarbonImmutable::parse($date)->dayOfWeekIso === 7)
                    ->values();

                foreach ($workedSundays as $sunday) {
                    $sundayDate = CarbonImmutable::parse($sunday)->startOfDay();
                    $windowStartsOn = $sundayDate->subDays(6);
                    $windowEndsOn = $sundayDate->addDays(7);

                    $knownWindowDates = $monthDates
                        ->filter(fn (string $date): bool => $date >= $windowStartsOn->toDateString()
                            && $date <= $windowEndsOn->toDateString())
                        ->values();
                    $hasKnownFreeDay = $knownWindowDates
                        ->contains(fn (string $date): bool => ! $workedDays->contains($date));

                    if ($hasKnownFreeDay) {
                        continue;
                    }

                    $result->addWarning(
                        'missing_sunday_compensation_rest_day',
                        'Für Sonntagsarbeit fehlt ein Ersatzruhetag im Ausgleichszeitraum.',
                        [
                            'userId' => $userId,
                            'employeeName' => $shifts->first()?->user?->name,
                            'sunday' => $sundayDate->toDateString(),
                            'compensationWindowStartsOn' => $windowStartsOn->toDateString(),
                            'compensationWindowEndsOn' => $windowEndsOn->toDateString(),
                            'month' => $roster->month,
                            'year' => $roster->year,
                        ],
                        'Ersatzruhetag für Sonntag fehlt',
                        'Für Sonntagsarbeit wurde im bekannten Ausgleichszeitraum kein freier Kalendertag gefunden.',
                    );
                }
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
                    'employeeName' => $shift->user?->name,
                    'shiftId' => $shift->id,
                    'shiftTemplateName' => $shift->shiftTemplate?->name,
                    'date' => $shift->date->toDateString(),
                    'absenceRequestId' => $absenceRequest->id,
                    'absenceType' => $absenceRequest->type->value,
                    'absenceStartsOn' => $absenceRequest->starts_on->toDateString(),
                    'absenceEndsOn' => $absenceRequest->ends_on->toDateString(),
                ],
                'Mitarbeiter ist abwesend',
                'Der Mitarbeiter hat im Zeitraum dieses Dienstes eine genehmigte Abwesenheit.',
            );
        }
    }

    /**
     * @param  EloquentCollection<int, ShiftTemplate>  $shiftTemplates
     */
    private function validatePlannedWorkingHours(
        Roster $roster,
        EloquentCollection $shiftTemplates,
        RosterValidationResult $result,
    ): void {
        $daysInMonth = CarbonImmutable::create($roster->year, $roster->month, 1)->daysInMonth;
        $weeksFactor = $daysInMonth / 7;

        $shiftMinutes = (int) round((float) ($shiftTemplates->avg('duration_minutes') ?? 0));
        $shiftMinutes = $shiftMinutes > 0 ? $shiftMinutes : self::DEFAULT_SHIFT_MINUTES;

        // Alle berechtigten Mitarbeiter betrachten, damit auch gar nicht oder
        // kaum eingeplante Personen erkannt werden (nicht nur die mit Diensten).
        $eligibleEmployees = User::query()
            ->with('employeeProfile')
            ->where('location_id', $roster->location_id)
            ->whereHas('employeeProfile', fn ($query) => $query
                ->where('active', true)
                ->where('employment_area', EmploymentArea::Nursing->value))
            ->get();

        $plannedByUser = $roster->shifts
            ->groupBy('user_id')
            ->map(fn (Collection $shifts): int => (int) $shifts->sum(
                fn (Shift $shift): int => (int) $shift->starts_at->diffInMinutes($shift->ends_at),
            ));

        foreach ($eligibleEmployees as $employee) {
            $targetMinutes = $this->targetMinutesForEmployee($employee, $weeksFactor, $shiftMinutes);

            if ($targetMinutes <= 0) {
                continue;
            }

            $plannedMinutes = (int) ($plannedByUser->get($employee->id) ?? 0);

            if ($plannedMinutes > $targetMinutes + self::PLANNED_WORKING_HOURS_TOLERANCE_MINUTES) {
                $result->addWarning(
                    'employee_over_planned_hours',
                    'Der Mitarbeiter ist über seiner Soll-Arbeitszeit geplant.',
                    [
                        'userId' => $employee->id,
                        'employeeName' => $employee->name,
                        'plannedMinutes' => $plannedMinutes,
                        'targetMinutes' => $targetMinutes,
                        'overtimeMinutes' => $plannedMinutes - $targetMinutes,
                        'weeklyHours' => (float) ($employee->employeeProfile?->weekly_hours ?? 0),
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                    'Soll-Arbeitszeit überschritten',
                    'Der Mitarbeiter ist über seiner monatlichen Soll-Arbeitszeit geplant.',
                );

                continue;
            }

            if ($plannedMinutes < (int) round($targetMinutes * self::UNDER_PLANNED_FACTOR)) {
                $result->addWarning(
                    'employee_under_planned_hours',
                    'Der Mitarbeiter ist deutlich unter seiner Soll-Arbeitszeit geplant.',
                    [
                        'userId' => $employee->id,
                        'employeeName' => $employee->name,
                        'plannedMinutes' => $plannedMinutes,
                        'targetMinutes' => $targetMinutes,
                        'missingMinutes' => $targetMinutes - $plannedMinutes,
                        'weeklyHours' => (float) ($employee->employeeProfile?->weekly_hours ?? 0),
                        'regularWorkDaysPerWeek' => $employee->employeeProfile?->regular_work_days_per_week,
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                    'Deutlich unter Soll-Arbeitszeit',
                    'Der Mitarbeiter ist deutlich unter seiner monatlichen Soll-Arbeitszeit (Wochenstunden bzw. Regel-Arbeitstage) geplant.',
                );
            }
        }
    }

    /**
     * Monatliche Soll-Kapazitaet in Minuten = das Strengere aus Wochenstunden
     * und Regel-Arbeitstagen (Schichten sind feste Bloecke).
     */
    private function targetMinutesForEmployee(User $employee, float $weeksFactor, int $shiftMinutes): int
    {
        $profile = $employee->employeeProfile;

        $targets = [];

        $weeklyHours = (float) ($profile?->weekly_hours ?? 0);
        if ($weeklyHours > 0) {
            $targets[] = (int) round($weeklyHours * 60 * $weeksFactor);
        }

        $regularDays = (int) ($profile?->regular_work_days_per_week ?? 0);
        if ($regularDays > 0 && $shiftMinutes > 0) {
            $targets[] = (int) round($regularDays * $shiftMinutes * $weeksFactor);
        }

        return $targets === [] ? 0 : min($targets);
    }
}
