<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Abweichung von der monatlichen Soll-Arbeitszeit, pro Minute gewichtet.
 * Auffüllen Richtung Soll senkt die Strafe, Überplanung erhöht sie.
 */
class HoursTargetDeviationConstraint implements SoftConstraint
{
    public function __construct(private readonly int $weight) {}

    public function code(): string
    {
        return 'hours_deviation';
    }

    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        $plannedMinutes = $context->plannedMinutesFor($employee);
        $targetMinutes = $context->targetMinutesFor($employee);
        $shiftMinutes = (int) $startsAt->diffInMinutes($endsAt, true);

        $deviationBefore = abs($plannedMinutes - $targetMinutes);
        $deviationAfter = abs($plannedMinutes + $shiftMinutes - $targetMinutes);

        return $this->weight * ($deviationAfter - $deviationBefore);
    }
}
