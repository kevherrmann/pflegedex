<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Duenner Wrapper um die Ollama-/api/generate-API.
 *
 * Liest Konfiguration aus env:
 *  - OLLAMA_URL (Basis-URL ohne /api)
 *  - AI_MODEL (Modell-Tag)
 *  - OLLAMA_TIMEOUT_SECONDS (optional, Default 120)
 *
 * Erlaubt es Tests, das Verhalten ohne echten Ollama-Server zu mocken
 * via Http::fake().
 */
class OllamaClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function generate(string $prompt, ?string $system = null): string
    {
        $baseUrl = (string) config('ai.ollama.url');
        $model = (string) config('ai.ollama.model');
        $timeout = (int) config('ai.ollama.timeout', 120);

        if ($baseUrl === '' || $model === '') {
            throw new RuntimeException('Ollama ist nicht konfiguriert (OLLAMA_URL/AI_MODEL).');
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => (float) config('ai.ollama.temperature', 0.3),
                'top_p' => (float) config('ai.ollama.top_p', 0.9),
            ],
        ];

        if ($system !== null && $system !== '') {
            $payload['system'] = $system;
        }

        $response = $this->client($timeout)
            ->post(rtrim($baseUrl, '/').'/api/generate', $payload);

        if ($response->failed()) {
            Log::warning('Ollama-Aufruf fehlgeschlagen', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Ollama lieferte Statuscode '.$response->status());
        }

        $text = (string) ($response->json('response') ?? '');
        if ($text === '') {
            throw new RuntimeException('Ollama lieferte leere Antwort.');
        }

        return trim($text);
    }

    private function client(int $timeout): PendingRequest
    {
        return $this->http
            ->timeout($timeout)
            ->connectTimeout(15)
            ->acceptJson();
    }
}
