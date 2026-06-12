<?php

namespace App\Services\Rosters\Planning;

use App\Models\User;

/**
 * Verbesserungsphase: deterministisches Hill-Climbing über Verschiebe- und
 * Tauschzüge. Harte Regeln bleiben unverletzlich, angenommen werden nur Züge,
 * die die Gesamtstrafe der weichen Ziele strikt senken. Bei der kleinen
 * Problemgröße eines Wohnbereichs (~100 Slots, ~12 Mitarbeiter) erreicht das
 * zuverlässig nahezu optimale Pläne ohne externen Solver.
 */
class PlanImprover
{
    private bool $relaxWeekendLimit = false;

    public function __construct(
        private readonly HardConstraintChecker $hardConstraints,
        private readonly SoftPenaltyEvaluator $evaluator,
    ) {}

    /**
     * Verbessert die Auto-Zuweisungen in-place und liefert die erreichte
     * Strafsenkung (negativ oder 0).
     *
     * @param  array<int, PlannedAssignment>  $assignments
     */
    public function improve(PlanningContext $context, array $assignments, DeterministicRandom $random): int
    {
        if (! (bool) config('rostering.improvement.enabled') || count($assignments) === 0) {
            return 0;
        }

        $employees = $context->employees->values()->all();

        if (count($employees) < 2) {
            return 0;
        }

        // Gleiche Lockerungs-Semantik wie die Konstruktion: Ist das
        // Wochenend-Limit zugunsten der Besetzung lockerbar, darf die lokale
        // Suche Wochenend-Mehrbelastung umverteilen — die quadratische
        // Fairness-Strafe haelt die Verteilung im Gleichgewicht.
        $this->relaxWeekendLimit = (bool) config('rostering.relax_weekend_limit_for_coverage');

        $maxIterations = (int) config('rostering.improvement.max_iterations');
        $stallIterations = (int) config('rostering.improvement.stall_iterations');
        $deadline = microtime(true) + ((int) config('rostering.improvement.max_milliseconds')) / 1000;
        $totalDelta = 0;
        $sinceLastImprovement = 0;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            if ($sinceLastImprovement >= $stallIterations) {
                // Konvergiert: Lange keine Verbesserung mehr gefunden.
                break;
            }

            if (($iteration & 0xFF) === 0 && microtime(true) > $deadline) {
                break;
            }

            // Mischung der Zugarten: Verschieben gleicht Stunden aus, Tauschen
            // entkommt lokalen Minima mit gleicher Besetzungszahl, und der
            // Wochenendblock-Zug verschiebt Samstag und Sonntag gemeinsam —
            // Einzeltag-Züge können Wochenend-Paare nicht entbündeln.
            $moveKind = $random->nextInt(10);

            $delta = match (true) {
                $moveKind < 4 => $this->tryTransfer($context, $assignments, $employees, $random),
                $moveKind < 7 => $this->trySwap($context, $assignments, $random),
                default => $this->tryWeekendBlockTransfer($context, $assignments, $employees, $random),
            };

            $totalDelta += $delta;
            $sinceLastImprovement = $delta < 0 ? 0 : $sinceLastImprovement + 1;
        }

        return $totalDelta;
    }

    /**
     * Verschiebt eine Zuweisung auf einen anderen Mitarbeiter.
     *
     * @param  array<int, PlannedAssignment>  $assignments
     * @param  array<int, User>  $employees
     */
    private function tryTransfer(
        PlanningContext $context,
        array $assignments,
        array $employees,
        DeterministicRandom $random,
    ): int {
        $assignment = $assignments[$random->nextInt(count($assignments))];
        $candidate = $employees[$random->nextInt(count($employees))];

        if ($candidate->id === $assignment->employee->id) {
            return 0;
        }

        if (! $this->keepsSpecialistQuota($context, $assignment, $candidate)) {
            return 0;
        }

        $original = $assignment->employee;

        $context->removeAssignment($assignment);
        $delta = -$this->evaluator->deltaForAssignment($context, $assignment);

        $failed = $this->hardConstraints->firstFailedConstraint(
            $context,
            $candidate,
            $assignment->shiftTemplate,
            $assignment->date,
            $assignment->startsAt,
            $assignment->endsAt,
            needSpecialist: false,
            relaxWeekendLimit: $this->relaxWeekendLimit,
        );

        if ($failed !== null) {
            $context->addAssignment($assignment);

            return 0;
        }

        $delta += $this->evaluator->deltaForAdd(
            $context,
            $candidate,
            $assignment->shiftTemplate,
            $assignment->date,
            $assignment->startsAt,
            $assignment->endsAt,
        );

        if ($delta < 0) {
            $assignment->employee = $candidate;
            $context->addAssignment($assignment);

            return $delta;
        }

        $assignment->employee = $original;
        $context->addAssignment($assignment);

        return 0;
    }

    /**
     * Tauscht die Mitarbeiter zweier Zuweisungen.
     *
     * @param  array<int, PlannedAssignment>  $assignments
     */
    private function trySwap(PlanningContext $context, array $assignments, DeterministicRandom $random): int
    {
        if (count($assignments) < 2) {
            return 0;
        }

        $first = $assignments[$random->nextInt(count($assignments))];
        $second = $assignments[$random->nextInt(count($assignments))];

        if ($first === $second
            || $first->employee->id === $second->employee->id
            || ($first->date->equalTo($second->date) && $first->shiftTemplate->id === $second->shiftTemplate->id)) {
            return 0;
        }

        $firstEmployee = $first->employee;
        $secondEmployee = $second->employee;

        // Fachkraftquoten beider Slots muessen nach dem Tausch halten.
        if (! $this->keepsSpecialistQuota($context, $first, $secondEmployee)
            || ! $this->keepsSpecialistQuota($context, $second, $firstEmployee)) {
            return 0;
        }

        $context->removeAssignment($first);
        $delta = -$this->evaluator->deltaForAssignment($context, $first);
        $context->removeAssignment($second);
        $delta -= $this->evaluator->deltaForAssignment($context, $second);

        $rollback = function () use ($context, $first, $second): int {
            $context->addAssignment($second);
            $context->addAssignment($first);

            return 0;
        };

        if ($this->hardConstraints->firstFailedConstraint(
            $context,
            $secondEmployee,
            $first->shiftTemplate,
            $first->date,
            $first->startsAt,
            $first->endsAt,
            needSpecialist: false,
            relaxWeekendLimit: $this->relaxWeekendLimit,
        ) !== null) {
            return $rollback();
        }

        $delta += $this->evaluator->deltaForAdd(
            $context,
            $secondEmployee,
            $first->shiftTemplate,
            $first->date,
            $first->startsAt,
            $first->endsAt,
        );

        $first->employee = $secondEmployee;
        $context->addAssignment($first);

        if ($this->hardConstraints->firstFailedConstraint(
            $context,
            $firstEmployee,
            $second->shiftTemplate,
            $second->date,
            $second->startsAt,
            $second->endsAt,
            needSpecialist: false,
            relaxWeekendLimit: $this->relaxWeekendLimit,
        ) !== null) {
            $context->removeAssignment($first);
            $first->employee = $firstEmployee;

            return $rollback();
        }

        $delta += $this->evaluator->deltaForAdd(
            $context,
            $firstEmployee,
            $second->shiftTemplate,
            $second->date,
            $second->startsAt,
            $second->endsAt,
        );

        if ($delta < 0) {
            $second->employee = $firstEmployee;
            $context->addAssignment($second);

            return $delta;
        }

        $context->removeAssignment($first);
        $first->employee = $firstEmployee;

        return $rollback();
    }

    /**
     * Verschiebt alle Dienste eines Mitarbeiters an einem Wochenende
     * gemeinsam auf einen anderen Mitarbeiter. Nur so kann die lokale Suche
     * ein komplettes Wochenende abgeben — der Wochenend-Zähler sinkt erst,
     * wenn Samstag UND Sonntag frei werden.
     *
     * @param  array<int, PlannedAssignment>  $assignments
     * @param  array<int, User>  $employees
     */
    private function tryWeekendBlockTransfer(
        PlanningContext $context,
        array $assignments,
        array $employees,
        DeterministicRandom $random,
    ): int {
        $seed = $assignments[$random->nextInt(count($assignments))];
        $weekendKey = $context->weekendStartKeyFor($seed->date);

        if ($weekendKey === null) {
            return 0;
        }

        $candidate = $employees[$random->nextInt(count($employees))];

        if ($candidate->id === $seed->employee->id) {
            return 0;
        }

        $block = array_values(array_filter(
            $assignments,
            fn (PlannedAssignment $assignment): bool => $assignment->employee->id === $seed->employee->id
                && $context->weekendStartKeyFor($assignment->date) === $weekendKey,
        ));

        foreach ($block as $assignment) {
            if (! $this->keepsSpecialistQuota($context, $assignment, $candidate)) {
                return 0;
            }
        }

        $original = $seed->employee;
        $delta = 0;

        foreach ($block as $assignment) {
            $context->removeAssignment($assignment);
            $delta -= $this->evaluator->deltaForAssignment($context, $assignment);
        }

        $added = [];

        foreach ($block as $assignment) {
            $failed = $this->hardConstraints->firstFailedConstraint(
                $context,
                $candidate,
                $assignment->shiftTemplate,
                $assignment->date,
                $assignment->startsAt,
                $assignment->endsAt,
                needSpecialist: false,
                relaxWeekendLimit: $this->relaxWeekendLimit,
            );

            if ($failed !== null) {
                $delta = null;
                break;
            }

            $delta += $this->evaluator->deltaForAdd(
                $context,
                $candidate,
                $assignment->shiftTemplate,
                $assignment->date,
                $assignment->startsAt,
                $assignment->endsAt,
            );

            $assignment->employee = $candidate;
            $context->addAssignment($assignment);
            $added[] = $assignment;
        }

        if ($delta !== null && $delta < 0) {
            return $delta;
        }

        // Zurückrollen: neue Zuweisungen entfernen, Original wiederherstellen.
        foreach (array_reverse($added) as $assignment) {
            $context->removeAssignment($assignment);
        }

        foreach ($block as $assignment) {
            $assignment->employee = $original;
            $context->addAssignment($assignment);
        }

        return 0;
    }

    /**
     * Prüft, ob die Fachkraftquote des Slots hält, wenn der bisherige
     * Mitarbeiter der Zuweisung durch den Kandidaten ersetzt wird.
     */
    private function keepsSpecialistQuota(
        PlanningContext $context,
        PlannedAssignment $assignment,
        User $replacement,
    ): bool {
        $requiredSpecialists = $context
            ->staffingRuleFor($assignment->shiftTemplate, $assignment->date)
            ?->required_specialists ?? 0;

        if ($requiredSpecialists <= 0) {
            return true;
        }

        $specialistsAfter = $context->slotSpecialistCount($assignment->date, $assignment->shiftTemplate)
            - ($context->isSpecialist($assignment->employee) ? 1 : 0)
            + ($context->isSpecialist($replacement) ? 1 : 0);

        return $specialistsAfter >= $requiredSpecialists;
    }
}
