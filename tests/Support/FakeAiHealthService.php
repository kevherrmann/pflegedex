<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Ai\AiHealthService;

/**
 * Test-Double fuer AiHealthService.
 *
 * Wird im TestCase::setUp() global gebunden, damit alle Tests die per
 * actingAs+Inertia-Render die Health-Probe ausloesen, keinen echten
 * HTTP-Call machen. Tests die "KI nicht verfuegbar" pruefen wollen,
 * koennen statt dessen unavailable() zurueckgeben:
 *
 *     app()->bind(AiHealthService::class, fn() => FakeAiHealthService::unavailable());
 *
 * Der echte AiHealthService wird in tests/Feature/AiHealthServiceTest.php
 * direkt instanziiert (mit Http-Factory aus dem Container), weil dort
 * gezielt das Verhalten der echten Implementation gepruefte wird.
 */
class FakeAiHealthService extends AiHealthService
{
    /**
     * @param  array{available: bool, modelPresent: bool, model: string, reason: string|null}  $status
     */
    public function __construct(private array $status)
    {
        // Bewusst kein parent::__construct - wir wollen die HTTP-Factory
        // gar nicht erst injecten. Status kommt komplett aus dem Konstruktor.
    }

    public static function healthy(string $model = 'gemma4:e2b'): self
    {
        return new self([
            'available' => true,
            'modelPresent' => true,
            'model' => $model,
            'reason' => null,
        ]);
    }

    public static function unavailable(string $reason = 'KI ist im Test als nicht verfuegbar konfiguriert.'): self
    {
        return new self([
            'available' => false,
            'modelPresent' => false,
            'model' => 'gemma4:e2b',
            'reason' => $reason,
        ]);
    }

    public static function modelMissing(string $model = 'gemma4:e2b'): self
    {
        return new self([
            'available' => true,
            'modelPresent' => false,
            'model' => $model,
            'reason' => sprintf('Ollama laeuft, aber das Modell "%s" ist nicht installiert.', $model),
        ]);
    }

    /**
     * @return array{available: bool, modelPresent: bool, model: string, reason: string|null}
     */
    public function status(): array
    {
        return $this->status;
    }

    public function isAvailable(): bool
    {
        return $this->status['available'] && $this->status['modelPresent'];
    }

    public function forget(): void
    {
        // No-op: Fake hat keinen Cache
    }
}
