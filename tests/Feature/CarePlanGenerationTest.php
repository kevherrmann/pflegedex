<?php

declare(strict_types=1);

use App\Jobs\GenerateCarePlanJob;
use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\CarePlanVersion;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\User;
use App\Services\Ai\AiHealthService;
use App\Services\Ai\CarePlanFormulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeAiHealthService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-08 10:00:00');

    config([
        'ai.ollama.url' => 'http://ollama-test:11434',
        'ai.ollama.model' => 'gemma4:e2b',
    ]);
});

function setupResidentWithCompletedSis(): array
{
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return [$location, $resident, $sis, $pdl];
}

it('PDL kann MP-Generation starten (legt MP an, dispatcht Job)', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();

    expect($resident->fresh()->carePlan)->toBeNull();

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertRedirect(route('residents.care-plan.show', $resident));

    $cp = $resident->fresh()->carePlan;
    expect($cp)->not->toBeNull()
        ->and($cp->started_at?->toDateString())->toBe('2026-05-08')
        ->and($cp->next_evaluation_due?->toDateString())->toBe('2026-07-03');

    $generation = CarePlanGeneration::query()->where('care_plan_id', $cp->id)->firstOrFail();
    expect($generation->status)->toBe('pending')
        ->and($generation->total_steps)->toBe(17)
        ->and($generation->triggered_by)->toBe($pdl->id);

    Queue::assertPushed(GenerateCarePlanJob::class);
});

it('Generation-Start verwendet existierenden MP, legt keinen zweiten an', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $existing = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertRedirect();

    expect($resident->fresh()->carePlan()->count())->toBe(1)
        ->and($resident->fresh()->carePlan->id)->toBe($existing->id);

    expect(CarePlanGeneration::query()->where('care_plan_id', $existing->id)->count())->toBe(1);
});

it('Generation-Start scheitert wenn SIS noch nicht fertiggestellt ist', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'completed_at' => null,
    ]);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHas('error');

    expect($resident->fresh()->carePlan)->toBeNull();
    Queue::assertNothingPushed();
});

it('Generation-Start scheitert wenn KI nicht verfuegbar ist', function (): void {
    app()->bind(AiHealthService::class, fn () => FakeAiHealthService::unavailable());

    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHas('error');

    expect($resident->fresh()->carePlan)->toBeNull();
    Queue::assertNothingPushed();
});

it('Pflegekraft darf MP-Generation NICHT starten', function (): void {
    [$location, $resident, $sis, $_] = setupResidentWithCompletedSis();

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->post(route('residents.care-plan.generate.start', $resident))
        ->assertForbidden();
});

it('Polling-Endpoint liefert Status-JSON', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $generation = CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'running',
        'progress' => 5,
        'total_steps' => 17,
    ]);

    $this->actingAs($pdl)
        ->getJson(route('residents.care-plan.generate.show', [$resident, $generation]))
        ->assertOk()
        ->assertJson([
            'id' => $generation->id,
            'status' => 'running',
            'progress' => 5,
            'totalSteps' => 17,
        ]);
});

it('Polling-Endpoint 404 wenn generation zu fremdem MP gehoert', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    // Generation eines fremden MPs
    $otherCp = CarePlan::factory()->create();
    $foreignGeneration = CarePlanGeneration::query()->create([
        'care_plan_id' => $otherCp->id,
        'triggered_by' => $pdl->id,
        'status' => 'completed',
    ]);

    $this->actingAs($pdl)
        ->getJson(route('residents.care-plan.generate.show', [$resident, $foreignGeneration]))
        ->assertNotFound();
});

it('GenerateCarePlanJob persistiert KI-Antworten und setzt Status completed', function (): void {
    Http::fake([
        '*/api/generate' => Http::sequence()
            ->push(['response' => 'Pflege nur zu zweit. Ansprache mit Vorname und Du.'])         // grundbotschaft
            ->push(['response' => 'Sturzprophylaxe bei jeder Mobilisation. Rollator immer in Reichweite.']) // 1 mobilitaet
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 2 ernaehrung
            ->push(['response' => 'Inkontinenzversorgung tagsueber, Hilfsmittel bereitstellen.']) // 3 kontinenz
            ->push(['response' => 'Ganzkoerperwaesche im Sitzen am Waschbecken.'])                // 4 koerperpflege
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 5 medikation
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 6 schmerz
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 7 wundversorgung
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 8 besondere bedarfslagen
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 9 sonstige therapie
            ->push(['response' => 'Brille zu jeder Mahlzeit reichen.'])                            // 10 sinneswahrnehmung
            ->push(['response' => 'Tagesablauf: 08:00 Fruehstueck, 12:00 Mittag, 18:00 Abendbrot.']) // 11 tagesstruktur
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 12 nacht
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 13 eingewoehnung
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 14 krankenhaus
            ->push(['response' => 'NICHT_RELEVANT'])                                              // 15 herausforderndes verhalten
            ->push(['response' => 'NICHT_RELEVANT']),                                             // 16 freiheitsentziehende massnahmen
    ]);

    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $generation = CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 17,
    ]);

    (new GenerateCarePlanJob($generation->id))->handle(app(CarePlanFormulator::class));

    $cp->refresh()->load('topics');
    expect($cp->grundbotschaft)->toBe('Pflege nur zu zweit. Ansprache mit Vorname und Du.')
        ->and($cp->topics)->toHaveCount(5); // 5 Themen mit Inhalt (Grundbotschaft sitzt am MP-Header)

    // Korrekte Themen wurden persistiert (Mobilitaet, Kontinenz, Koerperpflege, Sinne, Tagesstruktur)
    $persistedNumbers = $cp->topics->pluck('topic_number')->sort()->values()->all();
    expect($persistedNumbers)->toBe([1, 3, 4, 10, 11]);

    $generation->refresh();
    expect($generation->status)->toBe('completed')
        ->and($generation->progress)->toBe(17)
        ->and($generation->finished_at)->not->toBeNull();

    expect(CarePlanVersion::query()->where('care_plan_id', $cp->id)
        ->where('snapshot_reason', 'ai_generated')->exists())->toBeTrue();
});

it('GenerateCarePlanJob setzt status=failed bei Ollama-Fehler', function (): void {
    Http::fake([
        '*/api/generate' => Http::response('Service unavailable', 503),
    ]);

    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $generation = CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 17,
    ]);

    try {
        (new GenerateCarePlanJob($generation->id))->handle(app(CarePlanFormulator::class));
    } catch (Throwable $e) {
        // erwartet - der Job rethrowt fuer Retry
    }

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error_message)->not->toBeNull();
});

it('GenerateCarePlanJob ist idempotent fuer bereits abgeschlossene generations', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $generation = CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'completed',
        'progress' => 17,
        'total_steps' => 17,
    ]);

    Http::fake();

    (new GenerateCarePlanJob($generation->id))->handle(app(CarePlanFormulator::class));

    Http::assertNothingSent();

    $generation->refresh();
    expect($generation->status)->toBe('completed');
});

it('GenerateCarePlanJob bricht ab wenn SIS waehrend des Jobs unvollstaendig wird', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    // SIS-completed_at wieder zuruecksetzen (Edge-Case)
    $sis->forceFill(['completed_at' => null])->save();

    $generation = CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 17,
    ]);

    Http::fake();

    (new GenerateCarePlanJob($generation->id))->handle(app(CarePlanFormulator::class));

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error_message)->toContain('SIS');
    Http::assertNothingSent();
});

it('SIS-Show liefert carePlanExists=false wenn noch kein MP angelegt ist', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();

    $this->actingAs($pdl)
        ->get(route('residents.sis.show', $resident))
        ->assertInertia(fn ($page) => $page
            ->component('Sis/Show')
            ->where('carePlanExists', false));
});

it('SIS-Show liefert carePlanExists=true wenn MP existiert', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $this->actingAs($pdl)
        ->get(route('residents.sis.show', $resident))
        ->assertInertia(fn ($page) => $page
            ->component('Sis/Show')
            ->where('carePlanExists', true));
});

it('CarePlan-Show liefert latestGeneration im Inertia-Payload', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithCompletedSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'running',
        'progress' => 8,
        'total_steps' => 17,
    ]);

    $this->actingAs($pdl)
        ->get(route('residents.care-plan.show', $resident))
        ->assertInertia(fn ($page) => $page
            ->component('CarePlan/Show')
            ->where('latestGeneration.status', 'running')
            ->where('latestGeneration.progress', 8)
            ->where('latestGeneration.totalSteps', 17));
});
