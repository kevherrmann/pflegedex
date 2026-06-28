<?php

namespace App\Services\Rosters;

use App\Enums\ShiftCategory;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Services\Rosters\Planning\HardConstraintChecker;
use App\Services\Rosters\Planning\PlanningContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Vertretungssuche (Human-in-the-Loop): Ermittelt für einen Dienstplan die ab
 * heute noch unterbesetzten Schicht-Kategorien und schlägt je offene Stelle die
 * regelkonform einsetzbaren Mitarbeitenden vor – sortiert nach freier Kapazität
 * (Reststunden bis zum Monats-Soll). Der Planer entscheidet, wer eingesetzt
 * wird; automatisch wird niemand verplant.
 *
 * Quelle der Wahrheit sind {@see PlanningContext} (aktueller Besetzungs- und
 * Stundenstand) und {@see HardConstraintChecker} (gesetzliche/fachliche Regeln),
 * exakt wie beim automatischen Generator – so weichen Vorschlag und Validierung
 * nicht voneinander ab.
 */
class ReplacementCandidateService
{
    private const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    public function __construct(
        private readonly HardConstraintChecker $hardConstraints,
        private readonly RosterDateService $rosterDateService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openSlots(Roster $roster): array
    {
        $roster->loadMissing('location');

        $cutoff = $this->cutoffDate($roster);
        $context = new PlanningContext($roster);

        $relaxWeekendLimit = (bool) config('rostering.relax_weekend_limit_for_coverage');

        $openSlots = [];

        foreach ($this->rosterDateService->datesForRosterMonth($roster) as $date) {
            // Vergangene Tage sind gelaufen – keine Vertretung mehr nötig.
            if ($date->lessThan($cutoff)) {
                continue;
            }

            foreach ($context->categoriesWithTemplates() as $category) {
                $rule = $context->categoryStaffingFor($category, $date);

                if ($rule === null) {
                    continue;
                }

                $currentTotal = $context->slotCategoryTotal($date, $category);
                $currentSpecialists = $context->slotCategorySpecialistCount($date, $category);

                $missingTotal = max(0, $rule->required_total_staff - $currentTotal);
                $missingSpecialists = max(0, $rule->required_specialists - $currentSpecialists);

                if ($missingTotal === 0 && $missingSpecialists === 0) {
                    continue;
                }

                $templates = $context->templatesForCategory($category);

                $openSlots[] = [
                    'date' => $date->toDateString(),
                    'weekday' => self::WEEKDAYS[$date->dayOfWeekIso - 1],
                    'isWeekend' => $date->dayOfWeekIso >= 6,
                    'category' => $category,
                    'categoryLabel' => ShiftCategory::tryFrom($category)?->label() ?? $category,
                    'requiredTotal' => $rule->required_total_staff,
                    'currentTotal' => $currentTotal,
                    'missingTotal' => $missingTotal,
                    'requiredSpecialists' => $rule->required_specialists,
                    'currentSpecialists' => $currentSpecialists,
                    'missingSpecialists' => $missingSpecialists,
                    'templates' => $templates
                        ->map(fn (ShiftTemplate $template): array => [
                            'id' => $template->id,
                            'name' => $template->name,
                            'code' => $template->code,
                        ])
                        ->values()
                        ->all(),
                    'candidates' => $this->candidatesForSlot(
                        $context,
                        $date,
                        $templates,
                        $missingTotal,
                        $missingSpecialists > 0,
                        $relaxWeekendLimit,
                    ),
                ];
            }
        }

        return $openSlots;
    }

    /**
     * Regelkonform einsetzbare Mitarbeitende für eine offene Stelle, sortiert
     * nach freier Kapazität (Reststunden) absteigend. Bei fehlender Fachkraft
     * werden Fachkräfte vorgezogen.
     *
     * @param  Collection<int, ShiftTemplate>  $templates
     * @return array<int, array<string, mixed>>
     */
    private function candidatesForSlot(
        PlanningContext $context,
        CarbonImmutable $date,
        $templates,
        int $missingTotal,
        bool $needsSpecialist,
        bool $relaxWeekendLimit,
    ): array {
        $candidates = [];

        // Fehlt nur noch eine Fachkraft (Gesamtzahl stimmt), helfen nur
        // Fachkräfte – Nicht-Fachkräfte würden die Lücke nicht schließen.
        $specialistsOnly = $missingTotal === 0 && $needsSpecialist;

        foreach ($context->employees as $employee) {
            if ($specialistsOnly && ! $context->isSpecialist($employee)) {
                continue;
            }

            $feasibleTemplates = [];

            foreach ($templates as $template) {
                [$startsAt, $endsAt] = $this->rosterDateService->buildShiftTimes($date, $template);

                $failed = $this->hardConstraints->firstFailedConstraint(
                    $context,
                    $employee,
                    $template,
                    $date,
                    $startsAt,
                    $endsAt,
                    needSpecialist: false,
                    relaxWeekendLimit: $relaxWeekendLimit,
                );

                if ($failed === null) {
                    $feasibleTemplates[] = [
                        'id' => $template->id,
                        'name' => $template->name,
                        'code' => $template->code,
                    ];
                }
            }

            if ($feasibleTemplates === []) {
                continue;
            }

            $targetMinutes = $context->targetMinutesFor($employee);
            $plannedMinutes = $context->plannedMinutesFor($employee);
            $remainingMinutes = $targetMinutes > 0 ? max(0, $targetMinutes - $plannedMinutes) : 0;
            $isSpecialist = $context->isSpecialist($employee);

            $candidates[] = [
                'userId' => $employee->id,
                'name' => $employee->name,
                'isSpecialist' => $isSpecialist,
                'coversSpecialistNeed' => $needsSpecialist && $isSpecialist,
                'plannedMinutes' => $plannedMinutes,
                'targetMinutes' => $targetMinutes,
                'remainingMinutes' => $remainingMinutes,
                'remainingLabel' => $this->minutesLabel($remainingMinutes),
                'feasibleTemplates' => $feasibleTemplates,
            ];
        }

        usort($candidates, function (array $first, array $second) use ($needsSpecialist): int {
            // Bei fehlender Fachkraft: Fachkräfte zuerst.
            if ($needsSpecialist && $first['coversSpecialistNeed'] !== $second['coversSpecialistNeed']) {
                return $second['coversSpecialistNeed'] <=> $first['coversSpecialistNeed'];
            }

            // Dann die mit den meisten Reststunden (am wenigsten ausgelastet).
            return [$second['remainingMinutes'], $first['name']]
                <=> [$first['remainingMinutes'], $second['name']];
        });

        return $candidates;
    }

    private function cutoffDate(Roster $roster): CarbonImmutable
    {
        $monthStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $today = CarbonImmutable::today();

        return $today->greaterThan($monthStart) ? $today : $monthStart;
    }

    private function minutesLabel(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} h" : "{$hours} h {$rest} min";
    }
}
