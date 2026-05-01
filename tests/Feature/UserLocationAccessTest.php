<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('lets a user access multiple assigned locations while keeping a primary location', function () {
    $primary = Location::factory()->create(['name' => 'Wohnbereich A']);
    $secondary = Location::factory()->create(['name' => 'Wohnbereich B']);
    $unassigned = Location::factory()->create(['name' => 'Wohnbereich C']);
    $user = User::factory()->for($primary)->create();

    $user->locations()->attach($secondary);

    expect($user->fresh()->canAccessLocation($primary))->toBeTrue()
        ->and($user->fresh()->canAccessLocation($secondary))->toBeTrue()
        ->and($user->fresh()->canAccessLocation($unassigned))->toBeFalse()
        ->and($user->fresh()->accessibleLocations()->pluck('name')->all())
        ->toBe(['Wohnbereich A', 'Wohnbereich B']);
});

it('lists active residents from every location the user may access', function () {
    $primary = Location::factory()->create(['name' => 'Wohnbereich A']);
    $secondary = Location::factory()->create(['name' => 'Wohnbereich B']);
    $unassigned = Location::factory()->create(['name' => 'Wohnbereich C']);
    $user = User::factory()->for($primary)->create();
    $user->locations()->attach($secondary);

    Resident::factory()->for($primary)->create(['first_name' => 'Anna', 'last_name' => 'Areal', 'active' => true]);
    Resident::factory()->for($secondary)->create(['first_name' => 'Bernd', 'last_name' => 'Bereich', 'active' => true]);
    Resident::factory()->for($unassigned)->create(['first_name' => 'Clara', 'last_name' => 'Chaos', 'active' => true]);

    $this->actingAs($user)
        ->get('/residents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Index')
            ->has('locations', 2)
            ->where('locations.0.name', 'Wohnbereich A')
            ->where('locations.1.name', 'Wohnbereich B')
            ->where('location', null)
            ->has('residents', 2)
            ->where('residents.0.fullName', 'Anna Areal')
            ->where('residents.1.fullName', 'Bernd Bereich')
        );
});

it('can filter the residents list to one accessible location', function () {
    $primary = Location::factory()->create(['name' => 'Wohnbereich A']);
    $secondary = Location::factory()->create(['name' => 'Wohnbereich B']);
    $user = User::factory()->for($primary)->create();
    $user->locations()->attach($secondary);

    Resident::factory()->for($primary)->create(['first_name' => 'Anna', 'last_name' => 'Areal', 'active' => true]);
    Resident::factory()->for($secondary)->create(['first_name' => 'Bernd', 'last_name' => 'Bereich', 'active' => true]);

    $this->actingAs($user)
        ->get('/residents?location_id='.$secondary->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Index')
            ->where('location.name', 'Wohnbereich B')
            ->has('residents', 1)
            ->where('residents.0.fullName', 'Bernd Bereich')
        );
});

it('does not allow creating residents in an unassigned location', function () {
    $primary = Location::factory()->create();
    $secondary = Location::factory()->create();
    $unassigned = Location::factory()->create();
    $user = User::factory()->for($primary)->create();
    $user->locations()->attach($secondary);

    $this->actingAs($user)
        ->post('/residents', [
            'first_name' => 'Clara',
            'last_name' => 'Chaos',
            'location_id' => $unassigned->id,
        ])
        ->assertSessionHasErrors(['location_id']);

    expect(Resident::count())->toBe(0);
});

it('counts active residents from every accessible location on the dashboard', function () {
    $primary = Location::factory()->create(['name' => 'Wohnbereich A']);
    $secondary = Location::factory()->create(['name' => 'Wohnbereich B']);
    $unassigned = Location::factory()->create();
    $user = User::factory()->for($primary)->create();
    $user->locations()->attach($secondary);

    Resident::factory()->for($primary)->create(['active' => true]);
    Resident::factory()->for($secondary)->create(['active' => true]);
    Resident::factory()->for($unassigned)->create(['active' => true]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.locationName', '2 Wohnbereiche')
            ->where('stats.residentsActive', 2)
        );
});

it('stores a new resident in the selected accessible location', function () {
    $primary = Location::factory()->create();
    $secondary = Location::factory()->create();
    $user = User::factory()->for($primary)->create();
    $user->locations()->attach($secondary);

    $this->actingAs($user)
        ->post('/residents', [
            'first_name' => 'Bernd',
            'last_name' => 'Bereich',
            'location_id' => $secondary->id,
        ])
        ->assertRedirect('/residents?location_id='.$secondary->id);

    $this->assertDatabaseHas('residents', [
        'location_id' => $secondary->id,
        'first_name' => 'Bernd',
        'last_name' => 'Bereich',
        'active' => true,
    ]);
});
