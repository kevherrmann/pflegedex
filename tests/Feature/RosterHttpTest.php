<?php

use App\Enums\EmploymentArea;
use App\Enums\RosterStatus;
use App\Enums\ShiftSource;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
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

function createRosterHttpUser(string $role, ?Location $location = null): User
{
    $factory = User::factory();

    if ($location !== null) {
        $factory = $factory->for($location);
    }

    $user = $factory->create();
    $user->assignRole($role);

    return $user;
}

function createRosterHttpRoster(Location $location, User $createdBy, array $attributes = []): Roster
{
    if ($createdBy->hasRole('PDL') && $createdBy->location_id === null) {
        $createdBy->forceFill(['location_id' => $location->id])->save();
    }

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

function createRosterHttpEmployee(Location $location, array $profileAttributes = []): User
{
    $employee = User::factory()->for($location)->create();

    EmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employment_area' => $profileAttributes['employment_area'] ?? EmploymentArea::Nursing,
        'is_nursing_specialist' => $profileAttributes['is_nursing_specialist'] ?? false,
        'weekly_hours' => $profileAttributes['weekly_hours'] ?? 39.00,
        'regular_work_days_per_week' => $profileAttributes['regular_work_days_per_week'] ?? 5,
        'annual_vacation_days' => $profileAttributes['annual_vacation_days'] ?? 30,
        'vacation_days_carried_over' => $profileAttributes['vacation_days_carried_over'] ?? 0,
        'overtime_minutes_balance' => $profileAttributes['overtime_minutes_balance'] ?? 0,
        'can_work_early' => $profileAttributes['can_work_early'] ?? true,
        'can_work_late' => $profileAttributes['can_work_late'] ?? true,
        'can_work_night' => $profileAttributes['can_work_night'] ?? false,
        'active' => $profileAttributes['active'] ?? true,
    ]);

    return $employee->refresh();
}

function createRosterHttpShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
{
    return ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => $attributes['name'] ?? 'Frühdienst',
        'code' => $attributes['code'] ?? 'early',
        'starts_at' => $attributes['starts_at'] ?? '06:00',
        'ends_at' => $attributes['ends_at'] ?? '14:00',
        'duration_minutes' => $attributes['duration_minutes'] ?? 480,
        'color' => $attributes['color'] ?? '#F59E0B',
        'active' => $attributes['active'] ?? true,
    ]);
}

function createRosterHttpStaffingRule(ShiftTemplate $shiftTemplate, array $attributes = []): ShiftStaffingRule
{
    return ShiftStaffingRule::query()->create([
        'location_id' => $shiftTemplate->location_id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => $attributes['weekday'] ?? null,
        'required_total_staff' => $attributes['required_total_staff'] ?? 1,
        'required_specialists' => $attributes['required_specialists'] ?? 0,
    ]);
}

function createRosterHttpShift(
    Roster $roster,
    User $employee,
    ShiftTemplate $shiftTemplate,
    string $date,
    ShiftSource $source = ShiftSource::Manual,
): Shift {
    $shiftDate = CarbonImmutable::parse($date)->startOfDay();
    $startsAt = CarbonImmutable::parse($shiftDate->toDateString().' '.$shiftTemplate->starts_at);
    $endsAt = CarbonImmutable::parse($shiftDate->toDateString().' '.$shiftTemplate->ends_at);

    if ($endsAt->lessThanOrEqualTo($startsAt)) {
        $endsAt = $endsAt->addDay();
    }

    return Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $roster->location_id,
        'user_id' => $employee->id,
        'shift_template_id' => $shiftTemplate->id,
        'date' => $shiftDate->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'source' => $source,
        'note' => null,
    ]);
}

function rosterHttpJanuary2027Dates(): array
{
    $dates = [];

    for ($date = CarbonImmutable::create(2027, 1, 1); $date->month === 1; $date = $date->addDay()) {
        $dates[] = $date->toDateString();
    }

    return $dates;
}

it('shows the rosters page to PDL users', function (): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);

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

it('shows the roster detail page to PDL users', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Show')
                ->where('roster.id', $roster->id)
        );
});

it('blocks non PDL users from viewing the roster detail page', function (): void {
    $user = createRosterHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterHttpRoster($location, $createdBy);

    $this->actingAs($user)
        ->get("/rosters/{$roster->id}")
        ->assertForbidden();
});

it('passes roster employees and shift templates to the roster detail page', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create(['name' => 'Wohnbereich B']);
    $otherLocation = Location::factory()->create();
    $employee = createRosterHttpEmployee($location, [
        'is_nursing_specialist' => true,
        'can_work_night' => true,
    ]);
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpShiftTemplate($otherLocation, ['code' => 'late']);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Show')
                ->where('roster.id', $roster->id)
                ->where('roster.locationId', $location->id)
                ->where('roster.locationName', 'Wohnbereich B')
                ->where('employees.0.id', $employee->id)
                ->where('employees.0.name', $employee->name)
                ->where('employees.0.email', $employee->email)
                ->where('employees.0.locationId', $location->id)
                ->where('employees.0.isNursingSpecialist', true)
                ->where('employees.0.canWorkEarly', true)
                ->where('employees.0.canWorkLate', true)
                ->where('employees.0.canWorkNight', true)
                ->where('shiftTemplates.0.id', $shiftTemplate->id)
                ->where('shiftTemplates.0.locationId', $location->id)
                ->where('shiftTemplates.0.name', 'Frühdienst')
                ->where('shiftTemplates.0.code', 'early')
                ->where('shiftTemplates.0.startsAt', '06:00')
                ->where('shiftTemplates.0.endsAt', '14:00')
                ->has('shiftTemplates', 1)
        );
});

it('passes roster shifts to the roster detail page', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createRosterHttpEmployee($location);
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    $manualShift = Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $location->id,
        'user_id' => $employee->id,
        'shift_template_id' => $shiftTemplate->id,
        'date' => '2027-01-10',
        'starts_at' => '2027-01-10 06:00:00',
        'ends_at' => '2027-01-10 14:00:00',
        'source' => ShiftSource::Manual,
        'note' => 'Notiz',
    ]);

    $autoShift = Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $location->id,
        'user_id' => $employee->id,
        'shift_template_id' => $shiftTemplate->id,
        'date' => '2027-01-11',
        'starts_at' => '2027-01-11 06:00:00',
        'ends_at' => '2027-01-11 14:00:00',
        'source' => ShiftSource::Auto,
        'note' => null,
    ]);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('roster.id', $roster->id)
                ->where('roster.shifts.0.id', $manualShift->id)
                ->where('roster.shifts.0.date', '2027-01-10')
                ->where('roster.shifts.0.employeeName', $employee->name)
                ->where('roster.shifts.0.shiftTemplateName', 'Frühdienst')
                ->where('roster.shifts.0.shiftTemplateCode', 'early')
                ->where('roster.shifts.0.source', 'manual')
                ->where('roster.shifts.0.sourceLabel', 'Manuell')
                ->where('roster.shifts.0.note', 'Notiz')
                ->has('roster.shifts.0.startsAt')
                ->has('roster.shifts.0.endsAt')
                ->where('roster.shifts.1.id', $autoShift->id)
                ->where('roster.shifts.1.date', '2027-01-11')
                ->where('roster.shifts.1.source', 'auto')
                ->where('roster.shifts.1.sourceLabel', 'Auto')
                ->where('calendarDays.9.shifts.0.source', 'manual')
                ->where('calendarDays.9.shifts.0.sourceLabel', 'Manuell')
                ->where('calendarDays.10.shifts.0.source', 'auto')
                ->where('calendarDays.10.shifts.0.sourceLabel', 'Auto')
        );
});

it('passes calendar days for every day of the roster month', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->has('calendarDays', 31)
                ->where('calendarDays.0.date', '2027-01-01')
                ->where('calendarDays.30.date', '2027-01-31')
        );
});

it('passes German weekday labels in calendar days', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('calendarDays.0.dayLabel', '01.01.2027')
                ->where('calendarDays.0.weekdayLabel', 'Freitag')
        );
});

it('assigns shifts to the matching calendar day', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createRosterHttpEmployee($location);
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpShift($roster, $employee, $shiftTemplate, '2027-01-10');

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('calendarDays.9.date', '2027-01-10')
                ->where('calendarDays.9.shifts.0.employeeName', $employee->name)
                ->where('calendarDays.9.shifts.0.shiftTemplateName', 'Frühdienst')
                ->where('calendarDays.0.shifts', [])
        );
});

it('sorts shifts within a calendar day by start time', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createRosterHttpEmployee($location);
    $roster = createRosterHttpRoster($location, $pdl);
    $earlyShiftTemplate = createRosterHttpShiftTemplate($location);
    $lateShiftTemplate = createRosterHttpShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'color' => '#3B82F6',
    ]);

    createRosterHttpShift($roster, $employee, $lateShiftTemplate, '2027-01-10');
    createRosterHttpShift($roster, $employee, $earlyShiftTemplate, '2027-01-10');

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('calendarDays.9.shifts.0.shiftTemplateCode', 'early')
                ->where('calendarDays.9.shifts.1.shiftTemplateCode', 'late')
        );
});

it('passes empty shifts arrays for calendar days without shifts', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('calendarDays.0.date', '2027-01-01')
                ->where('calendarDays.0.shifts', [])
        );
});

it('passes roster validation flash results to the roster detail page', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->withSession([
            'rosterValidationResult' => [
                'rosterId' => $roster->id,
                'status' => 'yellow',
                'errors' => [],
                'warnings' => [
                    [
                        'code' => 'missing_staffing_rule',
                        'message' => 'Hinweis',
                        'context' => ['date' => '2027-01-01'],
                    ],
                ],
            ],
        ])
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('rosterValidationResult.rosterId', $roster->id)
                ->where('rosterValidationResult.status', 'yellow')
                ->where('rosterValidationResult.warnings.0.code', 'missing_staffing_rule')
        );
});

it('passes locations and rosters to inertia', function (): void {
    $location = Location::factory()->create([
        'name' => 'Wohnbereich A',
    ]);
    $pdl = createRosterHttpUser('PDL', $location);
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
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);

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

it('blocks PDL users from publishing a roster with validator errors through http', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/publish")
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('status');

    expect($roster->refresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->published_at)->toBeNull();
});

it('lets PDL users publish a green roster through http', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    foreach (rosterHttpJanuary2027Dates() as $date) {
        $employee = createRosterHttpEmployee($location, ['weekly_hours' => 80.00]);

        createRosterHttpShift($roster, $employee, $shiftTemplate, $date);
    }

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
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);

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
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);

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

it('lets PDL users validate a roster and flashes the validation result', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/validate")
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'roster-validated')
        ->assertSessionHas('rosterValidationResult', fn (array $result): bool => $result['rosterId'] === $roster->id
            && $result['status'] === 'red'
            && count($result['errors']) > 0
            && $result['warnings'] === []);
});

it('blocks non PDL users from validating rosters', function (): void {
    $user = createRosterHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterHttpRoster($location, $createdBy);

    $this->actingAs($user)
        ->post("/rosters/{$roster->id}/validate")
        ->assertForbidden();
});

it('flashes green status for a green validation result', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    foreach (rosterHttpJanuary2027Dates() as $date) {
        $employee = createRosterHttpEmployee($location, [
            'is_nursing_specialist' => true,
            'weekly_hours' => 80.00,
        ]);

        createRosterHttpShift($roster, $employee, $shiftTemplate, $date);
    }

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/validate")
        ->assertRedirect('/rosters')
        ->assertSessionHas('rosterValidationResult', fn (array $result): bool => $result['status'] === 'green'
            && $result['errors'] === []
            && $result['warnings'] === []);
});

it('flashes yellow status for a validation result with warnings', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);

    createRosterHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/validate")
        ->assertRedirect('/rosters')
        ->assertSessionHas('rosterValidationResult', fn (array $result): bool => $result['status'] === 'yellow'
            && $result['errors'] === []
            && count($result['warnings']) > 0);
});

it('flashes red status for a validation result with errors', function (): void {
    $pdl = createRosterHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);

    createRosterHttpStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/validate")
        ->assertRedirect('/rosters')
        ->assertSessionHas('rosterValidationResult', fn (array $result): bool => $result['status'] === 'red'
            && count($result['errors']) > 0);
});

it('shows only rosters from the PDL Wohnbereich', function (): void {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $otherLocation = Location::factory()->create(['name' => 'Wohnbereich B']);
    $pdl = createRosterHttpUser('PDL', $location);
    $ownRoster = createRosterHttpRoster($location, $pdl, ['month' => 6]);
    createRosterHttpRoster($otherLocation, User::factory()->create(), ['month' => 7]);

    $this->actingAs($pdl)
        ->get('/rosters')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Index')
                ->has('locations', 1)
                ->where('locations.0.id', $location->id)
                ->has('rosters', 1)
                ->where('rosters.0.id', $ownRoster->id)
        );
});

it('blocks PDL users from viewing rosters from another Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $foreignRoster = createRosterHttpRoster($otherLocation, User::factory()->create());

    $this->actingAs($pdl)
        ->get("/rosters/{$foreignRoster->id}")
        ->assertForbidden();
});

it('blocks PDL users from creating rosters for another Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post('/rosters', [
            'location_id' => $otherLocation->id,
            'year' => 2027,
            'month' => 8,
        ])
        ->assertForbidden();

    expect(Roster::query()->count())->toBe(0);
});

it('blocks PDL users from changing or validating rosters from another Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $draftRoster = createRosterHttpRoster($otherLocation, User::factory()->create(), [
        'month' => 8,
        'status' => RosterStatus::Draft,
    ]);
    $publishedRoster = createRosterHttpRoster($otherLocation, User::factory()->create(), [
        'month' => 9,
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);
    $reopenRoster = createRosterHttpRoster($otherLocation, User::factory()->create(), [
        'month' => 10,
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);
    $validateRoster = createRosterHttpRoster($otherLocation, User::factory()->create(), [
        'month' => 11,
    ]);

    $this->actingAs($pdl)->patch("/rosters/{$draftRoster->id}/publish")->assertForbidden();
    $this->actingAs($pdl)->patch("/rosters/{$publishedRoster->id}/lock")->assertForbidden();
    $this->actingAs($pdl)->patch("/rosters/{$reopenRoster->id}/reopen")->assertForbidden();
    $this->actingAs($pdl)->post("/rosters/{$validateRoster->id}/validate")->assertForbidden();

    expect($draftRoster->refresh()->status)->toBe(RosterStatus::Draft)
        ->and($publishedRoster->refresh()->status)->toBe(RosterStatus::Published)
        ->and($reopenRoster->refresh()->status)->toBe(RosterStatus::Published);
});

it('lets PDL users generate their own roster', function (): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    createRosterHttpEmployee($location, [], ['name' => 'Anna Pflege']);
    createRosterHttpEmployee($location, [], ['name' => 'Berta Pflege']);
    createRosterHttpEmployee($location, [], ['name' => 'Clara Pflege']);
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);
    createRosterHttpStaffingRule($shiftTemplate);

    $this->actingAs($pdl)
        ->from("/rosters/{$roster->id}")
        ->post("/rosters/{$roster->id}/generate")
        ->assertRedirect("/rosters/{$roster->id}")
        ->assertSessionHas('status', 'roster-generated')
        ->assertSessionHas('rosterGenerationResult', fn (array $result): bool => $result['createdShifts'] === 31
            && $result['deletedAutoShifts'] === 0
            && $result['skipped'] === [])
        ->assertSessionHas('rosterValidationResult', fn (array $result): bool => $result['rosterId'] === $roster->id
            && in_array($result['status'], ['green', 'yellow', 'red'], true)
            && array_key_exists('errors', $result)
            && array_key_exists('warnings', $result));

    expect(Shift::query()->where('roster_id', $roster->id)->count())->toBe(31)
        ->and(Shift::query()->where('source', ShiftSource::Auto->value)->count())->toBe(31);
});

it('validates freshly generated shifts after generating a roster', function (): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    createRosterHttpEmployee($location, ['weekly_hours' => 80.00], ['name' => 'Anna Pflege']);
    createRosterHttpEmployee($location, ['weekly_hours' => 80.00], ['name' => 'Berta Pflege']);
    createRosterHttpEmployee($location, ['weekly_hours' => 80.00], ['name' => 'Clara Pflege']);
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);
    createRosterHttpStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    $this->actingAs($pdl)
        ->from("/rosters/{$roster->id}")
        ->post("/rosters/{$roster->id}/generate")
        ->assertRedirect("/rosters/{$roster->id}")
        ->assertSessionHas('rosterGenerationResult', fn (array $result): bool => $result['createdShifts'] > 0)
        ->assertSessionHas('rosterValidationResult', fn (array $result): bool => collect($result['errors'])
            ->doesntContain(fn (array $error): bool => $error['code'] === 'understaffed_shift'));
});

it('blocks PDL users from generating rosters from another Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $foreignRoster = createRosterHttpRoster($otherLocation, User::factory()->create());

    $this->actingAs($pdl)
        ->post("/rosters/{$foreignRoster->id}/generate")
        ->assertForbidden();
});

it('returns a session error when generating published or locked rosters', function (RosterStatus $status, int $month): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $roster = createRosterHttpRoster($location, $pdl, [
        'month' => $month,
        'status' => $status,
    ]);

    $this->actingAs($pdl)
        ->from("/rosters/{$roster->id}")
        ->post("/rosters/{$roster->id}/generate")
        ->assertRedirect("/rosters/{$roster->id}")
        ->assertSessionHasErrors('status');

    expect(Shift::query()->where('roster_id', $roster->id)->count())->toBe(0);
})->with([
    [RosterStatus::Published, 2],
    [RosterStatus::Locked, 3],
]);

it('passes roster generation flash results to the roster detail page', function (): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $roster = createRosterHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->withSession([
            'rosterGenerationResult' => [
                'createdShifts' => 3,
                'deletedAutoShifts' => 2,
                'skipped' => [
                    [
                        'code' => 'no_candidate',
                        'message' => 'Hinweis',
                        'context' => ['date' => '2027-01-01'],
                    ],
                ],
            ],
        ])
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('rosterGenerationResult.createdShifts', 3)
                ->where('rosterGenerationResult.deletedAutoShifts', 2)
                ->where('rosterGenerationResult.skipped.0.code', 'no_candidate')
        );
});

it('lets PDL users delete auto shifts in their own roster', function (): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $employee = createRosterHttpEmployee($location);
    $roster = createRosterHttpRoster($location, $pdl);
    $shiftTemplate = createRosterHttpShiftTemplate($location);
    $manualShift = createRosterHttpShift($roster, $employee, $shiftTemplate, '2027-01-01');
    $autoShift = createRosterHttpShift($roster, $employee, $shiftTemplate, '2027-01-02');
    $autoShift->update(['source' => ShiftSource::Auto]);

    $this->actingAs($pdl)
        ->from("/rosters/{$roster->id}")
        ->delete("/rosters/{$roster->id}/auto-shifts")
        ->assertRedirect("/rosters/{$roster->id}")
        ->assertSessionHas('status', 'roster-auto-shifts-deleted')
        ->assertSessionHas('rosterGenerationResult', fn (array $result): bool => $result['createdShifts'] === 0
            && $result['deletedAutoShifts'] === 1
            && $result['skipped'] === []);

    expect(Shift::query()->whereKey($manualShift->id)->exists())->toBeTrue()
        ->and(Shift::query()->whereKey($autoShift->id)->exists())->toBeFalse()
        ->and($manualShift->refresh()->source)->toBe(ShiftSource::Manual);
});

it('blocks PDL users from deleting auto shifts in another Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $foreignRoster = createRosterHttpRoster($otherLocation, User::factory()->create());

    $this->actingAs($pdl)
        ->delete("/rosters/{$foreignRoster->id}/auto-shifts")
        ->assertForbidden();
});

it('returns a session error when deleting auto shifts from published or locked rosters', function (RosterStatus $status, int $month): void {
    $location = Location::factory()->create();
    $pdl = createRosterHttpUser('PDL', $location);
    $employee = createRosterHttpEmployee($location);
    $roster = createRosterHttpRoster($location, $pdl, [
        'month' => $month,
        'status' => $status,
    ]);
    $shiftTemplate = createRosterHttpShiftTemplate($location);
    $autoShift = createRosterHttpShift($roster, $employee, $shiftTemplate, '2027-01-01');
    $autoShift->update(['source' => ShiftSource::Auto]);

    $this->actingAs($pdl)
        ->from("/rosters/{$roster->id}")
        ->delete("/rosters/{$roster->id}/auto-shifts")
        ->assertRedirect("/rosters/{$roster->id}")
        ->assertSessionHasErrors('status');

    expect(Shift::query()->whereKey($autoShift->id)->exists())->toBeTrue();
})->with([
    [RosterStatus::Published, 4],
    [RosterStatus::Locked, 5],
]);
