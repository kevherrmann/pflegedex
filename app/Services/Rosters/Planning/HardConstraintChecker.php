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
            if ($shiftTemplate->code === 'night') {
                return 'maternity_night';
            }

            if ($date->isSunday() || $context->isHoliday($date)) {
                return 'maternity_sunday';
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

    public function employeeCanWorkShiftTemplate(User $employee, ShiftTemplate $shiftTemplate): bool
    {
        return match ($shiftTemplate->code) {
            'early' => $employee->employeeProfile?->can_work_early ?? false,
            'late' => $employee->employeeProfile?->can_work_late ?? false,
            'night' => $employee->employeeProfile?->can_work_night ?? false,
            default => true,
        };
    }
}
