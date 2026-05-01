<?php

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('creates the default Pflegedex roles', function () {
    $this->seed();

    expect(Role::query()->pluck('name')->all())->toContain('Admin', 'PDL', 'Pflegekraft');
});

it('assigns a user to one location and one care role', function () {
    $this->seed();

    $location = Location::factory()->create(['name' => 'Wohnbereich C']);
    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');

    expect($user->location)->toBeInstanceOf(Location::class)
        ->and($user->location->name)->toBe('Wohnbereich C')
        ->and($user->hasRole('Pflegekraft'))->toBeTrue();
});

it('can scope users to their own location', function () {
    $this->seed();

    $north = Location::factory()->create(['name' => 'Wohnbereich Nord']);
    $south = Location::factory()->create(['name' => 'Wohnbereich Süd']);

    $northUser = User::factory()->for($north)->create();
    User::factory()->for($south)->create();

    expect(User::query()->forLocation($northUser->location)->pluck('location_id')->unique()->all())
        ->toBe([$north->id]);
});
