<?php

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('opens the staff page even when some staff roles do not exist yet', function () {
    // Bewusst nur die benoetigten Rollen anlegen. Putzkraft/Hausmeister fehlen
    // absichtlich, um den frueheren RoleDoesNotExist-500er zu reproduzieren.
    Role::findOrCreate('PDL', 'web');
    Role::findOrCreate('Pflegekraft', 'web');

    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $staff = User::factory()->for($location)->create(['name' => 'Pflege Eins']);
    $staff->assignRole('Pflegekraft');

    $this->actingAs($pdl)
        ->get('/staff')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Staff/Index')
                ->has('staffUsers', 1)
                ->where('staffUsers.0.name', 'Pflege Eins')
        );
});

it('lets the demo PDL open the staff page after seeding roster demo data', function () {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-01'])
        ->assertSuccessful();

    $pdl = User::query()
        ->where('email', 'demo.pdl.dienstplan@pflegedex.local')
        ->firstOrFail();

    // Beide Wohnbereiche zusammen: je 12 Pflegepersonen (inkl. WBL) + je 1
    // Putzkraft = 26, dazu 1 Hausmeister = 27. Die PDL selbst ist kein Personal.
    $this->actingAs($pdl)
        ->get('/staff')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Staff/Index')
                ->has('staffUsers', 27)
        );
});
