<?php

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires authentication before showing the resident create form', function () {
    $this->get('/residents/create')->assertRedirect('/login');
});

it('shows a resident create form for the authenticated users location', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich Nord']);
    $user = User::factory()->for($location)->create();

    $this->actingAs($user)
        ->get('/residents/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Residents/Create')
            ->where('location.name', 'Wohnbereich Nord')
        );
});

it('stores a resident in the authenticated users location', function () {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $user = User::factory()->for($location)->create();

    $this->actingAs($user)
        ->post('/residents', [
            'first_name' => 'Erika',
            'last_name' => 'Musterfrau',
            'birth_date' => '1938-05-12',
            'room_number' => '12A',
            'care_level' => 3,
            'location_id' => $otherLocation->id,
        ])
        ->assertRedirect('/residents');

    $this->assertDatabaseHas('residents', [
        'location_id' => $location->id,
        'first_name' => 'Erika',
        'last_name' => 'Musterfrau',
        'room_number' => '12A',
        'care_level' => 3,
        'active' => true,
    ]);

    expect(Resident::first()?->birth_date?->toDateString())->toBe('1938-05-12');
});

it('requires a location before storing a resident', function () {
    $user = User::factory()->create(['location_id' => null]);

    $this->actingAs($user)
        ->post('/residents', [
            'first_name' => 'Erika',
            'last_name' => 'Musterfrau',
        ])
        ->assertRedirect('/residents')
        ->assertSessionHas('warning', 'Bitte ordne deinem Konto zuerst einen Wohnbereich zu.');

    expect(Resident::count())->toBe(0);
});

it('validates resident master data before storing', function () {
    $location = Location::factory()->create();
    $user = User::factory()->for($location)->create();

    $this->actingAs($user)
        ->post('/residents', [
            'first_name' => '',
            'last_name' => '',
            'birth_date' => now()->addDay()->toDateString(),
            'room_number' => str_repeat('A', 51),
            'care_level' => 6,
        ])
        ->assertSessionHasErrors(['first_name', 'last_name', 'birth_date', 'room_number', 'care_level']);

    expect(Resident::count())->toBe(0);
});
