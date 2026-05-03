<?php

use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('vergibt automatisch ein Pseudonym beim Anlegen ueber den Controller', function () {
    $this->seed();

    $pdl = User::query()->where('email', 'pdl@pflegedex.local')->firstOrFail();
    $location = $pdl->accessibleLocations()->first();

    $this->actingAs($pdl)
        ->post('/residents', [
            'first_name' => 'Test',
            'last_name' => 'Bewohner',
            'location_id' => $location->id,
        ])
        ->assertRedirect();

    $resident = Resident::query()
        ->where('first_name', 'Test')
        ->where('last_name', 'Bewohner')
        ->first();

    expect($resident)->not->toBeNull()
        ->and($resident->pseudonym)->toMatch('/^P-\d{4}-\d{4}$/');
});

it('zaehlt das Pseudonym pro Jahr fortlaufend hoch', function () {
    $this->seed();

    $year = (int) now()->format('Y');

    // Seeder vergibt P-YYYY-0001 und P-YYYY-0002
    expect(Resident::generatePseudonym($year))->toBe('P-'.$year.'-0003');
});
