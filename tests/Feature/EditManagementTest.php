<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('lets admins edit existing PDL accounts', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $pdl = User::factory()->create(['name' => 'Alte PDL', 'email' => 'alt@pflegedex.local']);
    $pdl->assignRole('PDL');

    $this->actingAs($admin)
        ->get('/users/'.$pdl->id.'/edit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Users/Edit')
            ->where('pdlUser.name', 'Alte PDL')
        );

    $this->actingAs($admin)
        ->patch('/users/'.$pdl->id, [
            'name' => 'Neue PDL',
            'email' => 'neu@pflegedex.local',
            'password' => 'Neues-Passwort1',
        ])
        ->assertRedirect('/users');

    $pdl->refresh();

    expect($pdl->name)->toBe('Neue PDL')
        ->and($pdl->email)->toBe('neu@pflegedex.local')
        ->and($pdl->hasRole('PDL'))->toBeTrue()
        ->and(Hash::check('Neues-Passwort1', $pdl->password))->toBeTrue();
});

it('lets PDL users edit Wohnbereiche', function () {
    $pdl = User::factory()->create();
    $pdl->assignRole('PDL');
    $location = Location::factory()->create([
        'name' => 'Wohnbereich Alt',
        'short_name' => 'ALT',
        'description' => 'Alte Beschreibung',
    ]);

    $this->actingAs($pdl)
        ->get('/locations/'.$location->id.'/edit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Locations/Edit')
            ->where('location.name', 'Wohnbereich Alt')
        );

    $this->actingAs($pdl)
        ->patch('/locations/'.$location->id, [
            'name' => 'Wohnbereich Neu',
            'short_name' => 'NEU',
            'description' => 'Neue Beschreibung',
        ])
        ->assertRedirect('/locations');

    $this->assertDatabaseHas('locations', [
        'id' => $location->id,
        'name' => 'Wohnbereich Neu',
        'short_name' => 'NEU',
        'description' => 'Neue Beschreibung',
    ]);
});

it('lets PDL users edit residents and move them to another accessible Wohnbereich', function () {
    $first = Location::factory()->create(['name' => 'Wohnbereich A']);
    $second = Location::factory()->create(['name' => 'Wohnbereich B']);
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);
    $resident = Resident::factory()->for($first)->create([
        'first_name' => 'Erika',
        'last_name' => 'Alt',
        'room_number' => '1A',
        'care_level' => 2,
    ]);

    $this->actingAs($pdl)
        ->get('/residents/'.$resident->id.'/edit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Edit')
            ->where('resident.fullName', 'Erika Alt')
            ->has('locations', 2)
        );

    $this->actingAs($pdl)
        ->patch('/residents/'.$resident->id, [
            'salutation' => 'frau',
            'location_id' => $second->id,
            'first_name' => 'Erika',
            'last_name' => 'Neu',
            'birth_date' => '1938-05-12',
            'room_number' => '2B',
            'care_level' => 3,
        ])
        ->assertRedirect('/residents?location_id='.$second->id);

    $this->assertDatabaseHas('residents', [
        'id' => $resident->id,
        'location_id' => $second->id,
        'first_name' => 'Erika',
        'last_name' => 'Neu',
        'room_number' => '2B',
        'care_level' => 3,
    ]);
});

it('prevents editing residents outside the PDL users Wohnbereiche', function () {
    $own = Location::factory()->create();
    $foreign = Location::factory()->create();
    $pdl = User::factory()->for($own)->create();
    $pdl->assignRole('PDL');
    $resident = Resident::factory()->for($foreign)->create();

    $this->actingAs($pdl)->get('/residents/'.$resident->id.'/edit')->assertForbidden();

    $this->actingAs($pdl)
        ->patch('/residents/'.$resident->id, [
            'location_id' => $own->id,
            'first_name' => 'Verboten',
            'last_name' => 'Bewohner',
        ])
        ->assertForbidden();
});
