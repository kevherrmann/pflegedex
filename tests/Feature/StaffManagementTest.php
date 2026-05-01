<?php

use App\Models\Location;
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

it('allows only PDL users to open staff management', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $staff = User::factory()->for($location)->create(['name' => 'Pflege Eins']);
    $staff->assignRole('Pflegekraft');

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($pdl)
        ->get('/staff')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Staff/Index')
            ->has('staffUsers', 1)
            ->has('locations', 1)
            ->where('staffUsers.0.name', 'Pflege Eins')
        );

    $this->actingAs($admin)->get('/staff')->assertForbidden();
});

it('lets PDL users create operational staff for accessible Wohnbereiche only', function () {
    $first = Location::factory()->create(['name' => 'Wohnbereich A']);
    $second = Location::factory()->create(['name' => 'Wohnbereich B']);
    $foreign = Location::factory()->create(['name' => 'Wohnbereich Fremd']);
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Neue Pflegekraft',
            'email' => 'pflege@pflegedex.local',
            'password' => 'sicheres-passwort',
            'role' => 'Pflegekraft',
            'location_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect('/staff');

    $staff = User::query()->where('email', 'pflege@pflegedex.local')->first();

    expect($staff)->not->toBeNull()
        ->and($staff->hasRole('Pflegekraft'))->toBeTrue()
        ->and($staff->location_id)->toBe($first->id)
        ->and($staff->locations()->pluck('locations.id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and(Hash::check('sicheres-passwort', $staff->password))->toBeTrue();

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Fremde Pflegekraft',
            'email' => 'fremd@pflegedex.local',
            'password' => 'sicheres-passwort',
            'role' => 'Pflegekraft',
            'location_ids' => [$foreign->id],
        ])
        ->assertSessionHasErrors('location_ids');

    expect(User::query()->where('email', 'fremd@pflegedex.local')->exists())->toBeFalse();
});

it('prevents PDL users from creating admin or PDL accounts through staff management', function () {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    foreach (['Admin', 'PDL'] as $role) {
        $this->actingAs($pdl)
            ->post('/staff', [
                'name' => 'Verboten '.$role,
                'email' => strtolower($role).'@pflegedex.local',
                'password' => 'sicheres-passwort',
                'role' => $role,
                'location_ids' => [$location->id],
            ])
            ->assertSessionHasErrors('role');
    }
});

it('lets PDL users edit staff in their Wohnbereiche', function () {
    $first = Location::factory()->create(['name' => 'Wohnbereich A']);
    $second = Location::factory()->create(['name' => 'Wohnbereich B']);
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);

    $staff = User::factory()->for($first)->create(['name' => 'Alter Name', 'email' => 'alt@pflegedex.local']);
    $staff->assignRole('Pflegekraft');
    $staff->locations()->attach([$first->id]);

    $this->actingAs($pdl)
        ->get('/staff/'.$staff->id.'/edit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Staff/Edit')
            ->where('staffUser.name', 'Alter Name')
            ->has('locations', 2)
        );

    $this->actingAs($pdl)
        ->patch('/staff/'.$staff->id, [
            'name' => 'Neuer Name',
            'email' => 'neu@pflegedex.local',
            'password' => 'neues-passwort',
            'role' => 'Hausmeister',
            'location_ids' => [$second->id],
        ])
        ->assertRedirect('/staff');

    $staff->refresh();

    expect($staff->name)->toBe('Neuer Name')
        ->and($staff->email)->toBe('neu@pflegedex.local')
        ->and($staff->hasRole('Hausmeister'))->toBeTrue()
        ->and($staff->location_id)->toBe($second->id)
        ->and($staff->locations()->pluck('locations.id')->all())->toEqualCanonicalizing([$second->id])
        ->and(Hash::check('neues-passwort', $staff->password))->toBeTrue();
});

it('prevents PDL users from editing staff outside their Wohnbereiche', function () {
    $own = Location::factory()->create();
    $foreign = Location::factory()->create();
    $pdl = User::factory()->for($own)->create();
    $pdl->assignRole('PDL');

    $staff = User::factory()->for($foreign)->create();
    $staff->assignRole('Pflegekraft');
    $staff->locations()->attach([$foreign->id]);

    $this->actingAs($pdl)->get('/staff/'.$staff->id.'/edit')->assertForbidden();

    $this->actingAs($pdl)
        ->patch('/staff/'.$staff->id, [
            'name' => 'Verboten',
            'email' => 'verboten@pflegedex.local',
            'role' => 'Pflegekraft',
            'location_ids' => [$own->id],
        ])
        ->assertForbidden();
});
