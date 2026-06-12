<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Enums\RosterStatus;
use App\Enums\ShiftSource;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function myRosterEmployee(Location $location, array $profileAttributes = []): User
{
    $employee = User::factory()->for($location)->create();
    $employee->assignRole('Pflegekraft');

    EmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employment_area' => EmploymentArea::Nursing,
        'is_nursing_specialist' => false,
        'weekly_hours' => $profileAttributes['weekly_hours'] ?? 39.00,
        'regular_work_days_per_week' => 5,
        'annual_vacation_days' => 30,
        'vacation_days_carried_over' => 0,
        'overtime_minutes_balance' => 0,
        'can_work_early' => true,
        'can_work_late' => true,
        'can_work_night' => true,
        'active' => $profileAttributes['active'] ?? true,
    ]);

    return $employee->refresh();
}

function myRosterRoster(Location $location, User $createdBy, RosterStatus $status): Roster
{
    return Roster::query()->create([
        'location_id' => $location->id,
        'year' => 2027,
        'month' => 1,
        'status' => $status,
        'published_at' => $status === RosterStatus::Published || $status === RosterStatus::Locked ? now() : null,
        'created_by' => $createdBy->id,
    ]);
}

function myRosterTemplate(Location $location): ShiftTemplate
{
    return ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'color' => '#F59E0B',
        'active' => true,
    ]);
}

function myRosterShift(Roster $roster, User $employee, ShiftTemplate $template, string $date): Shift
{
    $day = CarbonImmutable::parse($date);

    return Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $roster->location_id,
        'user_id' => $employee->id,
        'shift_template_id' => $template->id,
        'date' => $day->toDateString(),
        'starts_at' => $day->setTime(6, 0),
        'ends_at' => $day->setTime(14, 0),
        'source' => ShiftSource::Auto,
    ]);
}

it('shows own shifts from published rosters', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = myRosterEmployee($location);
    $roster = myRosterRoster($location, $pdl, RosterStatus::Published);
    $template = myRosterTemplate($location);

    myRosterShift($roster, $employee, $template, '2027-01-05');
    myRosterShift($roster, $employee, $template, '2027-01-06');

    $this->actingAs($employee)
        ->get(route('my-roster.show', ['month' => '2027-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MyRoster/Show')
            ->where('month.label', 'Januar 2027')
            ->has('days', 31)
            ->where('days.4.date', '2027-01-05')
            ->has('days.4.shifts', 1)
            ->where('days.4.shifts.0.shiftTemplateName', 'Frühdienst')
            ->where('days.4.shifts.0.startsAt', '06:00')
            ->where('summary.shiftCount', 2)
            ->where('summary.plannedMinutes', 960)
            ->where('hasUnpublishedRoster', false));
});

it('hides shifts from draft rosters and flags the month as unpublished', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = myRosterEmployee($location);
    $draftRoster = myRosterRoster($location, $pdl, RosterStatus::Draft);
    $template = myRosterTemplate($location);

    myRosterShift($draftRoster, $employee, $template, '2027-01-05');

    $this->actingAs($employee)
        ->get(route('my-roster.show', ['month' => '2027-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.shiftCount', 0)
            ->where('days.4.shifts', [])
            ->where('hasUnpublishedRoster', true));
});

it('shows shifts from locked rosters with a lock flag', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = myRosterEmployee($location);
    $roster = myRosterRoster($location, $pdl, RosterStatus::Locked);
    $template = myRosterTemplate($location);

    myRosterShift($roster, $employee, $template, '2027-01-05');

    $this->actingAs($employee)
        ->get(route('my-roster.show', ['month' => '2027-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.4.shifts.0.isLocked', true));
});

it('never shows shifts of other employees', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = myRosterEmployee($location);
    $colleague = myRosterEmployee($location);
    $roster = myRosterRoster($location, $pdl, RosterStatus::Published);
    $template = myRosterTemplate($location);

    myRosterShift($roster, $colleague, $template, '2027-01-05');

    $this->actingAs($employee)
        ->get(route('my-roster.show', ['month' => '2027-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.shiftCount', 0)
            ->where('days.4.shifts', []));
});

it('shows approved absences on the affected days', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = myRosterEmployee($location);

    AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2027-01-11',
        'ends_on' => '2027-01-13',
        'days_count' => 3,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $employee->id,
        'decided_by' => $pdl->id,
        'decided_at' => now(),
    ]);

    $this->actingAs($employee)
        ->get(route('my-roster.show', ['month' => '2027-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.10.absence.typeLabel', 'Urlaub')
            ->where('days.13.absence', null));
});

it('falls back to the current month for invalid month parameters', function (): void {
    $location = Location::factory()->create();
    $employee = myRosterEmployee($location);

    $this->actingAs($employee)
        ->get(route('my-roster.show', ['month' => 'kaputt-99']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('month.year', now()->year)
            ->where('month.month', now()->month));
});

it('forbids access for users without an active employee profile', function (): void {
    $location = Location::factory()->create();

    $withoutProfile = User::factory()->for($location)->create();
    $withoutProfile->assignRole('Pflegekraft');

    $this->actingAs($withoutProfile)
        ->get(route('my-roster.show'))
        ->assertForbidden();

    $inactive = myRosterEmployee($location, ['active' => false]);

    $this->actingAs($inactive)
        ->get(route('my-roster.show'))
        ->assertForbidden();
});

it('requires authentication', function (): void {
    $this->get(route('my-roster.show'))->assertRedirect(route('login'));
});
