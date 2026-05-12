<?php

declare(strict_types=1);

use App\Enums\SisRiskKind;
use App\Enums\SisTopic;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisRisk;
use App\Models\SisTopicEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-05 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('legt beim Anlegen alle 6 Themenfelder und alle 6 Risiken an', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.store', $resident), [
            'opening_question' => 'Was bewegt Sie?',
            'topics' => [],
            'risks' => [],
        ])
        ->assertRedirect();

    $sis = Sis::query()->where('resident_id', $resident->id)->firstOrFail();

    expect($sis->topicEntries()->count())->toBe(6)
        ->and($sis->topicEntries()->pluck('topic_number')->sort()->values()->all())->toBe(SisTopic::numbers())
        ->and($sis->risks()->count())->toBe(6)
        ->and($sis->risks()->pluck('risk_kind')->sort()->values()->all())
        ->toBe(collect(SisRiskKind::values())->sort()->values()->all());
});

it('schreibt einen Versions-Snapshot beim Anlegen', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.store', $resident), [
            'opening_question' => 'Erste Aufnahme',
            'topics' => [['topic_number' => 1, 'content' => 'Aufmerksam']],
            'risks' => [['risk_kind' => 'sturz', 'is_at_risk' => true, 'needs_further_assessment' => false]],
        ])
        ->assertRedirect();

    $sis = Sis::query()->where('resident_id', $resident->id)->firstOrFail();

    expect($sis->versions()->count())->toBe(1)
        ->and($sis->versions()->first()->snapshot_reason)->toBe('created');

    $snapshot = json_decode($sis->versions()->first()->content_snapshot, true);
    expect($snapshot['opening_question'])->toBe('Erste Aufnahme')
        ->and($snapshot['topics'])->toHaveCount(6);
});

it('schreibt einen Versions-Snapshot beim Update mit alten Werten', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'opening_question' => 'Alter Text',
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->patch(route('residents.sis.update', $resident), [
            'opening_question' => 'Neuer Text',
            'topics' => [],
            'risks' => [],
        ])
        ->assertRedirect();

    $latest = $sis->versions()->latest('created_at')->first();
    expect($latest)->not->toBeNull()
        ->and($latest->snapshot_reason)->toBe('updated');

    $oldSnapshot = json_decode($latest->content_snapshot, true);
    expect($oldSnapshot['opening_question'])->toBe('Alter Text');

    expect($sis->fresh()->opening_question)->toBe('Neuer Text');
});

it('setzt completed_at und next_evaluation_due NICHT mehr ueber update (nur ueber complete-Button)', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'completed_at' => null,
        'next_evaluation_due' => null,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    // completed_at im Form-Payload wird ignoriert (kein Validation-Error,
    // weil das Feld nicht mehr Teil der Validation ist)
    $this->actingAs($pdl)
        ->patch(route('residents.sis.update', $resident), [
            'completed_at' => '2026-05-04',
            'opening_question' => 'Update-Versuch',
            'topics' => [],
            'risks' => [],
        ])
        ->assertRedirect();

    $sis->refresh();
    expect($sis->completed_at)->toBeNull()
        ->and($sis->next_evaluation_due)->toBeNull()
        ->and($sis->opening_question)->toBe('Update-Versuch');
});

it('setzt bei Evaluation evaluated_at und next_evaluation_due', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.evaluate', $resident))
        ->assertRedirect();

    $sis->refresh();
    expect($sis->evaluated_at?->toDateString())->toBe('2026-05-05')
        ->and($sis->next_evaluation_due?->toDateString())->toBe('2026-06-30')
        ->and($sis->versions()->where('snapshot_reason', 'evaluated')->exists())->toBeTrue();
});

it('erkennt ueberfaellige SIS', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->overdue()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    expect($sis->isOverdue())->toBeTrue();

    $current = Sis::factory()->completed()->create([
        'resident_id' => Resident::factory()->for($location)->create()->id,
        'location_id' => $location->id,
    ]);
    expect($current->isOverdue())->toBeFalse();
});

it('verhindert dass eine zweite SIS fuer den gleichen Bewohner angelegt wird', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    // Zweiter store-Aufruf -> soll nicht zweite SIS anlegen, sondern auf show redirecten
    $this->actingAs($pdl)
        ->post(route('residents.sis.store', $resident), [
            'opening_question' => 'Sollte nicht durchkommen',
            'topics' => [],
            'risks' => [],
        ])
        ->assertRedirect(route('residents.sis.show', $resident));

    expect(Sis::query()->where('resident_id', $resident->id)->count())->toBe(1);
});
