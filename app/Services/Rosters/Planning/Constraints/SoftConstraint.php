<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Ein weiches Planungsziel. Die Strafe ist eine reine Funktion des
 * Planungszustands: Die Strafänderung beim Entfernen einer Zuweisung ist
 * exakt das Negative der Strafänderung beim erneuten Hinzufügen. Die lokale
 * Suche nutzt das, um Züge inkrementell und exakt zu bewerten.
 */
interface SoftConstraint
{
    public function code(): string;

    /**
     * Strafänderung, wenn der Mitarbeiter diese Schicht zusätzlich übernimmt.
     * Der Planungszustand enthält die Zuweisung noch nicht.
     */
    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int;
}
