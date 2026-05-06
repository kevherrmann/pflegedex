<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Prueft ob Ollama erreichbar ist und das konfigurierte Modell vorhanden ist.
 *
 * Das Ergebnis wird gecacht (TTL aus config/ai.php), damit nicht jeder
 * Inertia-Page-Render einen HTTP-Roundtrip zum LLM-Server ausloest.
 *
 * Wird verwendet von:
 *  - HandleInertiaRequests (Permission 'aiAvailable' fuer Frontend)
 *  - SisGenerationController (Pre-Flight-Check beim Start eines Jobs)
 */
class AiHealthService
{
    private const CACHE_KEY = 'ai.ollama.health';

    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * @return array{available: bool, modelPresent: bool, model: string, reason: string|null}
     */
    public function status(): array
    {
        $ttl = (int) config('ai.ollama.health_cache_ttl', 30);

        if ($ttl <= 0) {
            return $this->probe();
        }

        return Cache::remember(self::CACHE_KEY, $ttl, fn(): array => $this->probe());
    }

    public function isAvailable(): bool
    {
        $status = $this->status();

        return $status['available'] && $status['modelPresent'];
    }

    /**
     * Cache invalidieren - z.B. nach Modell-Pull oder vor manuellem Re-Check.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{available: bool, modelPresent: bool, model: string, reason: string|null}
     */
    private function probe(): array
    {
        $url = (string) config('ai.ollama.url');
        $model = (string) config('ai.ollama.model');

        $result = [
            'available' => false,
            'modelPresent' => false,
            'model' => $model,
            'reason' => null,
        ];

        if ($url === '' || $model === '') {
            $result['reason'] = 'Ollama ist nicht konfiguriert (OLLAMA_URL/AI_MODEL).';
            return $result;
        }

        try {
            $response = $this->http
                ->timeout(5)
                ->connectTimeout(2)
                ->acceptJson()
                ->get(rtrim($url, '/').'/api/tags');

            if ($response->failed()) {
                $result['reason'] = 'Ollama antwortet mit Status '.$response->status().'.';
                return $result;
            }

            $result['available'] = true;

            $models = (array) ($response->json('models') ?? []);
            foreach ($models as $entry) {
                $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
                if ($this->matchesModel($name, $model)) {
                    $result['modelPresent'] = true;
                    return $result;
                }
            }

            $result['reason'] = sprintf(
                'Ollama laeuft, aber das Modell "%s" ist nicht installiert.',
                $model,
            );
            return $result;
        } catch (Throwable $e) {
            $result['reason'] = 'Ollama nicht erreichbar: '.$e->getMessage();
            return $result;
        }
    }

    /**
     * Modell-Namen vergleichen mit Toleranz fuer Tag-Variationen.
     *
     * Beispiele die als Match gelten:
     *  - 'gemma4:e2b' == 'gemma4:e2b'
     *  - 'gemma4:e2b' matched 'gemma4:latest' wenn Konfiguration nur 'gemma4'
     */
    private function matchesModel(string $installed, string $configured): bool
    {
        if ($installed === $configured) {
            return true;
        }

        // Falls jemand AI_MODEL ohne Tag konfiguriert: 'gemma4' matched 'gemma4:irgendwas'
        if (! str_contains($configured, ':')) {
            return str_starts_with($installed, $configured.':');
        }

        return false;
    }
}
