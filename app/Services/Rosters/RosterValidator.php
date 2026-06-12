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
use App\Services\Rosters\Planning\TargetMinutesCalculator;
use App\Services\Rosters\Planning\WorkRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class RosterValidator
{
    private readonly int $requiredRestMinutes;

    private readonly int $maxConsecutiveWorkDays;

    private readonly int $maxWeekends;

    private readonly int $defaultShiftMinutes;

    private readonly int $plannedHoursToleranceMinutes;

    // Unter diesem Anteil der Soll-Kapazitaet gilt ein Mitarbeiter als deutlich unter Soll geplant.
    private readonly float $underPlannedFactor;

    public function __construct(
        private readonly RosterDateService $rosterDateService,
        private readonly TargetMinutesCalculator $targetMinutesCalculator = new TargetMinutesCalculator,
    ) {
        $this->requiredRestMinutes = (int) config('rostering.required_rest_minutes');
        $this->maxConsecutiveWorkDays = (int) config('rostering.max_consecutive_work_days');
        $this->maxWeekends = (int) config('rostering.max_weekends_per_month');
        $this->defaultShiftMinutes = (int) config('rostering.default_shift_minutes');
        $this->plannedHoursToleranceMinutes = (int) config('rostering.planned_hours_tolerance_minutes');
        $this->underPlannedFactor = (float) config('rostering.under_planned_factor');
    }

    /**
     * Validiert den Dienstplan. Über $shiftsOverride lassen sich noch nicht
     * persistierte Dienste prüfen (Vorschau der automatischen Planung);
     * die Modelle müssen user.employeeProfile und shiftTemplate geladen haben.
     *
     * @param  Collection<int, Shift>|null  $shiftsOverride
     */
    public function validate(Roster $roster, ?Collection $shiftsOverride = null): RosterValidationResult
    {
        $result = new RosterValidationResult;

        $roster->loadMissing(['location']);

        if ($shiftsOverride === null) {
            $roster->loadMissing([
                'shifts.user.employeeProfile',
                'shifts.shiftTemplate',
            ]);
        }

        $shifts = ($shiftsOverride ?? $roster->shifts)->toBase()->values();

        $shiftTemplates = ShiftTemplate::query()
            ->with('staffingRules')
            ->where('location_id', $roster->location_id)
            ->where('active', true)
            ->orderBy('starts_at')
            ->get();

        // Dienste derselben Mitarbeiter im Randfenster um den Monat (inklusive
        // anderer Dienstplaene/Standorte), damit Ruhezeiten und Folgetage nicht
        // an der Monatsgrenze abreissen.
        $boundaryShiftsByUser = $this->boundaryShiftsByUser($roster, $shifts);

        $this->validateStaffing($roster, $shifts, $shiftTemplates, $result);
        $this->validateAbsenceConflicts($roster, $shifts, $result);
        $this->validatePlannedWorkingHours($roster, $shifts, $shiftTemplates, $result);
        $this->validateConsecutiveWorkDays($roster, $shifts, $boundaryShiftsByUser, $result);
        $this->validateWeekendLoad($roster, $shifts, $boundaryShiftsByUser, $result);
        $this->validateMonthlyFreeDays($roster, $shifts, $boundaryShiftsByUser, $result);
        $this->validateSundayCompensationRestDays($roster, $shifts, $boundaryShiftsByUser, $result);
        $this->validateRestPeriods($roster, $shifts, $boundaryShiftsByUser, $result);

        return $result;
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return Collection<string, Collection<int, Shift>>
     */
    private function boundaryShiftsByUser(Roster $roster, Collection $shifts): Collection
    {
        if ($shifts->isEmpty()) {
            return collect();
        }

        $windowDays = (int) config('rostering.boundary_window_days');
        $monthStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        return Shift::query()
            ->with('shiftTemplate')
            ->whereIn('user_id', $shifts->pluck('user_id')->unique())
            ->where('roster_id', '!=', $roster->id)
            ->whereDate('date', '>=', $monthStart->subDays($windowDays)->toDateString())
            ->whereDate('date', '<=', $monthEnd->addDays($windowDays)->toDateString())
            ->get()
            ->groupBy('user_id');
    }

    /**
     * @param  EloquentCollection<int, ShiftTemplate>  $shiftTemplates
     */
    private function validateStaffing(
        Roster $roster,
        Collection $shifts,
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

                $slotShifts = $this->shiftsForDateAndTemplate($shifts, $date, $shiftTemplate);
                $actualTotalStaff = $slotShifts->count();

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

                $actualSpecialists = $slotShifts
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
        Collection $shifts,
        CarbonImmutable $date,
        ShiftTemplate $shiftTemplate,
    ): Collection {
        return $shifts
            ->filter(fn (Shift $shift): bool => $shift->date->toDateString() === $date->toDateString()
                && $shift->shift_template_id === $shiftTemplate->id)
            ->values();
    }

    /**
     * @param  Collection<string, Collection<int, Shift>>  $boundaryShiftsByUser
     */
    private function validateRestPeriods(Roster $roster, Collection $shifts, Collection $boundaryShiftsByUser, RosterValidationResult $result): void
    {
        $shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($roster, $boundaryShiftsByUser, $result): void {
                $orderedShifts = $shifts
                    ->concat($boundaryShiftsByUser->get($userId, collect()))
                    ->sortBy(fn (Shift $shift): int => $shift->starts_at->getTimestamp())
                    ->values();

                for ($index = 1; $index < $orderedShifts->count(); $index++) {
                    /** @var Shift $previousShift */
                    $previousShift = $orderedShifts[$index - 1];
                    /** @var Shift $nextShift */
                    $nextShift = $orderedShifts[$index];

                    // Konflikte rein zwischen fremden Dienstplaenen meldet deren eigener Plan.
                    if ($previousShift->roster_id !== $roster->id && $nextShift->roster_id !== $roster->id) {
                        continue;
                    }

                    $restMinutes = (int) $previousShift->ends_at->diffInMinutes($nextShift->starts_at);

                    if ($restMinutes < $this->requiredRestMinutes) {
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
                                'requiredRestMinutes' => $this->requiredRestMinutes,
                            ],
                            'Ruhezeit unterschritten',
                            'Zwischen zwei Diensten liegt weniger als die erforderliche Ruhezeit.',
                        );
                    }
                }
            });
    }

    /**
     * @param  Collection<string, Collection<int, Shift>>  $boundaryShiftsByUser
     */
    private function validateConsecutiveWorkDays(Roster $roster, Collection $shifts, Collection $boundaryShiftsByUser, RosterValidationResult $result): void
    {
        $shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($roster, $boundaryShiftsByUser, $result): void {
                $rosterWorkDates = $shifts
                    ->map(fn (Shift $shift): string => $shift->date->toDateString())
                    ->unique();

                $workDates = $rosterWorkDates
                    ->concat($boundaryShiftsByUser->get($userId, collect())
                        ->map(fn (Shift $shift): string => $shift->date->toDateString()))
                    ->unique()
                    ->sort()
                    ->values();

                if ($workDates->isEmpty()) {
                    return;
                }

                $sequenceStart = CarbonImmutable::parse($workDates->first())->startOfDay();
                $previousDate = $sequenceStart;
                $consecutiveDays = 1;
                $sequenceTouchesRoster = $rosterWorkDates->contains($workDates->first());

                for ($index = 1; $index < $workDates->count(); $index++) {
                    $currentDate = CarbonImmutable::parse($workDates[$index])->startOfDay();

                    if ($currentDate->isSameDay($previousDate->addDay())) {
                        $consecutiveDays++;
                    } else {
                        if ($sequenceTouchesRoster) {
                            $this->addConsecutiveWorkDaysWarning(
                                $result,
                                $userId,
                                $shifts->first()?->user?->name,
                                $roster,
                                $sequenceStart,
                                $previousDate,
                                $consecutiveDays,
                            );
                        }

                        $sequenceStart = $currentDate;
                        $consecutiveDays = 1;
                        $sequenceTouchesRoster = false;
                    }

                    $sequenceTouchesRoster = $sequenceTouchesRoster || $rosterWorkDates->contains($workDates[$index]);
                    $previousDate = $currentDate;
                }

                if ($sequenceTouchesRoster) {
                    $this->addConsecutiveWorkDaysWarning(
                        $result,
                        $userId,
                        $shifts->first()?->user?->name,
                        $roster,
                        $sequenceStart,
                        $previousDate,
                        $consecutiveDays,
                    );
                }
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
        if ($consecutiveDays <= $this->maxConsecutiveWorkDays) {
            return;
        }

        $result->addWarning(
            'employee_too_many_consecutive_work_days',
            'Der Mitarbeiter ist an zu vielen Tagen am Stück eingeplant.',
            [
                'userId' => $userId,
                'employeeName' => $employeeName,
                'consecutiveDays' => $consecutiveDays,
                'maxAllowedConsecutiveDays' => $this->maxConsecutiveWorkDays,
                'startsOn' => $startsOn->toDateString(),
                'endsOn' => $endsOn->toDateString(),
                'month' => $roster->month,
                'year' => $roster->year,
            ],
            'Zu viele Arbeitstage am Stück',
            'Der Mitarbeiter ist länger als empfohlen ohne freien Tag eingeplant.',
        );
    }

    /**
     * @param  Collection<string, Collection<int, Shift>>  $boundaryShiftsByUser
     */
    private function validateWeekendLoad(Roster $roster, Collection $shifts, Collection $boundaryShiftsByUser, RosterValidationResult $result): void
    {
        $monthStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();

        $shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($roster, $boundaryShiftsByUser, $monthStart, $monthEnd, $result): void {
                // Auch Wochenenddienste desselben Monats in anderen Dienstplaenen zaehlen mit.
                $weekendStartsOn = $shifts
                    ->concat($boundaryShiftsByUser->get($userId, collect())
                        ->filter(fn (Shift $shift): bool => CarbonImmutable::parse($shift->date)->startOfDay()->between($monthStart, $monthEnd)))
                    ->map(fn (Shift $shift): ?string => WorkRules::weekendStartKey(
                        CarbonImmutable::parse($shift->date)->startOfDay(),
                    ))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                if ($weekendStartsOn->count() <= $this->maxWeekends) {
                    return;
                }

                $result->addWarning(
                    'employee_too_many_weekends',
                    'Der Mitarbeiter ist an zu vielen Wochenenden eingeplant.',
                    [
                        'userId' => $userId,
                        'employeeName' => $shifts->first()?->user?->name,
                        'workedWeekends' => $weekendStartsOn->count(),
                        'maxAllowedWeekends' => $this->maxWeekends,
                        'weekendStartsOn' => $weekendStartsOn->all(),
                        'month' => $roster->month,
                        'year' => $roster->year,
                    ],
                    'Zu viele Wochenenden geplant',
                    'Der Mitarbeiter ist an mehr Wochenenden eingeplant als empfohlen.',
                );
            });
    }

    /**
     * @param  Collection<string, Collection<int, Shift>>  $boundaryShiftsByUser
     */
    private function validateMonthlyFreeDays(Roster $roster, Collection $shifts, Collection $boundaryShiftsByUser, RosterValidationResult $result): void
    {
        $monthDates = collect($this->rosterDateService->datesForRosterMonth($roster))
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();
        $daysInMonth = $monthDates->count();

        $shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($monthDates, $daysInMonth, $roster, $boundaryShiftsByUser, $result): void {
                // Auch Dienste desselben Monats in anderen Dienstplaenen belegen Tage.
                $workedDays = $shifts
                    ->concat($boundaryShiftsByUser->get($userId, collect()))
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

    /**
     * @param  Collection<string, Collection<int, Shift>>  $boundaryShiftsByUser
     */
    private function validateSundayCompensationRestDays(Roster $roster, Collection $shifts, Collection $boundaryShiftsByUser, RosterValidationResult $result): void
    {
        $monthDates = collect($this->rosterDateService->datesForRosterMonth($roster))
            ->map(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        $shifts
            ->groupBy('user_id')
            ->each(function (Collection $shifts, string $userId) use ($monthDates, $roster, $boundaryShiftsByUser, $result): void {
                // Belegte Tage inklusive Diensten in anderen Dienstplaenen im Randfenster,
                // damit ein vermeintlich freier Tag nicht anderswo verplant ist.
                $workedDays = $shifts
                    ->concat($boundaryShiftsByUser->get($userId, collect()))
                    ->map(fn (Shift $shift): string => $shift->date->toDateString())
                    ->unique()
                    ->values();
                // Ersatzruhetage werden nur fuer Sonntage gemeldet, die dieser Dienstplan verplant.
                $workedSundays = $shifts
                    ->map(fn (Shift $shift): string => $shift->date->toDateString())
                    ->unique()
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

    private function validateAbsenceConflicts(Roster $roster, Collection $shifts, RosterValidationResult $result): void
    {
        if ($shifts->isEmpty()) {
            return;
        }

        $userIds = $shifts
            ->pluck('user_id')
            ->unique()
            ->values();

        $shiftStartDate = $shifts
            ->min(fn (Shift $shift): string => $shift->starts_at->toDateString());
        $shiftEndDate = $shifts
            ->max(fn (Shift $shift): string => $shift->ends_at->toDateString());

        $absenceRequests = AbsenceRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', AbsenceRequestStatus::Approved->value)
            ->whereDate('starts_on', '<=', $shiftEndDate)
            ->whereDate('ends_on', '>=', $shiftStartDate)
            ->get()
            ->groupBy('user_id');

        foreach ($shifts as $shift) {
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
        Collection $shifts,
        EloquentCollection $shiftTemplates,
        RosterValidationResult $result,
    ): void {
        $daysInMonth = CarbonImmutable::create($roster->year, $roster->month, 1)->daysInMonth;
        $weeksFactor = $daysInMonth / 7;

        $shiftMinutes = (int) round((float) ($shiftTemplates->avg('duration_minutes') ?? 0));
        $shiftMinutes = $shiftMinutes > 0 ? $shiftMinutes : $this->defaultShiftMinutes;

        // Alle berechtigten Mitarbeiter betrachten, damit auch gar nicht oder
        // kaum eingeplante Personen erkannt werden (nicht nur die mit Diensten).
        $eligibleEmployees = User::query()
            ->with('employeeProfile')
            ->where('location_id', $roster->location_id)
            ->whereHas('employeeProfile', fn ($query) => $query
                ->where('active', true)
                ->where('employment_area', EmploymentArea::Nursing->value))
            ->get();

        $plannedByUser = $shifts
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

            if ($plannedMinutes > $targetMinutes + $this->plannedHoursToleranceMinutes) {
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

            if ($plannedMinutes < (int) round($targetMinutes * $this->underPlannedFactor)) {
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

    private function targetMinutesForEmployee(User $employee, float $weeksFactor, int $shiftMinutes): int
    {
        return $this->targetMinutesCalculator->targetMinutes($employee->employeeProfile, $weeksFactor, $shiftMinutes);
    }
}
