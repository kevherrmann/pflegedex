<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Mitarbeiterwünsche: Ein verplanter Wunschfrei-Tag kostet schwer, ein
 * erfüllter Wunschdienst wirkt als Belohnung. Wünsche sind ausschließlich
 * weich — die Besetzung gewinnt immer.
 */
class WishFulfillmentConstraint implements SoftConstraint
{
    public function __construct(
        private readonly int $wishFreeWeight,
        private readonly int $wishShiftWeight,
    ) {}

    public function code(): string
    {
        return 'wish_fulfillment';
    }

    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        $dateKey = $date->toDateString();

        if ($context->hasWishFree($employee, $dateKey)) {
            // Mehrere Dienste am selben Wunschfrei-Tag strafen nur einmal.
            return $context->isWorkDate($employee, $dateKey) ? 0 : $this->wishFreeWeight;
        }

        if ($context->fulfillsWishShift($employee, $dateKey, $shiftTemplate)) {
            return -$this->wishShiftWeight;
        }

        return 0;
    }
}
