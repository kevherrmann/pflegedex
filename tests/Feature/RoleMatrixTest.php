<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('allows admin users to create PDL accounts', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
    ->post('/users/pdl', [
        'name' => 'PDL Eins',
        'email' => 'pdl.eins@pflegedex.local',
        'password' => 'sicheres-passwort',
    ])
    ->assertRedirect('/users');

    $pdl = User::query()
    ->where('email', 'pdl.eins@pflegedex.local')
    ->firstOrFail();

    expect($pdl->hasRole('PDL'))->toBeTrue()
    ->and(Hash::check('sicheres-passwort', $pdl->password))->toBeTrue();
});

it('prevents PDL users from creating other PDL accounts', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
    ->post('/users/pdl', [
        'name' => 'Verbotene PDL',
        'email' => 'verboten.pdl@pflegedex.local',
        'password' => 'sicheres-passwort',
    ])
    ->assertForbidden();

    expect(
        User::query()->where('email', 'verboten.pdl@pflegedex.local')->exists()
    )->toBeFalse();
});

it('allows PDL users to create residents in their accessible Wohnbereich', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
    ->post('/residents', [
        'location_id' => $location->id,
        'first_name' => 'Erika',
        'last_name' => 'Mustermann',
        'birth_date' => '1942-03-15',
        'room_number' => '12',
        'care_level' => 3,
    ])
    ->assertRedirect('/residents?location_id='.$location->id);

    $resident = Resident::query()
    ->where('last_name', 'Mustermann')
    ->firstOrFail();

    expect($resident->location_id)->toBe($location->id)
    ->and($resident->pseudonym)->toStartWith('P-');
});

it('allows PDL users to create operational staff but not Admin or PDL accounts', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
    ->post('/staff', [
        'name' => 'Pflege Eins',
        'email' => 'pflege.eins@pflegedex.local',
        'password' => 'sicheres-passwort',
        'role' => 'Pflegekraft',
        'location_ids' => [$location->id],
    ])
    ->assertRedirect('/staff');

    $staff = User::query()
    ->where('email', 'pflege.eins@pflegedex.local')
    ->firstOrFail();

    expect($staff->hasRole('Pflegekraft'))->toBeTrue();

    foreach (['Admin', 'PDL'] as $forbiddenRole) {
        $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Verboten '.$forbiddenRole,
            'email' => strtolower($forbiddenRole).'@pflegedex.local',
               'password' => 'sicheres-passwort',
               'role' => $forbiddenRole,
               'location_ids' => [$location->id],
        ])
        ->assertSessionHasErrors('role');
    }
});
