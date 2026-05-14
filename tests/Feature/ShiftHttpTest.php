<?php

use App\Enums\EmploymentArea;
use App\Enums\RosterStatus;
use App\Enums\ShiftSource;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function createShiftHttpUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createShiftHttpRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createShiftHttpShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

function createShiftHttpEmployee(Location $location, array $profileAttributes = []): User
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

it('lets PDL users add a shift to a draft roster', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
            'note' => 'Bitte eintragen',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'shift-created');

    $shift = Shift::query()->firstOrFail();

    expect($shift->roster_id)->toBe($roster->id)
        ->and($shift->location_id)->toBe($location->id)
        ->and($shift->user_id)->toBe($employee->id)
        ->and($shift->shift_template_id)->toBe($shiftTemplate->id)
        ->and($shift->date->toDateString())->toBe('2027-01-10')
        ->and($shift->source)->toBe(ShiftSource::Manual)
        ->and($shift->note)->toBe('Bitte eintragen');
});

it('blocks non PDL users from adding shifts', function (): void {
    $user = createShiftHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $createdBy);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($user)
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertForbidden();

    expect(Shift::query()->count())->toBe(0);
});

it('passes employees and shift templates to inertia', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location, [
        'is_nursing_specialist' => true,
        'can_work_night' => true,
    ]);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->get('/rosters')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Index')
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
        );
});

it('passes roster shifts to inertia', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    Shift::query()->create([
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

    $this->actingAs($pdl)
        ->get('/rosters')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('rosters.0.id', $roster->id)
                ->where('rosters.0.shifts.0.date', '2027-01-10')
                ->where('rosters.0.shifts.0.employeeName', $employee->name)
                ->where('rosters.0.shifts.0.shiftTemplateName', 'Frühdienst')
                ->where('rosters.0.shifts.0.shiftTemplateCode', 'early')
                ->where('rosters.0.shifts.0.note', 'Notiz')
                ->has('rosters.0.shifts.0.startsAt')
                ->has('rosters.0.shifts.0.endsAt')
        );
});

it('returns a session error for invalid users', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => (string) Str::uuid(),
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('user_id');
});

it('returns a session error for invalid shift templates', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => (string) Str::uuid(),
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('shift_template_id');
});

it('returns a user error when cleaning staff is assigned through http', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location, [
        'employment_area' => EmploymentArea::Cleaning,
    ]);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('user_id');
});

it('returns a shift template error when the template belongs to another location', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($otherLocation);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('shift_template_id');
});

it('returns a date error when the shift is outside the roster month', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-02-01',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('date');
});

it('returns a user error when the same shift is assigned twice on the same day', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'shift-created');

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('user_id');
});

it('calculates night shift end time on the following day through http', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location, ['can_work_night' => true]);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'color' => '#6366F1',
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->post("/rosters/{$roster->id}/shifts", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-10',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'shift-created');

    $shift = Shift::query()->firstOrFail();

    expect($shift->starts_at->format('Y-m-d H:i:s'))->toBe('2027-01-10 22:00:00')
        ->and($shift->ends_at->format('Y-m-d H:i:s'))->toBe('2027-01-11 06:00:00');
});
