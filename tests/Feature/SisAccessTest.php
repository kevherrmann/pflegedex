<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('laesst PDL eine SIS anlegen', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->post(route('residents.sis.store', $resident), [
            'opening_question' => 'Was bewegt Sie?',
            'topics' => [
                ['topic_number' => 1, 'content' => 'Test 1'],
                ['topic_number' => 2, 'content' => 'Test 2'],
            ],
            'risks' => [
                ['risk_kind' => 'sturz', 'is_at_risk' => true, 'needs_further_assessment' => false],
            ],
        ])
        ->assertRedirect(route('residents.sis.show', $resident));

    expect($resident->fresh()->sis)->not->toBeNull();
});

it('verbietet Pflegekraft das Anlegen', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->post(route('residents.sis.store', $resident), [])
        ->assertForbidden();
});

it('laesst Pflegekraft eine SIS lesen', function (): void {
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
        ->get(route('residents.sis.show', $resident))
        ->assertOk();
});

it('verbietet Pflegekraft das Edit-Formular zu oeffnen', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->get(route('residents.sis.edit', $resident))
        ->assertForbidden();
});

it('verbietet Admin/Putzkraft/Hausmeister jeden SIS-Zugriff', function (string $role): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $user = User::factory()->for($location)->create();
    $user->assignRole($role);
    $user->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($user)
        ->get(route('residents.sis.show', $resident))
        ->assertForbidden();
})->with(['Admin', 'Putzkraft', 'Hausmeister']);

it('verbietet PDL den Zugriff auf SIS in fremdem Wohnbereich', function (): void {
    $own = Location::factory()->create();
    $foreign = Location::factory()->create();
    $foreignResident = Resident::factory()->for($foreign)->create();

    $pdl = User::factory()->for($own)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$own->id]);

    $this->actingAs($pdl)
        ->get(route('residents.sis.show', $foreignResident))
        ->assertForbidden();
});
