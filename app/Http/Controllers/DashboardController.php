<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisGeneration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PDL-Dashboard: zentrale Inbox-Sicht ueber alle Bewohner in den
 * zugeordneten Wohnbereichen.
 *
 * Zugriff: nur PDL. Andere Rollen werden auf /residents umgeleitet
 * (transparente UX, statt eine "kein Zugriff"-Seite zu zeigen).
 *
 * Drei Bloecke (jeweils gefiltert auf accessibleLocations):
 *   1. Was muss ich tun? - SIS/MP-Faelle mit Fristen
 *      - SIS nicht fertiggestellt + >14 Tage (red)
 *      - SIS-Evaluation ueberfaellig (red)
 *      - SIS-Evaluation faellt in <=7 Tagen (yellow)
 *      - MP-Evaluation ueberfaellig (red)
 *      - MP-Evaluation faellt in <=7 Tagen (yellow)
 *      - SIS fertig aber kein MP (yellow)
 *
 *   2. Was laeuft gerade? - aktive/fehlgeschlagene KI-Generationen
 *      - SIS-Generation pending/running (Fortschritt)
 *      - MP-Generation pending/running (Fortschritt)
 *      - Generationen failed (zum Retry)
 *
 *   3. Schnellzugriff - zuletzt aufgenommene Bewohner
 */
class DashboardController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user?->hasRole('PDL')) {
            return redirect()->route('residents.index');
        }

        $locationIds = $user->accessibleLocations()->pluck('id');

        return Inertia::render('Dashboard', [
            'todo' => $this->todoBlock($locationIds),
            'running' => $this->runningBlock($locationIds),
            'recent' => $this->recentBlock($locationIds),
        ]);
    }

    /**
     * Block 1: faellige/ueberfaellige Aufgaben.
     *
     * @return array<string, mixed>
     */
    private function todoBlock($locationIds): array
    {
        $today = today();
        $soon = $today->copy()->addDays(7);
        $admissionDeadline = $today->copy()->subDays(14);

        // SIS nicht fertiggestellt + >14 Tage seit Aufnahme
        $sisOverdueAdmission = Sis::query()
            ->whereIn('location_id', $locationIds)
            ->whereNull('completed_at')
            ->whereDate('started_at', '<', $admissionDeadline)
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->get()
            ->map(fn (Sis $s): array => [
                'residentId' => $s->resident_id,
                'pseudonym' => $s->resident->pseudonym,
                'name' => $s->resident->formal_name,
                'startedAt' => $s->started_at?->toDateString(),
                'severity' => 'red',
            ])
            ->values()
            ->all();

        // SIS-Evaluation ueberfaellig
        $sisEvalOverdue = Sis::query()
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('next_evaluation_due')
            ->whereDate('next_evaluation_due', '<', $today)
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->get()
            ->map(fn (Sis $s): array => [
                'residentId' => $s->resident_id,
                'pseudonym' => $s->resident->pseudonym,
                'name' => $s->resident->formal_name,
                'dueDate' => $s->next_evaluation_due?->toDateString(),
                'severity' => 'red',
            ])
            ->values()
            ->all();

        // SIS-Evaluation faellig in <=7 Tagen
        $sisEvalSoon = Sis::query()
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('next_evaluation_due')
            ->whereDate('next_evaluation_due', '>=', $today)
            ->whereDate('next_evaluation_due', '<=', $soon)
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->get()
            ->map(fn (Sis $s): array => [
                'residentId' => $s->resident_id,
                'pseudonym' => $s->resident->pseudonym,
                'name' => $s->resident->formal_name,
                'dueDate' => $s->next_evaluation_due?->toDateString(),
                'severity' => 'yellow',
            ])
            ->values()
            ->all();

        // MP-Evaluation ueberfaellig
        $mpEvalOverdue = CarePlan::query()
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('next_evaluation_due')
            ->whereDate('next_evaluation_due', '<', $today)
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->get()
            ->map(fn (CarePlan $c): array => [
                'residentId' => $c->resident_id,
                'pseudonym' => $c->resident->pseudonym,
                'name' => $c->resident->formal_name,
                'dueDate' => $c->next_evaluation_due?->toDateString(),
                'severity' => 'red',
            ])
            ->values()
            ->all();

        // MP-Evaluation faellig in <=7 Tagen
        $mpEvalSoon = CarePlan::query()
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('next_evaluation_due')
            ->whereDate('next_evaluation_due', '>=', $today)
            ->whereDate('next_evaluation_due', '<=', $soon)
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->get()
            ->map(fn (CarePlan $c): array => [
                'residentId' => $c->resident_id,
                'pseudonym' => $c->resident->pseudonym,
                'name' => $c->resident->formal_name,
                'dueDate' => $c->next_evaluation_due?->toDateString(),
                'severity' => 'yellow',
            ])
            ->values()
            ->all();

        // SIS fertig, aber noch kein MP
        $sisCompletedNoMp = Sis::query()
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('completed_at')
            ->whereDoesntHave('resident.carePlan')
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->get()
            ->map(fn (Sis $s): array => [
                'residentId' => $s->resident_id,
                'pseudonym' => $s->resident->pseudonym,
                'name' => $s->resident->formal_name,
                'completedAt' => $s->completed_at?->toDateString(),
                'severity' => 'yellow',
            ])
            ->values()
            ->all();

        // Assessment-Wiedervorlagen: jeweils das juengste Assessment je (Bewohner, Typ).
        $latestAssessments = Assessment::query()
            ->whereIn('location_id', $locationIds)
            ->whereNotNull('next_due')
            ->with('resident:id,pseudonym,first_name,last_name,salutation')
            ->orderByDesc('assessed_on')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (Assessment $a): string => $a->resident_id.'|'.$a->type->value);

        $assessmentEvalOverdue = $latestAssessments
            ->filter(fn (Assessment $a): bool => $a->next_due->isBefore($today))
            ->map(fn (Assessment $a): array => [
                'residentId' => $a->resident_id,
                'pseudonym' => $a->resident->pseudonym,
                'name' => $a->resident->formal_name,
                'assessmentType' => $a->type->label(),
                'dueDate' => $a->next_due?->toDateString(),
                'severity' => 'red',
            ])
            ->values()
            ->all();

        $assessmentEvalSoon = $latestAssessments
            ->filter(fn (Assessment $a): bool => ! $a->next_due->isBefore($today) && ! $a->next_due->isAfter($soon))
            ->map(fn (Assessment $a): array => [
                'residentId' => $a->resident_id,
                'pseudonym' => $a->resident->pseudonym,
                'name' => $a->resident->formal_name,
                'assessmentType' => $a->type->label(),
                'dueDate' => $a->next_due?->toDateString(),
                'severity' => 'yellow',
            ])
            ->values()
            ->all();

        return [
            'sisOverdueAdmission' => $sisOverdueAdmission,
            'sisEvalOverdue' => $sisEvalOverdue,
            'sisEvalSoon' => $sisEvalSoon,
            'mpEvalOverdue' => $mpEvalOverdue,
            'mpEvalSoon' => $mpEvalSoon,
            'sisCompletedNoMp' => $sisCompletedNoMp,
            'assessmentEvalOverdue' => $assessmentEvalOverdue,
            'assessmentEvalSoon' => $assessmentEvalSoon,
            // totalRed = ueberfaellige Termine + zu lange offene SIS-Anlage
            'totalRed' => count($sisOverdueAdmission) + count($sisEvalOverdue) + count($mpEvalOverdue) + count($assessmentEvalOverdue),
            // totalYellow = Termine in den naechsten 7 Tagen
            'totalYellow' => count($sisEvalSoon) + count($mpEvalSoon) + count($assessmentEvalSoon),
            // totalGap = strukturelle Luecken (kein Termin, sondern fehlende Artefakte)
            'totalGap' => count($sisCompletedNoMp),
        ];
    }

    /**
     * Block 2: aktive/fehlgeschlagene KI-Generationen.
     *
     * @return array<string, mixed>
     */
    private function runningBlock($locationIds): array
    {
        $sisActive = SisGeneration::query()
            ->whereIn('status', ['pending', 'running'])
            ->whereHas('sis', fn ($q) => $q->whereIn('location_id', $locationIds))
            ->with(['sis.resident:id,pseudonym,first_name,last_name,salutation'])
            ->orderByDesc('created_at')
            ->take(20)
            ->get()
            ->map(fn (SisGeneration $g): array => [
                'generationId' => $g->id,
                'kind' => 'sis',
                'residentId' => $g->sis->resident_id,
                'pseudonym' => $g->sis->resident->pseudonym,
                'name' => $g->sis->resident->formal_name,
                'status' => $g->status,
                'progress' => (int) $g->progress,
                'totalSteps' => (int) $g->total_steps,
                'startedAt' => $g->started_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $mpActive = CarePlanGeneration::query()
            ->whereIn('status', ['pending', 'running'])
            ->whereHas('carePlan', fn ($q) => $q->whereIn('location_id', $locationIds))
            ->with(['carePlan.resident:id,pseudonym,first_name,last_name,salutation'])
            ->orderByDesc('created_at')
            ->take(20)
            ->get()
            ->map(fn (CarePlanGeneration $g): array => [
                'generationId' => $g->id,
                'kind' => 'mp',
                'residentId' => $g->carePlan->resident_id,
                'pseudonym' => $g->carePlan->resident->pseudonym,
                'name' => $g->carePlan->resident->formal_name,
                'status' => $g->status,
                'progress' => (int) $g->progress,
                'totalSteps' => (int) $g->total_steps,
                'startedAt' => $g->started_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        // Failed-Liste: nur die juengste failed-Generation pro Bewohner
        $sisFailed = SisGeneration::query()
            ->where('status', 'failed')
            ->whereHas('sis', fn ($q) => $q->whereIn('location_id', $locationIds))
            ->with(['sis.resident:id,pseudonym,first_name,last_name,salutation'])
            ->orderByDesc('finished_at')
            ->take(10)
            ->get()
            ->map(fn (SisGeneration $g): array => [
                'generationId' => $g->id,
                'kind' => 'sis',
                'residentId' => $g->sis->resident_id,
                'pseudonym' => $g->sis->resident->pseudonym,
                'name' => $g->sis->resident->formal_name,
                'errorMessage' => $g->error_message,
                'finishedAt' => $g->finished_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $mpFailed = CarePlanGeneration::query()
            ->where('status', 'failed')
            ->whereHas('carePlan', fn ($q) => $q->whereIn('location_id', $locationIds))
            ->with(['carePlan.resident:id,pseudonym,first_name,last_name,salutation'])
            ->orderByDesc('finished_at')
            ->take(10)
            ->get()
            ->map(fn (CarePlanGeneration $g): array => [
                'generationId' => $g->id,
                'kind' => 'mp',
                'residentId' => $g->carePlan->resident_id,
                'pseudonym' => $g->carePlan->resident->pseudonym,
                'name' => $g->carePlan->resident->formal_name,
                'errorMessage' => $g->error_message,
                'finishedAt' => $g->finished_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'sisActive' => $sisActive,
            'mpActive' => $mpActive,
            'sisFailed' => $sisFailed,
            'mpFailed' => $mpFailed,
        ];
    }

    /**
     * Block 3: zuletzt aufgenommene Bewohner.
     *
     * @return list<array<string, mixed>>
     */
    private function recentBlock($locationIds): array
    {
        return Resident::query()
            ->whereIn('location_id', $locationIds)
            ->active()
            ->orderByDesc('created_at')
            ->take(5)
            ->with('location:id,name')
            ->get()
            ->map(fn (Resident $r): array => [
                'id' => $r->id,
                'pseudonym' => $r->pseudonym,
                'name' => $r->formal_name,
                'locationName' => $r->location?->name,
                'createdAt' => $r->created_at?->toDateString(),
                'hasSis' => $r->sis()->exists(),
                'sisCompleted' => $r->sis()->whereNotNull('completed_at')->exists(),
                'hasCarePlan' => $r->carePlan()->exists(),
            ])
            ->values()
            ->all();
    }
}
