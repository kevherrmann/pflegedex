<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Verteilt Wochenenden gleichmäßig (quadratische Strafe je Mitarbeiter).
 * Ein bereits angebrochenes Wochenende (Samstag gearbeitet, Sonntag kommt
 * dazu) kostet nichts zusätzlich.
 */
class WeekendFairnessConstraint implements SoftConstraint
{
    public function __construct(private readonly int $weight) {}

    public function code(): string
    {
        return 'weekend_fairness';
    }

    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        $weekendKey = $context->weekendStartKeyFor($date);

        if ($weekendKey === null || $context->hasWeekend($employee, $weekendKey)) {
            return 0;
        }

        $weekendCount = $context->weekendCountFor($employee);

        return $this->weight * (2 * $weekendCount + 1);
    }
}
