<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateCarePlanJob;
use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\Resident;
use App\Models\Sis;
use App\Services\Ai\AiHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpunkte fuer die KI-Erstellung des Massnahmenplans aus einer SIS.
 *
 * Workflow:
 *   - POST /residents/{resident}/care-plan/generate
 *       Voraussetzung: SIS existiert + completed_at !== null.
 *       Wirkung: Falls noch kein MP existiert, wird er angelegt
 *       (started_at=heute, next_evaluation_due=+8w). Anschliessend
 *       wird ein generation-Eintrag (pending) erstellt und der
 *       GenerateCarePlanJob dispatched. Redirect auf MP-Show.
 *   - GET  /residents/{resident}/care-plan/generate/{generation}
 *       JSON-Status fuer Polling im Frontend.
 *
 * Auth:
 *   - start: PDL only (Schreib-Aktion).
 *   - show: PDL + Pflegekraft (lesend).
 */
class CarePlanGenerationController extends Controller
{
    public function start(Request $request, Resident $resident, AiHealthService $health): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        $sis = $resident->sis()->with(['topicEntries', 'risks'])->first();
        if ($sis === null) {
            return redirect()
                ->route('residents.sis.show', $resident)
                ->with('error', 'Maßnahmenplan kann nicht erzeugt werden: Es existiert noch keine SIS.');
        }
        if ($sis->completed_at === null) {
            return redirect()
                ->route('residents.sis.show', $resident)
                ->with('error', 'Maßnahmenplan kann erst erzeugt werden, wenn die SIS fachlich fertiggestellt ist.');
        }

        // Pre-Flight: Ollama erreichbar?
        $status = $health->status();
        if (! $status['available'] || ! $status['modelPresent']) {
            return redirect()
                ->route('residents.sis.show', $resident)
                ->with('error', $status['reason'] ?? 'KI-Service ist gerade nicht verfügbar.');
        }

        // Concurrency-Guard: keine zweite Generierung starten, solange eine
        // laeuft (Doppelklick oder paralleler Start einer zweiten PDL).
        $existingCarePlan = $resident->carePlan()->first();

        if ($existingCarePlan !== null) {
            $hasActiveGeneration = CarePlanGeneration::query()
                ->where('care_plan_id', $existingCarePlan->id)
                ->whereIn('status', [CarePlanGeneration::STATUS_PENDING, CarePlanGeneration::STATUS_RUNNING])
                ->exists();

            if ($hasActiveGeneration) {
                return redirect()
                    ->route('residents.care-plan.show', $resident)
                    ->with('error', 'Es läuft bereits eine KI-Erstellung für diesen Maßnahmenplan.');
            }
        }

        $generation = DB::transaction(function () use ($resident, $sis, $request): CarePlanGeneration {
            $user = $request->user();

            // MP anlegen, falls noch nicht vorhanden
            $carePlan = $resident->carePlan()->first();
            if ($carePlan === null) {
                $carePlan = CarePlan::query()->create([
                    'resident_id' => $resident->id,
                    'location_id' => $resident->location_id,
                    'grundbotschaft' => null,
                    'started_at' => today(),
                    'evaluated_at' => null,
                    'next_evaluation_due' => today()->addWeeks(8),
                    'created_by' => $user->id,
                ]);
                $carePlan->refresh()->load('topics');
                $carePlan->appendVersion('created', $user);
            }

            return CarePlanGeneration::query()->create([
                'care_plan_id' => $carePlan->id,
                'triggered_by' => $user->id,
                'status' => CarePlanGeneration::STATUS_PENDING,
                'progress' => 0,
                'total_steps' => 17,
                'input_snapshot' => json_encode($this->snapshotSis($sis), JSON_UNESCAPED_UNICODE),
            ]);
        });

        GenerateCarePlanJob::dispatch($generation->id);

        return redirect()
            ->route('residents.care-plan.show', $resident)
            ->with('success', 'KI-Erstellung des Maßnahmenplans gestartet. Das dauert einige Minuten.');
    }

    public function show(Request $request, Resident $resident, CarePlanGeneration $generation): JsonResponse
    {
        $this->authorizeRead($request, $resident);
        abort_unless($generation->care_plan_id === $resident->carePlan?->id, 404);

        return response()->json([
            'id' => $generation->id,
            'status' => $generation->status,
            'progress' => (int) $generation->progress,
            'totalSteps' => (int) $generation->total_steps,
            'errorMessage' => $generation->error_message,
            'startedAt' => $generation->started_at?->toIso8601String(),
            'finishedAt' => $generation->finished_at?->toIso8601String(),
        ]);
    }

    private function authorizeRead(Request $request, Resident $resident): void
    {
        $user = $request->user();
        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), 403);
        abort_unless($user->canAccessLocation($resident->location_id), 403);
    }

    private function authorizeWrite(Request $request, Resident $resident): void
    {
        $user = $request->user();
        abort_unless($user?->hasRole('PDL'), 403);
        abort_unless($user->canAccessLocation($resident->location_id), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSis(Sis $sis): array
    {
        return [
            'opening_question' => $sis->opening_question,
            'topics' => $sis->topicEntries->map(fn ($t): array => [
                'topic_number' => $t->topic_number,
                'content' => $t->content,
            ])->all(),
            'risks' => $sis->risks->map(fn ($r): array => [
                'kind' => $r->risk_kind,
                'is_at_risk' => $r->is_at_risk,
                'notes' => $r->notes,
            ])->all(),
        ];
    }
}
