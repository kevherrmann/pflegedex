<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiModel;
use Throwable;

/**
 * Liefert das aktuell aktive KI-Modell. Reihenfolge: aktives Modell → Standard
 * (Gemma) → Konfigurations-Fallback aus config/ai.php. So funktioniert die
 * Generierung auch, solange noch kein Modell in der DB hinterlegt ist.
 */
class AiModelResolver
{
    /**
     * @return array{provider: string, model: string, base_url: string, api_key: ?string, label: string}
     */
    public function active(): array
    {
        try {
            $model = AiModel::query()->where('is_active', true)->first()
                ?? AiModel::query()->where('is_default', true)->first();
        } catch (Throwable) {
            // z. B. Tabelle noch nicht migriert – auf Config zurückfallen.
            $model = null;
        }

        if ($model !== null) {
            $provider = $model->provider instanceof AiProvider
                ? $model->provider->value
                : (string) $model->provider;

            $baseUrl = (string) ($model->base_url ?? '');

            if ($baseUrl === '' && $provider === AiProvider::Ollama->value) {
                $baseUrl = (string) config('ai.ollama.url');
            }

            return [
                'provider' => $provider,
                'model' => (string) $model->model,
                'base_url' => $baseUrl,
                'api_key' => $model->api_key,
                'label' => (string) $model->label,
            ];
        }

        return [
            'provider' => AiProvider::Ollama->value,
            'model' => (string) config('ai.ollama.model'),
            'base_url' => (string) config('ai.ollama.url'),
            'api_key' => null,
            'label' => 'Standard',
        ];
    }
}
