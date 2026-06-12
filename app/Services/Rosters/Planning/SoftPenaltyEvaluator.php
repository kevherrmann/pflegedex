<?php

namespace App\Services\Rosters\Planning;

use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\Constraints\HoursTargetDeviationConstraint;
use App\Services\Rosters\Planning\Constraints\NightFairnessConstraint;
use App\Services\Rosters\Planning\Constraints\RotationConstraint;
use App\Services\Rosters\Planning\Constraints\SoftConstraint;
use App\Services\Rosters\Planning\Constraints\SplitFreeDayConstraint;
use App\Services\Rosters\Planning\Constraints\SundayCompensationConstraint;
use App\Services\Rosters\Planning\Constraints\WeekendFairnessConstraint;
use App\Services\Rosters\Planning\Constraints\WishFulfillmentConstraint;
use Carbon\CarbonImmutable;

/**
 * Bündelt alle weichen Planungsziele zu einer Gesamtstrafe. Da jede Strafe
 * eine reine Zustandsfunktion ist, gilt exakt: Entfernen einer Zuweisung
 * ändert die Strafe um das Negative des erneuten Hinzufügens.
 */
class SoftPenaltyEvaluator
{
    /** @var array<int, SoftConstraint> */
    private array $constraints;

    public function __construct()
    {
        $weights = config('rostering.weights');

        $this->constraints = [
            new HoursTargetDeviationConstraint((int) $weights['hours_deviation']),
            new NightFairnessConstraint((int) $weights['night_fairness']),
            new WeekendFairnessConstraint((int) $weights['weekend_fairness']),
            new RotationConstraint((int) $weights['rotation']),
            new SplitFreeDayConstraint((int) $weights['split_free_day']),
            new WishFulfillmentConstraint((int) $weights['wish_free'], (int) $weights['wish_shift']),
            new SundayCompensationConstraint((int) $weights['sunday_compensation']),
        ];
    }

    public function deltaForAdd(
        PlanningContext $context,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        $delta = 0;

        foreach ($this->constraints as $constraint) {
            $delta += $constraint->deltaForAdd($context, $employee, $shiftTemplate, $date, $startsAt, $endsAt);
        }

        return $delta;
    }

    public function deltaForAssignment(PlanningContext $context, PlannedAssignment $assignment): int
    {
        return $this->deltaForAdd(
            $context,
            $assignment->employee,
            $assignment->shiftTemplate,
            $assignment->date,
            $assignment->startsAt,
            $assignment->endsAt,
        );
    }
}
