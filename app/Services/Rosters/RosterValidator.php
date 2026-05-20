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
