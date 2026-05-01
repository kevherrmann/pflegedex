<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('can run the database seeder multiple times without duplicating local admin data', function () {
    $this->seed();
    $this->seed();

    $pdl = User::query()->where('email', 'pdl@pflegedex.local')->first();
    $carl = User::query()->where('email', 'carl@pflegedex.local')->first();
    $wohnbereichA = Location::query()->where('name', 'Wohnbereich A')->first();
    $wohnbereichB = Location::query()->where('name', 'Wohnbereich B')->first();

    expect(Location::query()->where('name', 'Wohnbereich A')->count())->toBe(1)
        ->and(Location::query()->where('name', 'Wohnbereich B')->count())->toBe(1)
        ->and(Resident::query()->where('first_name', 'Erika')->where('last_name', 'Mustermann')->count())->toBe(1)
        ->and(Resident::query()->where('first_name', 'Karl')->where('last_name', 'Beispiel')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@pflegedex.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'pdl@pflegedex.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'carl@pflegedex.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'admin@pflegedex.local')->first()?->hasRole('Admin'))->toBeTrue()
        ->and($pdl?->hasRole('PDL'))->toBeTrue()
        ->and($pdl?->locations()->pluck('locations.id')->all())->toEqualCanonicalizing([$wohnbereichA?->id, $wohnbereichB?->id])
        ->and($carl?->hasRole('Pflegekraft'))->toBeTrue()
        ->and($carl?->locations()->pluck('locations.id')->all())->toEqualCanonicalizing([$wohnbereichA?->id])
        ->and($carl?->location_id)->toBe($wohnbereichA?->id)
        ->and(Hash::check('password', $carl?->password ?? ''))->toBeTrue();
});
