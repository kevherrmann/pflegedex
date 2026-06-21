<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Sis;
use App\Models\SisGeneration;
use App\Models\SisTopicEntry;
use App\Services\Ai\SisFormulator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Faehrt die KI-Ausformulierung einer SIS aus.
 *
 * Lifecycle:
 *  1. Eintrag in sis_generations existiert bereits mit status='pending'
 *     (vom Controller angelegt). Job-Konstruktor bekommt die ID.
 *  2. handle() setzt status='running', started_at=now()
 *  3. Iteriert sequenziell ueber Eingangsfrage + 6 Themenfelder, ruft pro
 *     Feld den SisFormulator auf, schreibt nach jedem fertigen Feld den
 *     progress-Counter hoch (Frontend kann live mitlesen).
 *  4. Wenn alle Felder durch: schreibt Texte zurueck in die SIS, schreibt
 *     einen sis_versions Snapshot mit reason='ai_generated', setzt
 *     status='completed', finished_at=now().
 *  5. Bei Exception: status='failed', error_message=truncated message.
 *
 * Tries=2 + Backoff=10s, damit kurze Ollama-Hick-Ups einen Retry kriegen.
 * Bei finalem Failure ruft failed() auf und persistiert das ins Status-Feld.
 */
class GenerateSisJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 10;

    /**
     * Timeout pro Job-Lauf in Sekunden.
     * 7 Felder * ~30s = 210s als Obergrenze + Puffer.
     */
    public int $timeout = 300;

    public function __construct(public string $generationId) {}

    public function handle(SisFormulator $formulator): void
    {
        $generation = SisGeneration::query()->findOrFail($this->generationId);

        if ($generation->isFinished()) {
            // Idempotenz: bereits fertig (z.B. retry nach completed). Nichts zu tun.
            return;
        }

        $generation->forceFill([
            'status' => SisGeneration::STATUS_RUNNING,
            'started_at' => $generation->started_at ?? now(),
            'progress' => 0,
            'error_message' => null,
        ])->save();

        $sis = Sis::query()
            ->with(['topicEntries', 'risks', 'resident'])
            ->findOrFail($generation->sis_id);

        try {
            $fields = $formulator->fieldsForSis($sis);
            $generation->forceFill(['total_steps' => count($fields)])->save();

            $outputs = [];
            $stepIndex = 0;
            $salutation = $sis->resident->salutation;

            foreach ($fields as $field) {
                $output = $formulator->formulateField($field['label'], $field['content'], $salutation);
                $outputs[$field['key']] = $output;
                $stepIndex++;

                $generation->forceFill(['progress' => $stepIndex])->save();
            }

            $this->applyOutputsToSis($sis, $outputs, $generation);

            $generation->forceFill([
                'status' => SisGeneration::STATUS_COMPLETED,
                'output_snapshot' => json_encode($outputs, JSON_UNESCAPED_UNICODE),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('GenerateSisJob fehlgeschlagen', [
                'generation_id' => $generation->id,
                'sis_id' => $sis->id,
                'message' => $e->getMessage(),
            ]);

            $generation->forceFill([
                'status' => SisGeneration::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }

    /**
     * Wird aufgerufen, wenn alle Tries verbraucht sind.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = SisGeneration::query()->find($this->generationId);
        if ($generation === null || $generation->isFinished()) {
            return;
        }

        $generation->forceFill([
            'status' => SisGeneration::STATUS_FAILED,
            'error_message' => $exception !== null
                ? mb_substr($exception->getMessage(), 0, 500)
                : 'Job nach mehreren Versuchen fehlgeschlagen.',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, string|null>  $outputs
     */
    private function applyOutputsToSis(Sis $sis, array $outputs, SisGeneration $generation): void
    {
        DB::transaction(function () use ($sis, $outputs, $generation): void {
            // Snapshot des Stichpunkte-Stands VOR Ueberschreibung
            $sis->appendVersion('ai_generated', $generation->trigger);

            // Eingangsfrage
            if (array_key_exists('opening_question', $outputs) && $outputs['opening_question'] !== null) {
                $sis->forceFill(['opening_question' => $outputs['opening_question']])->save();
            }

            // Themenfelder 1..6
            foreach ($outputs as $key => $output) {
                if (! str_starts_with($key, 'topic_') || $output === null) {
                    continue;
                }

                $topicNumber = (int) substr($key, strlen('topic_'));

                // Ueber das Model speichern (nicht per Query-Builder-update), damit der
                // 'encrypted'-Cast greift und der Inhalt at-rest verschluesselt wird.
                $entry = SisTopicEntry::query()
                    ->where('sis_id', $sis->id)
                    ->where('topic_number', $topicNumber)
                    ->first();

                $entry?->forceFill(['content' => $output])->save();
            }
        });
    }
}
