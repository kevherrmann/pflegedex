<?php

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

it('allows only admins to open the PDL account management page', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $pdl = User::factory()->create();
    $pdl->assignRole('PDL');

    $this->actingAs($admin)
        ->get('/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Users/Index')
            ->has('pdlUsers', 1)
        );

    $this->actingAs($pdl)->get('/users')->assertForbidden();
});

it('allows admins to create PDL accounts', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->post('/users/pdl', [
            'name' => 'Neue PDL',
            'email' => 'neue.pdl@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
        ])
        ->assertRedirect('/users');

    $user = User::query()->where('email', 'neue.pdl@pflegedex.local')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasRole('PDL'))->toBeTrue()
        ->and(Hash::check('Sicheres-Passwort1', $user->password))->toBeTrue();
});

it('prevents non admins from creating PDL accounts', function () {
    $pdl = User::factory()->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->post('/users/pdl', [
            'name' => 'Verbotene PDL',
            'email' => 'verboten@pflegedex.local',
            'password' => 'password',
        ])
        ->assertForbidden();

    expect(User::query()->where('email', 'verboten@pflegedex.local')->exists())->toBeFalse();
});
