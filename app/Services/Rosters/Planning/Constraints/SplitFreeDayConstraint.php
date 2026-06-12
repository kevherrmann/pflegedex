<?php

namespace App\Services\Rosters\Planning\Constraints;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;

/**
 * Vermeidet "geteilte freie Tage": einen einzelnen freien Tag, der zwischen
 * zwei Arbeitstagen eingeklemmt ist. Zusammenhängende freie Blöcke sind für
 * die Erholung deutlich wertvoller.
 */
class SplitFreeDayConstraint implements SoftConstraint
{
    public function __construct(private readonly int $weight) {}

    public function code(): string
    {
        return 'split_free_day';
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
            // Der Tag ist bereits Arbeitstag, das Sandwich-Muster ändert sich nicht.
            return 0;
        }

        $penaltyBefore = 0;
        $penaltyAfter = 0;

        // Betroffen sind nur die Sandwich-Kandidaten Vortag, Tag und Folgetag.
        foreach ([$context->previousDayKey($dateKey), $dateKey, $context->nextDayKey($dateKey)] as $candidate) {
            $penaltyBefore += $this->sandwichPenalty($context, $employee, $candidate, null);
            $penaltyAfter += $this->sandwichPenalty($context, $employee, $candidate, $dateKey);
        }

        return $penaltyAfter - $penaltyBefore;
    }

    private function sandwichPenalty(
        PlanningContext $context,
        User $employee,
        string $candidate,
        ?string $extraWorkDate,
    ): int {
        $isWorked = fn (string $day): bool => $day === $extraWorkDate
            || $context->isWorkDate($employee, $day);

        $isSandwich = ! $isWorked($candidate)
            && $isWorked($context->previousDayKey($candidate))
            && $isWorked($context->nextDayKey($candidate));

        return $isSandwich ? $this->weight : 0;
    }
}
