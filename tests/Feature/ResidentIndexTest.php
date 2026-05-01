<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('PDL', 'web');
});

it('requires authentication before showing the residents list', function () {
    $this->get('/residents')->assertRedirect('/login');
});

it('shows only active residents from the authenticated users location', function () {
    $north = Location::factory()->create(['name' => 'Wohnbereich Nord']);
    $south = Location::factory()->create(['name' => 'Wohnbereich Süd']);
    $user = User::factory()->for($north)->create();
    $user->assignRole('PDL');

    Resident::factory()->for($north)->create([
        'first_name' => 'Erika',
        'last_name' => 'Musterfrau',
        'room_number' => '12A',
        'care_level' => 3,
        'active' => true,
    ]);
    Resident::factory()->for($north)->create([
        'first_name' => 'Heinz',
        'last_name' => 'Beispiel',
        'room_number' => '14B',
        'care_level' => 4,
        'active' => true,
    ]);
    Resident::factory()->for($north)->create([
        'first_name' => 'Archiv',
        'last_name' => 'Bewohner',
        'active' => false,
    ]);
    Resident::factory()->for($south)->create([
        'first_name' => 'Fremd',
        'last_name' => 'Bereich',
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get('/residents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Index')
            ->where('location.name', 'Wohnbereich Nord')
            ->has('residents', 2)
            ->where('residents.0.fullName', 'Heinz Beispiel')
            ->where('residents.0.roomNumber', '14B')
            ->where('residents.0.careLevel', 4)
            ->where('residents.1.fullName', 'Erika Musterfrau')
            ->where('residents.1.roomNumber', '12A')
            ->where('residents.1.careLevel', 3)
        );
});

it('shows an empty residents list if the user has no location yet', function () {
    $user = User::factory()->create(['location_id' => null]);
    $user->assignRole('PDL');

    $this->actingAs($user)
        ->get('/residents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Index')
            ->where('location', null)
            ->has('residents', 0)
        );
});
