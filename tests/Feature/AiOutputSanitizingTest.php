<?php

declare(strict_types=1);

use App\Enums\Salutation;
use App\Jobs\GenerateCarePlanJob;
use App\Jobs\GenerateSisJob;
use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisGeneration;
use App\Models\User;
use App\Services\Ai\AiOutputSanitizer;
use App\Services\Ai\CarePlanFormulator;
use App\Services\Ai\SisFormulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    config([
        'ai.ollama.url' => 'http://ollama-test:11434',
        'ai.ollama.model' => 'gemma4:e2b',
    ]);
});

it('strips markdown fences and quotes from model output', function (): void {
    $sanitizer = new AiOutputSanitizer;

    expect($sanitizer->sanitize("```text\nDie Bewohnerin ist mobil.\n```"))
        ->toBe('Die Bewohnerin ist mobil.')
        ->and($sanitizer->sanitize('"Die Bewohnerin ist mobil."'))
        ->toBe('Die Bewohnerin ist mobil.')
        ->and($sanitizer->sanitize('Antwort: Die Bewohnerin ist mobil.'))
        ->toBe('Die Bewohnerin ist mobil.');
});

it('detects the not-applicable sentinel in robust variants', function (): void {
    $sanitizer = new AiOutputSanitizer;

    expect($sanitizer->isNotApplicable('NICHT_RELEVANT'))->toBeTrue()
        ->and($sanitizer->isNotApplicable('nicht relevant'))->toBeTrue()
        ->and($sanitizer->isNotApplicable('NICHT-RELEVANT, weil dazu nichts in der SIS steht.'))->toBeTrue()
        ->and($sanitizer->isNotApplicable(''))->toBeTrue()
        ->and($sanitizer->isNotApplicable('Der Themenblock ist relevant und enthaelt Massnahmen.'))->toBeFalse();
});

it('replaces neutral resident terms mechanically', function (): void {
    $sanitizer = new AiOutputSanitizer;

    ['text' => $text, 'violations' => $violations] = $sanitizer->enforceSalutation(
        'Der Bewohner/in benötigt Hilfe. Die Bewohner:in nutzt einen Rollator.',
        Salutation::Frau,
    );

    expect($text)->toBe('Der Bewohnerin benötigt Hilfe. Die Bewohnerin nutzt einen Rollator.')
        ->and($violations)->toContain('neutral_term_replaced');
});

it('retries once when the model uses the wrong gender term', function (): void {
    Http::fake([
        '*/api/generate' => Http::sequence()
            ->push(['response' => 'Die Bewohnerin ist mobil.'])
            ->push(['response' => 'Der Bewohner ist mobil.']),
    ]);

    $output = app(SisFormulator::class)->formulateField(
        'Mobilität und Beweglichkeit',
        'mobil mit Rollator',
        Salutation::Herr,
    );

    expect($output)->toBe('Der Bewohner ist mobil.');
    Http::assertSentCount(2);
});

it('keeps the sanitized output when the wrong gender term persists after retries', function (): void {
    Http::fake([
        '*/api/generate' => Http::response(['response' => 'Die Bewohnerin ist mobil.']),
    ]);

    $output = app(SisFormulator::class)->formulateField(
        'Mobilität und Beweglichkeit',
        'mobil mit Rollator',
        Salutation::Herr,
    );

    // Lieber ein Text, den die PDL gegenliest, als ein leeres Themenfeld.
    expect($output)->toBe('Die Bewohnerin ist mobil.');
    Http::assertSentCount(2);
});

it('retries the model call once after a transport failure', function (): void {
    Http::fake([
        '*/api/generate' => Http::sequence()
            ->push('Service unavailable', 503)
            ->push(['response' => 'Der Bewohner ist mobil.']),
    ]);

    $output = app(SisFormulator::class)->formulateField(
        'Mobilität und Beweglichkeit',
        'mobil mit Rollator',
        Salutation::Herr,
    );

    expect($output)->toBe('Der Bewohner ist mobil.');
    Http::assertSentCount(2);
});

it('fails after the configured number of transport failures', function (): void {
    Http::fake([
        '*/api/generate' => Http::response('Service unavailable', 503),
    ]);

    expect(fn () => app(SisFormulator::class)->formulateField(
        'Mobilität und Beweglichkeit',
        'mobil mit Rollator',
        Salutation::Herr,
    ))->toThrow(RuntimeException::class);

    Http::assertSentCount(2);
});

it('treats embedded sentinels in care plan output as not applicable', function (): void {
    Http::fake([
        '*/api/generate' => Http::response(['response' => 'nicht relevant, weil die SIS dazu nichts enthaelt.']),
    ]);

    $output = app(CarePlanFormulator::class)->formulateField(
        'topic_2',
        'Ernährung',
        ['opening_question' => null, 'topics' => [], 'risks' => []],
        Salutation::Frau,
    );

    expect($output)->toBeNull();
});

it('rejects starting a sis generation while another one is running', function (): void {
    Queue::fake();

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'running',
        'progress' => 2,
        'total_steps' => 7,
    ]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.generate.start', $resident))
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHas('error');

    expect(SisGeneration::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('rejects starting a care plan generation while another one is running', function (): void {
    Queue::fake();

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'completed_at' => now(),
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $carePlan = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    CarePlanGeneration::query()->create([
        'care_plan_id' => $carePlan->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 17,
    ]);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertRedirect(route('residents.care-plan.show', $resident))
        ->assertSessionHas('error');

    expect(CarePlanGeneration::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('runs the full pipeline from sis generation to care plan topics', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create(['salutation' => 'frau']);
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'opening_question' => 'Garten, Familie',
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    // Eine Sequenz für die ganze Pipeline: 7 SIS-Felder, dann Grundbotschaft
    // und Mobilität des Maßnahmenplans, alle weiteren Themen nicht relevant.
    Http::fake([
        '*/api/generate' => Http::sequence()
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und mobil.'])
            ->push(['response' => 'Ansprache ruhig und langsam.'])
            ->push(['response' => 'Sturzprophylaxe bei jeder Mobilisation.'])
            ->whenEmpty(Http::response(['response' => 'NICHT_RELEVANT'])),
    ]);

    // Schritt 1: SIS-Ausformulierung (7 Felder).
    $sisGeneration = SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 7,
    ]);

    (new GenerateSisJob($sisGeneration->id))->handle(app(SisFormulator::class));

    expect($sisGeneration->refresh()->status)->toBe('completed');

    // Schritt 2: SIS fachlich fertigstellen.
    $this->actingAs($pdl)
        ->post(route('residents.sis.complete', $resident))
        ->assertRedirect();

    expect($sis->refresh()->completed_at)->not->toBeNull();

    // Schritt 3: Maßnahmenplan-Generierung starten (legt MP an).
    Queue::fake();

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertRedirect(route('residents.care-plan.show', $resident));

    Queue::assertPushed(GenerateCarePlanJob::class);

    $carePlanGeneration = CarePlanGeneration::query()->sole();

    // Schritt 4: Job ausführen — nur Mobilität ist relevant.
    (new GenerateCarePlanJob($carePlanGeneration->id))->handle(app(CarePlanFormulator::class));

    $carePlan = $resident->carePlan()->with('topics')->sole();

    expect($carePlanGeneration->refresh()->status)->toBe('completed')
        ->and($carePlan->grundbotschaft)->toBe('Ansprache ruhig und langsam.')
        ->and($carePlan->topics)->toHaveCount(1)
        ->and($carePlan->topics->first()->topic_number)->toBe(1);
});
