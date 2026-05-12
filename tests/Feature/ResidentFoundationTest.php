<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('assigns residents to exactly one location with core master data', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich Nord']);

    $resident = Resident::factory()->for($location)->create([
        'first_name' => 'Erika',
        'last_name' => 'Musterfrau',
        'room_number' => '12A',
        'care_level' => 3,
    ]);

    expect($resident->location)->toBeInstanceOf(Location::class)
        ->and($resident->location->name)->toBe('Wohnbereich Nord')
        ->and($resident->full_name)->toBe('Erika Musterfrau')
        ->and($resident->room_number)->toBe('12A')
        ->and($resident->care_level)->toBe(3);
});

it('scopes active residents to a location', function () {
    $north = Location::factory()->create(['name' => 'Wohnbereich Nord']);
    $south = Location::factory()->create(['name' => 'Wohnbereich Süd']);

    Resident::factory()->count(2)->for($north)->create(['active' => true]);
    Resident::factory()->for($north)->create(['active' => false]);
    Resident::factory()->for($south)->create(['active' => true]);

    expect(Resident::query()->forLocation($north)->active()->count())->toBe(2)
        ->and(Resident::query()->forLocation($south)->active()->count())->toBe(1);
});

it('shows location-aware resident counts on the dashboard', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich Nord']);
    $user = User::factory()->for($location)->create();
    $user->assignRole('PDL');
    $user->locations()->syncWithoutDetaching([$location->id]);

    Resident::factory()->count(2)->for($location)->create(['active' => true]);
    Resident::factory()->for($location)->create(['active' => false]);

    // Dashboard nutzt jetzt nicht mehr stats.*, sondern listet im
    // recent-Block die zuletzt aufgenommenen aktiven Bewohner.
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('recent', 2) // 2 aktive, 1 inaktive Bewohner -> 2 im recent-Block
        );
});
