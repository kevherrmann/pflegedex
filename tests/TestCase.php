<?php

namespace Tests;

use App\Services\Ai\AiHealthService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAiHealthService;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Queue global fake-en, damit dispatchete Jobs (insbesondere
        // GenerateSisJob aus dem Auto-Generate-Pfad in SisController::store
        // und SisGenerationController::start) nicht real ausgefuehrt werden
        // und auf Ollama zugreifen wollen. Tests die Queue::assertPushed()
        // machen, sehen die Pushes weiterhin.
        Queue::fake();

        // AiHealthService global durch einen Fake ersetzen, der "Modell vorhanden"
        // meldet. Damit triggert HandleInertiaRequests::aiStatus() bei jedem
        // authentifizierten Request keinen echten HTTP-Call zu Ollama.
        //
        // Tests die KI als nicht-verfuegbar simulieren wollen, binden den
        // Service in ihrem Body um:
        //
        //     app()->bind(AiHealthService::class, fn() => FakeAiHealthService::unavailable());
        //
        // Der echte AiHealthService wird im AiHealthServiceTest direkt
        // ueber app()->forgetInstance + new AiHealthService(...) verwendet.
        $this->app->bind(AiHealthService::class, fn () => FakeAiHealthService::healthy());
    }
}
