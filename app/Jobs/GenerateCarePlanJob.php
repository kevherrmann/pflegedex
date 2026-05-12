<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\CarePlanTopicEntry;
use App\Services\Ai\CarePlanFormulator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Faehrt die KI-Erstellung eines Massnahmenplans aus einer
 * fertiggestellten SIS aus.
 *
 * Lifecycle:
 *   1. care_plan_generations-Eintrag existiert mit status='pending'
 *      (vom CarePlanGenerationController angelegt). Der zugehoerige
 *      MP wurde ebenfalls bereits angelegt (leer, started_at=heute,
 *      next_evaluation_due=+8w).
 *   2. handle() setzt status='running', berechnet SIS-Kontext einmal
 *      vorab, iteriert dann sequenziell ueber:
 *        Grundbotschaft + 16 Themenbloecke (laut Doku-Reihenfolge).
 *      Nach jedem fertigen Feld: progress hochzaehlen.
 *   3. Wenn alle Felder durch: Outputs in MP+Topics persistieren,
 *      Versions-Snapshot mit reason='ai_generated', Status completed.
 *   4. Bei Exception: status='failed', error_message gekuerzt.
 *
 * Tries=2 + Backoff=10s, timeout=900s (17 Calls * ~30s + Puffer).
 */
class GenerateCarePlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 10;

    /** 17 Felder * ~30s = 510s plus Puffer */
    public int $timeout = 900;

    public function __construct(public string $generationId)
    {
    }

    public function handle(CarePlanFormulator $formulator): void
    {
        $generation = CarePlanGeneration::query()->findOrFail($this->generationId);

        if ($generation->isFinished()) {
            return;
        }

        $generation->forceFill([
            'status' => CarePlanGeneration::STATUS_RUNNING,
            'started_at' => $generation->started_at ?? now(),
            'progress' => 0,
            'error_message' => null,
        ])->save();

        $carePlan = CarePlan::query()
            ->with(['topics', 'resident.sis.topicEntries', 'resident.sis.risks'])
            ->findOrFail($generation->care_plan_id);

        $sis = $carePlan->resident->sis;
        if ($sis === null || $sis->completed_at === null) {
            $generation->forceFill([
                'status' => CarePlanGeneration::STATUS_FAILED,
                'error_message' => 'SIS fehlt oder ist nicht fertiggestellt.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        try {
            $fields = $formulator->fieldsForCarePlan();
            $generation->forceFill(['total_steps' => count($fields)])->save();

            $sisContext = $formulator->sisContext($sis);
            $salutation = $carePlan->resident->salutation;

            $outputs = [];
            $stepIndex = 0;

            foreach ($fields as $field) {
                $output = $formulator->formulateField(
                    $field['key'],
                    $field['label'],
                    $sisContext,
                    $salutation,
                );
                $outputs[$field['key']] = $output;
                $stepIndex++;

                $generation->forceFill(['progress' => $stepIndex])->save();
            }

            $this->applyOutputsToCarePlan($carePlan, $outputs, $generation);

            $generation->forceFill([
                'status' => CarePlanGeneration::STATUS_COMPLETED,
                'output_snapshot' => json_encode($outputs, JSON_UNESCAPED_UNICODE),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('GenerateCarePlanJob fehlgeschlagen', [
                'generation_id' => $generation->id,
                'care_plan_id' => $carePlan->id,
                'message' => $e->getMessage(),
            ]);

            $generation->forceFill([
                'status' => CarePlanGeneration::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $generation = CarePlanGeneration::query()->find($this->generationId);
        if ($generation === null || $generation->isFinished()) {
            return;
        }

        $generation->forceFill([
            'status' => CarePlanGeneration::STATUS_FAILED,
            'error_message' => $exception !== null
                ? mb_substr($exception->getMessage(), 0, 500)
                : 'Job nach mehreren Versuchen fehlgeschlagen.',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, string|null>  $outputs
     */
    private function applyOutputsToCarePlan(CarePlan $carePlan, array $outputs, CarePlanGeneration $generation): void
    {
        DB::transaction(function () use ($carePlan, $outputs, $generation): void {
            $carePlan->refresh()->load('topics');
            $carePlan->appendVersion('ai_generated', $generation->trigger);

            // Grundbotschaft
            if (array_key_exists('grundbotschaft', $outputs) && $outputs['grundbotschaft'] !== null) {
                $carePlan->forceFill(['grundbotschaft' => $outputs['grundbotschaft']])->save();
            }

            // Themenbloecke
            foreach ($outputs as $key => $output) {
                if (! str_starts_with($key, 'topic_')) {
                    continue;
                }
                $topicNumber = (int) substr($key, strlen('topic_'));

                if ($output === null || trim($output) === '') {
                    // Nicht relevant: keinen Eintrag anlegen.
                    continue;
                }

                CarePlanTopicEntry::query()->updateOrCreate(
                    ['care_plan_id' => $carePlan->id, 'topic_number' => $topicNumber],
                    ['content' => $output],
                );
            }
        });
    }
}
