<?php

declare(strict_types=1);

use App\Models\CarePlan;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-08 10:00:00');
});

function makeLocationWithCompletedSis(): array
{
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    return [$location, $resident];
}

function makePdlIn(Location $location): User
{
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return $pdl;
}

function makeNurseIn(Location $location): User
{
    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    return $nurse;
}

it('PDL kann MP anlegen wenn SIS fertiggestellt ist', function (): void {
    [$location, $resident] = makeLocationWithCompletedSis();
    $pdl = makePdlIn($location);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.store', $resident), [
            'grundbotschaft' => 'Pflege nur zu zweit.',
            'topics' => [
                ['topic_number' => 1, 'content' => 'Sturzprophylaxe: Rollator immer in Reichweite.'],
                ['topic_number' => 4, 'content' => 'Körperpflege im Sitzen, Hilfsmittel: Duschstuhl.'],
            ],
        ])
        ->assertRedirect(route('residents.care-plan.show', $resident));

    $cp = $resident->fresh()->carePlan;
    expect($cp)->not->toBeNull()
        ->and($cp->grundbotschaft)->toBe('Pflege nur zu zweit.')
        ->and($cp->next_evaluation_due?->toDateString())->toBe('2026-07-03')
        ->and($cp->topics)->toHaveCount(2);
});

it('MP-Anlage scheitert ohne SIS', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $pdl = makePdlIn($location);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.store', $resident), [
            'topics' => [],
        ])
        ->assertStatus(422);

    expect($resident->fresh()->carePlan)->toBeNull();
});

it('MP-Anlage scheitert wenn SIS noch nicht fertiggestellt', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'completed_at' => null,
    ]);

    $pdl = makePdlIn($location);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.store', $resident), ['topics' => []])
        ->assertStatus(422);
});

it('Pflegekraft darf MP NICHT anlegen', function (): void {
    [$location, $resident] = makeLocationWithCompletedSis();
    $nurse = makeNurseIn($location);

    $this->actingAs($nurse)
        ->post(route('residents.care-plan.store', $resident), ['topics' => []])
        ->assertForbidden();
});

it('Pflegekraft darf MP lesen', function (): void {
    [$location, $resident] = makeLocationWithCompletedSis();
    CarePlan::factory()->withSampleTopics()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $nurse = makeNurseIn($location);

    $this->actingAs($nurse)
        ->get(route('residents.care-plan.show', $resident))
        ->assertOk();
});

it('Pflegekraft darf MP NICHT bearbeiten', function (): void {
    [$location, $resident] = makeLocationWithCompletedSis();
    CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $nurse = makeNurseIn($location);

    $this->actingAs($nurse)
        ->patch(route('residents.care-plan.update', $resident), [
            'grundbotschaft' => 'Hack',
            'topics' => [],
        ])
        ->assertForbidden();
});

it('PDL aus fremder Location bekommt 403', function (): void {
    [$location, $resident] = makeLocationWithCompletedSis();
    $other = Location::factory()->create();
    $foreign = makePdlIn($other);

    $this->actingAs($foreign)
        ->get(route('residents.care-plan.show', $resident))
        ->assertForbidden();

    $this->actingAs($foreign)
        ->post(route('residents.care-plan.store', $resident), ['topics' => []])
        ->assertForbidden();
});

it('zweiter Anlegungsversuch redirected statt zu duplizieren', function (): void {
    [$location, $resident] = makeLocationWithCompletedSis();
    CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    $pdl = makePdlIn($location);

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.store', $resident), ['topics' => []])
        ->assertRedirect(route('residents.care-plan.show', $resident));

    expect($resident->fresh()->carePlan()->count())->toBe(1);
});
