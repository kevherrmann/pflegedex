<?php

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can run the database seeder multiple times without duplicating local admin data', function () {
    $this->seed();
    $this->seed();

    expect(Location::query()->where('name', 'Wohnbereich A')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@pflegedex.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'pdl@pflegedex.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@pflegedex.local')->first()?->hasRole('Admin'))->toBeTrue()
        ->and(User::query()->where('email', 'pdl@pflegedex.local')->first()?->hasRole('PDL'))->toBeTrue();
});
