<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Bevorzugt Vorwärtsrotation (Früh -> Spät -> Nacht): Für jedes Paar
 * direkt aufeinanderfolgender Arbeitstage fällt eine Strafe an, wenn der
 * Rang am Folgetag niedriger ist als am Vortag (z. B. Spät -> Früh).
 */
class RotationConstraint implements SoftConstraint
{
    public function __construct(private readonly int $weight) {}

    public function code(): string
    {
        return 'rotation';
    }

    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        $addedRank = $context->rotationRankForCode($shiftTemplate->code);

        if ($addedRank === null) {
            return 0;
        }

        $dateKey = $date->toDateString();
        $previousKey = $context->previousDayKey($dateKey);
        $nextKey = $context->nextDayKey($dateKey);

        $delta = 0;

        // Paar (Vortag, Tag): Strafe, wenn der hoechste Rang des Vortags
        // ueber dem niedrigsten Rang des Tags liegt.
        $delta += $this->pairPenalty(
            $context->maxRotationRank($employee, $previousKey),
            $this->minWithAdded($context->minRotationRank($employee, $dateKey), $addedRank),
        ) - $this->pairPenalty(
            $context->maxRotationRank($employee, $previousKey),
            $context->minRotationRank($employee, $dateKey),
        );

        // Paar (Tag, Folgetag).
        $delta += $this->pairPenalty(
            $this->maxWithAdded($context->maxRotationRank($employee, $dateKey), $addedRank),
            $context->minRotationRank($employee, $nextKey),
        ) - $this->pairPenalty(
            $context->maxRotationRank($employee, $dateKey),
            $context->minRotationRank($employee, $nextKey),
        );

        return $delta;
    }

    private function pairPenalty(?int $previousDayMaxRank, ?int $nextDayMinRank): int
    {
        if ($previousDayMaxRank === null || $nextDayMinRank === null) {
            return 0;
        }

        return $previousDayMaxRank > $nextDayMinRank ? $this->weight : 0;
    }

    private function minWithAdded(?int $currentMin, int $addedRank): int
    {
        return $currentMin === null ? $addedRank : min($currentMin, $addedRank);
    }

    private function maxWithAdded(?int $currentMax, int $addedRank): int
    {
        return $currentMax === null ? $addedRank : max($currentMax, $addedRank);
    }
}
