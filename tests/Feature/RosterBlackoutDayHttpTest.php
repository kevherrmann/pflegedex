<?php

use App\Enums\BlackoutScope;
use App\Enums\EmploymentArea;
use App\Enums\QualificationLevel;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\RosterBlackoutDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
    Role::findOrCreate('Putzkraft');
    Role::findOrCreate('Hausmeister');
});

function createRosterBlackoutDayHttpUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('shows the roster blackout days page to PDL users', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');

    $this->actingAs($pdl)
        ->get('/roster-blackout-days')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('RosterBlackoutDays/Index')
        );
});

it('lets PDL users create roster blackout days', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $location = Location::factory()->create();

    $this->actingAs($pdl)
        ->from('/roster-blackout-days')
        ->post('/roster-blackout-days', [
            'location_id' => $location->id,
            'date' => '2027-04-10',
            'reason' => 'Fortbildung im Wohnbereich',
            'blocks_vacation' => false,
            'blocks_overtime_compensation' => true,
        ])
        ->assertRedirect('/roster-blackout-days')
        ->assertSessionHas('status', 'roster-blackout-day-created');

    $blackoutDay = RosterBlackoutDay::query()->firstOrFail();

    expect($blackoutDay->location_id)->toBe($location->id)
        ->and($blackoutDay->date->toDateString())->toBe('2027-04-10')
        ->and($blackoutDay->reason)->toBe('Fortbildung im Wohnbereich')
        ->and($blackoutDay->blocks_vacation)->toBeFalse()
        ->and($blackoutDay->blocks_overtime_compensation)->toBeTrue()
        ->and($blackoutDay->created_by)->toBe($pdl->id);
});

it('blocks non PDL users from viewing roster blackout days', function (): void {
    $user = createRosterBlackoutDayHttpUser('Pflegekraft');

    $this->actingAs($user)
        ->get('/roster-blackout-days')
        ->assertForbidden();
});

it('blocks non PDL users from creating roster blackout days', function (): void {
    $user = createRosterBlackoutDayHttpUser('Pflegekraft');
    $location = Location::factory()->create();

    $this->actingAs($user)
        ->post('/roster-blackout-days', [
            'location_id' => $location->id,
            'date' => '2027-04-10',
        ])
        ->assertForbidden();

    expect(RosterBlackoutDay::query()->count())->toBe(0);
});

it('returns a date session error for duplicate roster blackout days in the same location', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $location = Location::factory()->create();

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2027-05-01',
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->from('/roster-blackout-days')
        ->post('/roster-blackout-days', [
            'location_id' => $location->id,
            'date' => '2027-05-01',
        ])
        ->assertRedirect('/roster-blackout-days')
        ->assertSessionHasErrors([
            'date' => 'Für diesen Wohnbereich gibt es an diesem Datum bereits eine Urlaubssperre.',
        ]);

    expect(RosterBlackoutDay::query()->count())->toBe(1);
});

it('allows the same day for different locations', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();

    RosterBlackoutDay::query()->create([
        'location_id' => $firstLocation->id,
        'date' => '2027-05-01',
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->from('/roster-blackout-days')
        ->post('/roster-blackout-days', [
            'location_id' => $secondLocation->id,
            'date' => '2027-05-01',
        ])
        ->assertRedirect('/roster-blackout-days')
        ->assertSessionHas('status', 'roster-blackout-day-created');

    expect(RosterBlackoutDay::query()->count())->toBe(2);
});

it('passes blackout days and locations to inertia', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $location = Location::factory()->create([
        'name' => 'Wohnbereich A',
    ]);

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2027-06-15',
        'reason' => 'Sommerfest',
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => false,
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->get('/roster-blackout-days')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('RosterBlackoutDays/Index')
                ->where('blackoutDays.0.locationName', 'Wohnbereich A')
                ->where('blackoutDays.0.date', '2027-06-15')
                ->where('blackoutDays.0.reason', 'Sommerfest')
                ->where('blackoutDays.0.blocksVacation', true)
                ->where('blackoutDays.0.blocksOvertimeCompensation', false)
                ->where('blackoutDays.0.createdByName', $pdl->name)
                ->where('locations.0.id', $location->id)
                ->where('locations.0.name', 'Wohnbereich A')
                ->where('blackoutDays.0.scope', 'all')
                ->where('blackoutDays.0.scopeLabel', 'Ganzer Wohnbereich')
                ->has('qualificationLevels', 3)
                ->has('staff')
        );
});

it('lets PDL users create a qualification-scoped blackout day', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $location = Location::factory()->create();

    $this->actingAs($pdl)
        ->from('/roster-blackout-days')
        ->post('/roster-blackout-days', [
            'location_id' => $location->id,
            'date' => '2027-12-24',
            'scope' => BlackoutScope::Qualification->value,
            'qualification_levels' => [QualificationLevel::Specialist->value],
            'reason' => 'Weihnachten nur Fachkräfte',
        ])
        ->assertRedirect('/roster-blackout-days')
        ->assertSessionHas('status', 'roster-blackout-day-created');

    $blackoutDay = RosterBlackoutDay::query()->firstOrFail();

    expect($blackoutDay->scope)->toBe(BlackoutScope::Qualification)
        ->and($blackoutDay->qualification_levels)->toBe([QualificationLevel::Specialist->value]);
});

it('lets PDL users create an employee-scoped blackout day', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $location = Location::factory()->create();

    $employee = User::factory()->for($location)->create();
    EmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employment_area' => EmploymentArea::Nursing,
        'active' => true,
    ]);

    $this->actingAs($pdl)
        ->from('/roster-blackout-days')
        ->post('/roster-blackout-days', [
            'location_id' => $location->id,
            'date' => '2027-12-24',
            'scope' => BlackoutScope::Employees->value,
            'employee_ids' => [$employee->id],
            'reason' => 'Sperre für einzelne Person',
        ])
        ->assertRedirect('/roster-blackout-days')
        ->assertSessionHas('status', 'roster-blackout-day-created');

    $blackoutDay = RosterBlackoutDay::query()->with('employees')->firstOrFail();

    expect($blackoutDay->scope)->toBe(BlackoutScope::Employees)
        ->and($blackoutDay->employees->pluck('id')->all())->toBe([$employee->id]);
});

it('rejects employee-scoped blackouts that reference foreign-location employees', function (): void {
    $pdl = createRosterBlackoutDayHttpUser('PDL');
    $location = Location::factory()->create();
    $foreign = User::factory()->create();

    $this->actingAs($pdl)
        ->from('/roster-blackout-days')
        ->post('/roster-blackout-days', [
            'location_id' => $location->id,
            'date' => '2027-12-24',
            'scope' => BlackoutScope::Employees->value,
            'employee_ids' => [$foreign->id],
        ])
        ->assertRedirect('/roster-blackout-days')
        ->assertSessionHasErrors('employee_ids.0');

    expect(RosterBlackoutDay::query()->count())->toBe(0);
});
