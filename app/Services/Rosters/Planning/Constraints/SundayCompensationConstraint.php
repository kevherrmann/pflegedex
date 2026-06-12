<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Sonntagsarbeit braucht einen freien Ersatzruhetag im Ausgleichszeitraum
 * (6 Tage davor bis 7 Tage danach, § 11 Abs. 3 ArbZG). Bestraft Zuweisungen,
 * die diesen Ausgleich unmöglich machen.
 */
class SundayCompensationConstraint implements SoftConstraint
{
    public function __construct(private readonly int $weight) {}

    public function code(): string
    {
        return 'sunday_compensation';
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

        if ($context->isWorkDate($employee, $dateKey)) {
            return 0;
        }

        $violationsBefore = $context->sundayCompensationViolations($employee);
        $violationsAfter = $context->sundayCompensationViolations($employee, $dateKey);

        return $this->weight * ($violationsAfter - $violationsBefore);
    }
}
