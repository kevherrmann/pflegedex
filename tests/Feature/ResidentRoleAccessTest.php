<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('prevents admins from managing residents', function () {
    $location = Location::factory()->create();
    $admin = User::factory()->for($location)->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)->get('/residents')->assertForbidden();
    $this->actingAs($admin)->get('/residents/create')->assertForbidden();

    $this->actingAs($admin)
        ->post('/residents', [
            'first_name' => 'Admin',
            'last_name' => 'Bewohner',
            'location_id' => $location->id,
        ])
        ->assertForbidden();

    expect(Resident::query()->count())->toBe(0);
});

it('allows PDL users to manage residents after a Wohnbereich exists', function () {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach($location);

    $this->actingAs($pdl)->get('/residents')->assertOk();
    $this->actingAs($pdl)->get('/residents/create')->assertOk();

    $this->actingAs($pdl)
        ->post('/residents', [
            'first_name' => 'Erika',
            'last_name' => 'Musterfrau',
            'location_id' => $location->id,
        ])
        ->assertRedirect('/residents?location_id='.$location->id);

    $this->assertDatabaseHas('residents', [
        'location_id' => $location->id,
        'first_name' => 'Erika',
        'last_name' => 'Musterfrau',
    ]);
});

it('requires selecting an accessible Wohnbereich before storing a resident', function () {
    $first = Location::factory()->create();
    $second = Location::factory()->create();
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);

    $this->actingAs($pdl)
        ->post('/residents', [
            'first_name' => 'Ohne',
            'last_name' => 'Wohnbereich',
        ])
        ->assertSessionHasErrors(['location_id']);

    expect(Resident::query()->count())->toBe(0);
});
