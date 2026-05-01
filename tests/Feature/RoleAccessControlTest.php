<?php

use App\Models\Location;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('allows only PDL users to manage Wohnbereiche', function () {
    $pdl = User::factory()->create();
    $admin = User::factory()->create();
    $caregiver = User::factory()->create();

    $pdl->assignRole('PDL');
    $admin->assignRole('Admin');
    $caregiver->assignRole('Pflegekraft');

    $this->actingAs($pdl)->get('/locations')->assertOk();
    $this->actingAs($admin)->get('/locations')->assertForbidden();
    $this->actingAs($caregiver)->get('/locations')->assertForbidden();
});

it('prevents non PDL users from creating Wohnbereiche', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->post('/locations', ['name' => 'Wohnbereich Admin'])
        ->assertForbidden();

    expect(Location::query()->where('name', 'Wohnbereich Admin')->exists())->toBeFalse();
});

it('seeds the planned operational roles', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::query()->where('name', 'Admin')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'PDL')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'Pflegekraft')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'Putzkraft')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'Hausmeister')->exists())->toBeTrue();
});
