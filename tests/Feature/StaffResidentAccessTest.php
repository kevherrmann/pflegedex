<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('lets Pflegekraft users view residents from assigned Wohnbereiche', function () {
    $own = Location::factory()->create(['name' => 'Wohnbereich Pflege']);
    $foreign = Location::factory()->create(['name' => 'Wohnbereich Fremd']);

    $pflegekraft = User::factory()->for($own)->create();
    $pflegekraft->assignRole('Pflegekraft');
    $pflegekraft->locations()->attach($own->id);

    Resident::factory()->for($own)->create(['first_name' => 'Erika', 'last_name' => 'Pflege']);
    Resident::factory()->for($foreign)->create(['first_name' => 'Fremde', 'last_name' => 'Person']);

    $this->actingAs($pflegekraft)
        ->get('/residents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Index')
            ->has('residents', 1)
            ->where('residents.0.fullName', 'Erika Pflege')
            ->where('auth.permissions.viewResidents', true)
            ->where('auth.permissions.manageResidents', false)
        );
});

it('keeps resident creation and editing PDL-only', function () {
    $location = Location::factory()->create();
    $pflegekraft = User::factory()->for($location)->create();
    $pflegekraft->assignRole('Pflegekraft');
    $pflegekraft->locations()->attach($location->id);
    $resident = Resident::factory()->for($location)->create(['first_name' => 'Erika']);

    $this->actingAs($pflegekraft)->get('/residents/create')->assertForbidden();

    $this->actingAs($pflegekraft)
        ->post('/residents', [
            'location_id' => $location->id,
            'first_name' => 'Neue',
            'last_name' => 'Person',
        ])
        ->assertForbidden();

    $this->actingAs($pflegekraft)->get('/residents/'.$resident->id.'/edit')->assertForbidden();

    $this->actingAs($pflegekraft)
        ->patch('/residents/'.$resident->id, [
            'location_id' => $location->id,
            'first_name' => 'Geändert',
            'last_name' => 'Person',
        ])
        ->assertForbidden();

    expect(Resident::query()->where('first_name', 'Neue')->exists())->toBeFalse()
        ->and($resident->refresh()->first_name)->toBe('Erika');
});

it('does not expose resident lists to Putzkraft or Hausmeister roles', function (string $role) {
    $location = Location::factory()->create();
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);
    $user->locations()->attach($location->id);

    $this->actingAs($user)->get('/residents')->assertForbidden();
})->with(['Putzkraft', 'Hausmeister']);
