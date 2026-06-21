<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LLM-Client für die Textgenerierung. Verwendet das aktuell aktive Modell
 * (siehe {@see AiModelResolver}) und spricht je nach Anbieter:
 *  - 'ollama' das lokale /api/generate, oder
 *  - 'openai' eine OpenAI-kompatible /v1/chat/completions-API (z. B. DeepSeek)
 *    mit hinterlegtem API-Key.
 *
 * Tests mocken das Verhalten via Http::fake().
 */
class OllamaClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function generate(string $prompt, ?string $system = null): string
    {
        $active = app(AiModelResolver::class)->active();

        return $active['provider'] === AiProvider::OpenAi->value
            ? $this->generateViaOpenAi($active, $prompt, $system)
            : $this->generateViaOllama($active, $prompt, $system);
    }

    /**
     * @param  array{provider: string, model: string, base_url: string, api_key: ?string, label: string}  $active
     */
    private function generateViaOllama(array $active, string $prompt, ?string $system): string
    {
        $baseUrl = $active['base_url'] !== '' ? $active['base_url'] : (string) config('ai.ollama.url');
        $model = $active['model'];
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

    /**
     * @param  array{provider: string, model: string, base_url: string, api_key: ?string, label: string}  $active
     */
    private function generateViaOpenAi(array $active, string $prompt, ?string $system): string
    {
        $baseUrl = rtrim($active['base_url'], '/');
        $model = $active['model'];
        $apiKey = (string) ($active['api_key'] ?? '');
        $timeout = (int) config('ai.ollama.timeout', 120);

        if ($baseUrl === '' || $model === '' || $apiKey === '') {
            throw new RuntimeException('Externes KI-Modell ist nicht vollständig konfiguriert (URL/Modell/API-Key).');
        }

        $messages = [];
        if ($system !== null && $system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $response = $this->client($timeout)
            ->withToken($apiKey)
            ->post($baseUrl.'/v1/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'temperature' => (float) config('ai.ollama.temperature', 0.3),
                'top_p' => (float) config('ai.ollama.top_p', 0.9),
            ]);

        if ($response->failed()) {
            Log::warning('Externer KI-Aufruf fehlgeschlagen', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Das externe KI-Modell lieferte Statuscode '.$response->status());
        }

        $text = (string) ($response->json('choices.0.message.content') ?? '');
        if ($text === '') {
            throw new RuntimeException('Das externe KI-Modell lieferte eine leere Antwort.');
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
