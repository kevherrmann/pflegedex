<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateSisJob;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisGeneration;
use App\Services\Ai\AiHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endpunkte fuer die KI-Ausformulierung einer SIS.
 *
 * Workflow:
 *  - POST /residents/{resident}/sis/generate         -> Job dispatchen, redirect auf show
 *  - GET  /residents/{resident}/sis/generate/{id}    -> JSON-Status fuer Polling im Frontend
 *
 * Auth: Nur PDL darf generieren (analog zur SIS-Schreib-Berechtigung).
 */
class SisGenerationController extends Controller
{
    public function start(Request $request, Resident $resident, AiHealthService $health): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        // Pre-Flight: ist Ollama erreichbar und das Modell vorhanden?
        // Sonst kein Job-Dispatch, sondern Redirect mit Fehlermeldung.
        $status = $health->status();
        if (! $status['available'] || ! $status['modelPresent']) {
            return redirect()
                ->route('residents.sis.show', $resident)
                ->with('error', $status['reason'] ?? 'KI-Service ist gerade nicht verfügbar.');
        }

        /** @var Sis $sis */
        $sis = $resident->sis()->with(['topicEntries', 'risks'])->firstOrFail();

        // Concurrency-Guard: keine zweite Generierung starten, solange eine
        // laeuft (Doppelklick oder paralleler Start einer zweiten PDL).
        $hasActiveGeneration = SisGeneration::query()
            ->where('sis_id', $sis->id)
            ->whereIn('status', [SisGeneration::STATUS_PENDING, SisGeneration::STATUS_RUNNING])
            ->exists();

        if ($hasActiveGeneration) {
            return redirect()
                ->route('residents.sis.show', $resident)
                ->with('error', 'Es läuft bereits eine KI-Ausformulierung für diese SIS.');
        }

        $generation = SisGeneration::query()->create([
            'sis_id' => $sis->id,
            'triggered_by' => $request->user()->id,
            'status' => SisGeneration::STATUS_PENDING,
            'progress' => 0,
            'total_steps' => 7,
            'input_snapshot' => json_encode($this->snapshotSis($sis), JSON_UNESCAPED_UNICODE),
        ]);

        GenerateSisJob::dispatch($generation->id);

        return redirect()
            ->route('residents.sis.show', $resident)
            ->with('success', 'KI-Ausformulierung gestartet. Das dauert einige Sekunden.');
    }

    public function show(Request $request, Resident $resident, SisGeneration $generation): JsonResponse
    {
        $this->authorizeRead($request, $resident);
        abort_unless($generation->sis_id === $resident->sis?->id, 404);

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
        ];
    }
}
