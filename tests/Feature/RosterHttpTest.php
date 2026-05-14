<?php

use App\Enums\RosterStatus;
use App\Models\Location;
use App\Models\Roster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function createRosterHttpUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createRosterHttpRoster(Location $location, User $createdBy, array $attributes = []): Roster
{
    return Roster::query()->create([
        'location_id' => $location->id,
        'year' => $attributes['year'] ?? 2027,
        'month' => $attributes['month'] ?? 1,
        'status' => $attributes['status'] ?? RosterStatus::Draft,
        'generated_at' => $attributes['generated_at'] ?? null,
        'published_at' => $attributes['published_at'] ?? null,
        'created_by' => $createdBy->id,
    ]);
}

it('shows the rosters page to PDL users', function (): void {
    $pdl = createRosterHttpUser('PDL');

    $this->actingAs($pdl)
        ->get('/rosters')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Index')
        );
});

it('blocks non PDL users from viewing the rosters page', function (): void {
    $user = createRosterHttpUser('Pflegekraft');

    $this->actingAs($user)
        ->get('/rosters')
        ->assertForbidden();
});

it('passes locations and rosters to inertia', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create([
        'name' => 'Wohnbereich A',
    ]);
    $createdBy = User::factory()->create([
        'name' => 'PDL Beispiel',
    ]);
    $roster = createRosterHttpRoster($location, $createdBy, [
        'year' => 2028,
        'month' => 3,
        'status' => RosterStatus::Reviewed,
    ]);

    $this->actingAs($pdl)
        ->get('/rosters')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Index')
                ->where('locations.0.id', $location->id)
                ->where('locations.0.name', 'Wohnbereich A')
                ->where('rosters.0.id', $roster->id)
                ->where('rosters.0.locationId', $location->id)
                ->where('rosters.0.locationName', 'Wohnbereich A')
                ->where('rosters.0.year', 2028)
                ->where('rosters.0.month', 3)
                ->where('rosters.0.status', 'reviewed')
                ->where('rosters.0.statusLabel', 'Geprüft')
                ->where('rosters.0.isEditable', true)
                ->where('rosters.0.isPublished', false)
                ->where('rosters.0.generatedAt', null)
                ->where('rosters.0.publishedAt', null)
                ->where('rosters.0.createdByName', 'PDL Beispiel')
                ->where('rosters.0.shiftsCount', 0)
                ->has('rosters.0.createdAt')
        );
});

it('lets PDL users create a monthly roster', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post('/rosters', [
            'location_id' => $location->id,
            'year' => 2027,
            'month' => 4,
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'roster-created');

    $roster = Roster::query()->firstOrFail();

    expect($roster->location_id)->toBe($location->id)
        ->and($roster->year)->toBe(2027)
        ->and($roster->month)->toBe(4)
        ->and($roster->status)->toBe(RosterStatus::Draft)
        ->and($roster->created_by)->toBe($pdl->id);
});

it('does not create duplicates when the same month is created again', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $existing = createRosterHttpRoster($location, $pdl, [
        'year' => 2027,
        'month' => 5,
        'status' => RosterStatus::Reviewed,
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post('/rosters', [
            'location_id' => $location->id,
            'year' => 2027,
            'month' => 5,
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'roster-created');

    expect(Roster::query()->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(RosterStatus::Reviewed);
});

it('lets PDL users publish a draft roster', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/publish")
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'roster-published');

    expect($roster->refresh()->status)->toBe(RosterStatus::Published)
        ->and($roster->published_at)->not->toBeNull();
});

it('lets PDL users lock a published roster', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl, [
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/lock")
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'roster-locked');

    expect($roster->refresh()->status)->toBe(RosterStatus::Locked);
});

it('lets PDL users reopen a published roster', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl, [
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/reopen")
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'roster-reopened');

    expect($roster->refresh()->status)->toBe(RosterStatus::Reviewed)
        ->and($roster->published_at)->toBeNull();
});

it('blocks non PDL users from creating rosters', function (): void {
    $user = createRosterHttpUser('Pflegekraft');
    $location = Location::factory()->create();

    $this->actingAs($user)
        ->post('/rosters', [
            'location_id' => $location->id,
            'year' => 2027,
            'month' => 4,
        ])
        ->assertForbidden();

    expect(Roster::query()->count())->toBe(0);
});

it('blocks non PDL users from changing roster status', function (): void {
    $user = createRosterHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $draftRoster = createRosterHttpRoster($location, $createdBy, [
        'month' => 1,
        'status' => RosterStatus::Draft,
    ]);
    $publishedRoster = createRosterHttpRoster($location, $createdBy, [
        'month' => 2,
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);
    $reopenRoster = createRosterHttpRoster($location, $createdBy, [
        'month' => 3,
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch("/rosters/{$draftRoster->id}/publish")
        ->assertForbidden();

    $this->actingAs($user)
        ->patch("/rosters/{$publishedRoster->id}/lock")
        ->assertForbidden();

    $this->actingAs($user)
        ->patch("/rosters/{$reopenRoster->id}/reopen")
        ->assertForbidden();

    expect($draftRoster->refresh()->status)->toBe(RosterStatus::Draft)
        ->and($publishedRoster->refresh()->status)->toBe(RosterStatus::Published)
        ->and($reopenRoster->refresh()->status)->toBe(RosterStatus::Published);
});

it('returns a session error for invalid months', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post('/rosters', [
            'location_id' => $location->id,
            'year' => 2027,
            'month' => 13,
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('month');
});

it('returns a session error for invalid years', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post('/rosters', [
            'location_id' => $location->id,
            'year' => 2101,
            'month' => 1,
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('year');
});
