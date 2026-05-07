<?php

declare(strict_types=1);

use App\Services\Ai\AiHealthService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::forget('ai.ollama.health');
    config([
        'ai.ollama.url' => 'http://ollama-test:11434',
        'ai.ollama.model' => 'gemma4:e2b',
        'ai.ollama.health_cache_ttl' => 0,
    ]);

    // Globalen Test-Bind aus TestCase aushebeln, damit hier die echte
    // Implementation getestet wird.
    app()->forgetInstance(AiHealthService::class);
    app()->bind(AiHealthService::class, fn() => new AiHealthService(app(HttpFactory::class)));
});

it('meldet available+modelPresent wenn Ollama das Modell hat', function (): void {
    Http::fake([
        '*/api/tags' => Http::response([
            'models' => [
                ['name' => 'gemma4:e2b', 'size' => 7200000000],
                ['name' => 'llama3:8b', 'size' => 4500000000],
            ],
        ]),
    ]);

    $status = app(AiHealthService::class)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['modelPresent'])->toBeTrue()
        ->and($status['model'])->toBe('gemma4:e2b')
        ->and($status['reason'])->toBeNull();
});

it('meldet available aber modelPresent=false wenn das Modell fehlt', function (): void {
    Http::fake([
        '*/api/tags' => Http::response([
            'models' => [
                ['name' => 'llama3:8b', 'size' => 4500000000],
            ],
        ]),
    ]);

    $status = app(AiHealthService::class)->status();

    expect($status['available'])->toBeTrue()
        ->and($status['modelPresent'])->toBeFalse()
        ->and($status['reason'])->toContain('gemma4:e2b');
});

it('meldet available=false wenn Ollama unreachable ist', function (): void {
    Http::fake([
        '*/api/tags' => Http::response('Connection refused', 503),
    ]);

    $status = app(AiHealthService::class)->status();

    expect($status['available'])->toBeFalse()
        ->and($status['modelPresent'])->toBeFalse()
        ->and($status['reason'])->not->toBeNull();
});

it('matched Modell ohne Tag gegen jeden Tag des installierten Modells', function (): void {
    config(['ai.ollama.model' => 'gemma4']);

    Http::fake([
        '*/api/tags' => Http::response([
            'models' => [
                ['name' => 'gemma4:e2b'],
            ],
        ]),
    ]);

    $status = app(AiHealthService::class)->status();

    expect($status['modelPresent'])->toBeTrue();
});

it('cached den Status fuer health_cache_ttl Sekunden', function (): void {
    config(['ai.ollama.health_cache_ttl' => 30]);

    Http::fake([
        '*/api/tags' => Http::sequence()
            ->push(['models' => [['name' => 'gemma4:e2b']]])
            ->push(['models' => []]), // zweiter Call wuerde leeres Ergebnis liefern
    ]);

    $service = app(AiHealthService::class);

    $first = $service->status();
    $second = $service->status();

    // Wenn Caching greift, ist der zweite Aufruf identisch zum ersten
    expect($first)->toBe($second)
        ->and($first['modelPresent'])->toBeTrue();

    // Genau eine HTTP-Anfrage darf ausgeloest worden sein
    Http::assertSentCount(1);
});

it('forget() invalidiert den Cache', function (): void {
    config(['ai.ollama.health_cache_ttl' => 30]);

    Http::fake([
        '*/api/tags' => Http::sequence()
            ->push(['models' => [['name' => 'gemma4:e2b']]])
            ->push(['models' => []]),
    ]);

    $service = app(AiHealthService::class);

    $service->status();
    $service->forget();
    $second = $service->status();

    expect($second['modelPresent'])->toBeFalse();
    Http::assertSentCount(2);
});
