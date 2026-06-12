<?php

declare(strict_types=1);

use App\Jobs\GenerateSisJob;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisGeneration;
use App\Models\User;
use App\Services\Ai\AiHealthService;
use App\Services\Ai\SisFormulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeAiHealthService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    config([
        'ai.ollama.url' => 'http://ollama-test:11434',
        'ai.ollama.model' => 'gemma4:e2b',
    ]);
});

it('PDL kann eine Generation starten und der Job wird dispatcht', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.generate.start', $resident))
        ->assertRedirect(route('residents.sis.show', $resident));

    expect(SisGeneration::query()->where('sis_id', $sis->id)->count())->toBe(1);

    $generation = SisGeneration::query()->first();
    expect($generation->status)->toBe('pending')
        ->and($generation->triggered_by)->toBe($pdl->id);

    Queue::assertPushed(GenerateSisJob::class);
});

it('blockt den Generation-Start wenn Ollama nicht verfuegbar ist', function (): void {
    app()->bind(AiHealthService::class, fn () => FakeAiHealthService::unavailable());

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.generate.start', $resident))
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHas('error');

    expect(SisGeneration::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('Pflegekraft darf keine Generation starten', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->post(route('residents.sis.generate.start', $resident))
        ->assertForbidden();

    expect(SisGeneration::query()->count())->toBe(0);
});

it('Pflegekraft darf den Status pollen (read-only)', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $generation = SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'running',
        'progress' => 3,
        'total_steps' => 7,
    ]);

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->getJson(route('residents.sis.generate.show', [$resident, $generation]))
        ->assertOk()
        ->assertJson([
            'id' => $generation->id,
            'status' => 'running',
            'progress' => 3,
            'totalSteps' => 7,
        ]);
});

it('GenerateSisJob ueberschreibt SIS-Felder mit Ollama-Antwort', function (): void {
    Http::fake([
        '*/api/generate' => Http::sequence()
            ->push(['response' => 'Die Bewohnerin moechte mehr Zeit im Garten verbringen.'])
            ->push(['response' => 'Die Bewohnerin ist orientiert und kommunikativ.'])
            ->push(['response' => 'Die Bewohnerin nutzt einen Rollator.'])
            ->push(['response' => 'Diabetes Typ 2, gut eingestellt.'])
            ->push(['response' => 'Hilfe beim Duschen erforderlich.'])
            ->push(['response' => 'Regelmaessiger Familienkontakt.'])
            ->push(['response' => 'Wohnt in einem Einzelzimmer.']),
    ]);

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create(['salutation' => 'frau']);
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'opening_question' => 'Garten, Familie',
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $generation = SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 7,
    ]);

    (new GenerateSisJob($generation->id))->handle(app(SisFormulator::class));

    $sis->refresh();
    expect($sis->opening_question)->toBe('Die Bewohnerin moechte mehr Zeit im Garten verbringen.');

    $generation->refresh();
    expect($generation->status)->toBe('completed')
        ->and($generation->progress)->toBe(7)
        ->and($generation->finished_at)->not->toBeNull();

    expect($sis->versions()->where('snapshot_reason', 'ai_generated')->exists())->toBeTrue();
});

it('GenerateSisJob setzt status=failed bei Ollama-Fehler', function (): void {
    Http::fake([
        '*/api/generate' => Http::response('Service unavailable', 503),
    ]);

    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'opening_question' => 'Test',
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $generation = SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 7,
    ]);

    try {
        (new GenerateSisJob($generation->id))->handle(app(SisFormulator::class));
    } catch (Throwable) {
        // Job re-throwt - das ist fuer Queue-Retry gewollt
    }

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error_message)->not->toBeNull()
        ->and($generation->finished_at)->not->toBeNull();
});

it('Show-Page liefert latestGeneration wenn vorhanden', function (): void {
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
        ->get(route('residents.sis.show', $resident))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sis/Show')
            ->where('latestGeneration.status', 'running')
            ->where('latestGeneration.progress', 2)
        );
});
