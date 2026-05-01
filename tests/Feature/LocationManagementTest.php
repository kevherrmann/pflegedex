<?php

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires authentication before showing locations', function () {
    $this->get('/locations')->assertRedirect('/login');
});

it('shows active locations and whether the authenticated user has access', function () {
    $primary = Location::factory()->create(['name' => 'Wohnbereich A', 'short_name' => 'A']);
    $secondary = Location::factory()->create(['name' => 'Wohnbereich B', 'short_name' => 'B']);
    Location::factory()->create(['name' => 'Archiv', 'active' => false]);
    $user = User::factory()->for($primary)->create();
    $user->locations()->attach($secondary);

    $this->actingAs($user)
        ->get('/locations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Locations/Index')
            ->has('locations', 2)
            ->where('locations.0.name', 'Wohnbereich A')
            ->where('locations.0.shortName', 'A')
            ->where('locations.0.userHasAccess', true)
            ->where('locations.1.name', 'Wohnbereich B')
            ->where('locations.1.userHasAccess', true)
        );
});

it('stores a new active location and assigns it to the current user', function () {
    $primary = Location::factory()->create();
    $user = User::factory()->for($primary)->create();

    $this->actingAs($user)
        ->post('/locations', [
            'name' => 'Wohnbereich C',
            'short_name' => 'C',
            'description' => 'Demenzbereich im Erdgeschoss',
        ])
        ->assertRedirect('/locations');

    $location = Location::query()->where('name', 'Wohnbereich C')->first();

    expect($location)->not->toBeNull()
        ->and($location->active)->toBeTrue()
        ->and($user->fresh()->canAccessLocation($location))->toBeTrue();
});

it('uses the first created location as primary location if the user has none yet', function () {
    $user = User::factory()->create(['location_id' => null]);

    $this->actingAs($user)
        ->post('/locations', [
            'name' => 'Wohnbereich Start',
        ])
        ->assertRedirect('/locations');

    $location = Location::query()->where('name', 'Wohnbereich Start')->firstOrFail();

    expect($user->fresh()->location_id)->toBe($location->id)
        ->and($user->fresh()->canAccessLocation($location))->toBeTrue();
});

it('validates location data before storing', function () {
    $existing = Location::factory()->create(['name' => 'Wohnbereich A']);
    $user = User::factory()->for($existing)->create();

    $this->actingAs($user)
        ->post('/locations', [
            'name' => 'Wohnbereich A',
            'short_name' => str_repeat('X', 51),
        ])
        ->assertSessionHasErrors(['name', 'short_name']);
});
