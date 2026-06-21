<?php

namespace App\Services\Rosters\Planning;

use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Harte Planungsregeln, die Generator und Verbesserungsphase gemeinsam
 * nutzen. Liefert den Code der ersten verletzten Regel oder null.
 *
 * Das Wochenend-Limit ist als einzige Regel lockerbar: Wenn ein Slot sonst
 * unbesetzt bliebe, gewinnt die Besetzung (der Validator stuft zu viele
 * Wochenenden als Hinweis ein, Unterbesetzung dagegen als Fehler). Die
 * quadratische Wochenend-Fairness-Strafe verteilt die Mehrbelastung dann
 * gleichmäßig über die Mitarbeiter.
 */
class HardConstraintChecker
{
    public function firstFailedConstraint(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $needSpecialist,
        bool $relaxWeekendLimit = false,
    ): ?string {
        if ($needSpecialist && ! $context->isSpecialist($employee)) {
            return 'not_specialist';
        }

        if (! $this->employeeCanWorkShiftTemplate($employee, $shiftTemplate)) {
            return 'shift_capability';
        }

        // Mutterschutz (MuSchG): kein Nacht- und kein Sonntagsdienst (Feiertage: s. isHoliday).
        if ($employee->employeeProfile?->maternity_protection) {
            if ($shiftTemplate->category === 'night') {
                return 'maternity_night';
            }

            if ($date->isSunday() || $context->isHoliday($date)) {
                return 'maternity_sunday';
            }
        }

        // Persönliche Sonderregelungen (mit der PDL vereinbart).
        $profile = $employee->employeeProfile;
        if ($profile !== null) {
            if ($profile->avoids_weekends && in_array($date->dayOfWeekIso, [6, 7], true)) {
                return 'employee_no_weekend';
            }

            if ($this->isRotationWeekOff($profile->week_rotation, $date)) {
                return 'employee_week_off';
            }

            if (in_array($date->dayOfWeekIso, $profile->fixed_free_weekdays ?? [], true)) {
                return 'employee_fixed_free';
            }
        }

        if ($context->isAlreadyAssigned($employee, $shiftTemplate, $date)) {
            return 'already_assigned';
        }

        if ($context->hasApprovedAbsenceOverlap($employee, $startsAt, $endsAt)) {
            return 'absence';
        }

        if ($context->hasRestConflict($employee, $startsAt, $endsAt)) {
            return 'rest_period';
        }

        if ($context->wouldExceedConsecutiveWorkDays($employee, $date)) {
            return 'consecutive_days';
        }

        if (! $relaxWeekendLimit && $context->wouldExceedWeekendLoad($employee, $date)) {
            return 'weekend_limit';
        }

        $shiftMinutes = (int) $startsAt->diffInMinutes($endsAt, true);

        if ($context->wouldExceedWeeklyMaxMinutes($employee, $date, $shiftMinutes)) {
            return 'weekly_hours_cap';
        }

        if ($context->wouldExceedDailyMaxMinutes($employee, $date, $shiftMinutes)) {
            return 'daily_hours_cap';
        }

        return null;
    }

    /**
     * Wochenweiser Wechsel (1 Woche Dienst / 1 Woche frei) über die
     * Kalenderwoche: 'even' = arbeitet nur in geraden KW, 'odd' = nur in
     * ungeraden KW. In der jeweils anderen Woche ist der Tag dienstfrei.
     */
    private function isRotationWeekOff(?string $rotation, CarbonImmutable $date): bool
    {
        if ($rotation !== 'even' && $rotation !== 'odd') {
            return false;
        }

        $isEvenWeek = $date->isoWeek() % 2 === 0;

        return $rotation === 'even' ? ! $isEvenWeek : $isEvenWeek;
    }

    public function employeeCanWorkShiftTemplate(User $employee, ShiftTemplate $shiftTemplate): bool
    {
        return match ($shiftTemplate->category) {
            'early' => $employee->employeeProfile?->can_work_early ?? false,
            'late' => $employee->employeeProfile?->can_work_late ?? false,
            'night' => $employee->employeeProfile?->can_work_night ?? false,
            default => true,
        };
    }
}
