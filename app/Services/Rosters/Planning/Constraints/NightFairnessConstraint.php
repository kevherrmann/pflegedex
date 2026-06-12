<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Verteilt Nachtdienste gleichmäßig: Die Strafe wächst quadratisch mit der
 * Anzahl Nachtdienste eines Mitarbeiters, wodurch der Mitarbeiter mit den
 * wenigsten Nachtdiensten bevorzugt wird, ohne einen Fair-Share-Wert zu
 * benötigen (Summe der Quadrate ist minimal bei Gleichverteilung).
 */
class NightFairnessConstraint implements SoftConstraint
{
    public function __construct(private readonly int $weight) {}

    public function code(): string
    {
        return 'night_fairness';
    }

    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        if ($shiftTemplate->code !== 'night') {
            return 0;
        }

        $nightCount = $context->nightShiftCountFor($employee);

        // (c+1)² − c² = 2c + 1
        return $this->weight * (2 * $nightCount + 1);
    }
}
