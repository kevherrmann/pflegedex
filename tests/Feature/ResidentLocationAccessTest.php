<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('shows PDL users only residents from accessible Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create(['name' => 'Wohnbereich A']);
    $foreignLocation = Location::factory()->create(['name' => 'Wohnbereich B']);

    $ownResident = Resident::factory()->for($ownLocation)->create([
        'first_name' => 'Eigene',
        'last_name' => 'Bewohnerin',
        'active' => true,
    ]);

    Resident::factory()->for($foreignLocation)->create([
        'first_name' => 'Fremde',
        'last_name' => 'Bewohnerin',
        'active' => true,
    ]);

    $pdl = User::factory()->for($ownLocation)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$ownLocation->id]);

    $this->actingAs($pdl)
    ->get('/residents')
    ->assertOk()
    ->assertInertia(fn (Assert $page) => $page
    ->component('Residents/Index')
    ->has('residents', 1)
    ->where('residents.0.id', $ownResident->id)
    ->where('residents.0.fullName', 'Eigene Bewohnerin')
    );
});

it('prevents PDL users from creating residents in foreign Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create();
    $foreignLocation = Location::factory()->create();

    $pdl = User::factory()->for($ownLocation)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$ownLocation->id]);

    $this->actingAs($pdl)
    ->post('/residents', [
        'location_id' => $foreignLocation->id,
        'first_name' => 'Fremde',
        'last_name' => 'Bewohnerin',
        'birth_date' => '1940-01-01',
        'room_number' => '99',
        'care_level' => 2,
    ])
    ->assertSessionHasErrors('location_id');

    expect(
        Resident::query()->where('last_name', 'Bewohnerin')->exists()
    )->toBeFalse();
});

it('allows Pflegekraft users to view only their own Wohnbereich residents', function (): void {
    $ownLocation = Location::factory()->create(['name' => 'Wohnbereich A']);
    $foreignLocation = Location::factory()->create(['name' => 'Wohnbereich B']);

    $ownResident = Resident::factory()->for($ownLocation)->create([
        'first_name' => 'Sichtbare',
        'last_name' => 'Bewohnerin',
        'active' => true,
    ]);

    Resident::factory()->for($foreignLocation)->create([
        'first_name' => 'Unsichtbare',
        'last_name' => 'Bewohnerin',
        'active' => true,
    ]);

    $pflegekraft = User::factory()->for($ownLocation)->create();
    $pflegekraft->assignRole('Pflegekraft');
    $pflegekraft->locations()->syncWithoutDetaching([$ownLocation->id]);

    $this->actingAs($pflegekraft)
    ->get('/residents')
    ->assertOk()
    ->assertInertia(fn (Assert $page) => $page
    ->component('Residents/Index')
    ->has('residents', 1)
    ->where('residents.0.id', $ownResident->id)
    ->where('residents.0.fullName', 'Sichtbare Bewohnerin')
    );
});

it('prevents Pflegekraft users from creating residents', function (): void {
    $location = Location::factory()->create();

    $pflegekraft = User::factory()->for($location)->create();
    $pflegekraft->assignRole('Pflegekraft');
    $pflegekraft->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pflegekraft)
    ->post('/residents', [
        'location_id' => $location->id,
        'first_name' => 'Nicht',
        'last_name' => 'Erlaubt',
        'birth_date' => '1940-01-01',
        'room_number' => '1',
        'care_level' => 1,
    ])
    ->assertForbidden();

    expect(
        Resident::query()->where('last_name', 'Erlaubt')->exists()
    )->toBeFalse();
});
