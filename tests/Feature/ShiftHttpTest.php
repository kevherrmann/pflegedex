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

function createShiftHttpShift(Roster $roster, User $employee, ShiftTemplate $shiftTemplate, array $attributes = []): Shift
{
    return Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $roster->location_id,
        'user_id' => $employee->id,
        'shift_template_id' => $shiftTemplate->id,
        'date' => $attributes['date'] ?? '2027-01-10',
        'starts_at' => $attributes['starts_at'] ?? '2027-01-10 06:00:00',
        'ends_at' => $attributes['ends_at'] ?? '2027-01-10 14:00:00',
        'source' => $attributes['source'] ?? ShiftSource::Manual,
        'note' => $attributes['note'] ?? null,
    ]);
}

function createShiftHttpAbsenceRequest(User $employee, User $requestedBy, array $attributes = []): AbsenceRequest
{
    return AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $attributes['location_id'] ?? $employee->location_id,
        'type' => $attributes['type'] ?? AbsenceRequestType::Vacation,
        'starts_on' => $attributes['starts_on'] ?? '2027-01-10',
        'ends_on' => $attributes['ends_on'] ?? '2027-01-10',
        'days_count' => $attributes['days_count'] ?? 1,
        'status' => $attributes['status'] ?? AbsenceRequestStatus::Approved,
        'requested_by' => $attributes['requested_by'] ?? $requestedBy->id,
        'decided_by' => $attributes['decided_by'] ?? $requestedBy->id,
        'decided_at' => $attributes['decided_at'] ?? now(),
        'rejection_reason' => $attributes['rejection_reason'] ?? null,
        'note' => $attributes['note'] ?? null,
    ]);
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
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Show')
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

    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate, [
        'date' => '2027-01-10',
        'starts_at' => '2027-01-10 06:00:00',
        'ends_at' => '2027-01-10 14:00:00',
        'note' => 'Notiz',
    ]);

    $this->actingAs($pdl)
        ->get("/rosters/{$roster->id}")
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Rosters/Show')
                ->where('roster.id', $roster->id)
                ->where('roster.shifts.0.id', $shift->id)
                ->where('roster.shifts.0.userId', $employee->id)
                ->where('roster.shifts.0.shiftTemplateId', $shiftTemplate->id)
                ->where('roster.shifts.0.date', '2027-01-10')
                ->where('roster.shifts.0.employeeName', $employee->name)
                ->where('roster.shifts.0.shiftTemplateName', 'Frühdienst')
                ->where('roster.shifts.0.shiftTemplateCode', 'early')
                ->where('roster.shifts.0.note', 'Notiz')
                ->has('roster.shifts.0.startsAt')
                ->has('roster.shifts.0.endsAt')
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

it('lets PDL users delete a shift from an editable roster', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->delete("/rosters/{$roster->id}/shifts/{$shift->id}")
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'shift-deleted');

    $this->assertDatabaseMissing('shifts', [
        'id' => $shift->id,
    ]);
});

it('blocks non PDL users from deleting shifts', function (): void {
    $user = createShiftHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $createdBy);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($user)
        ->delete("/rosters/{$roster->id}/shifts/{$shift->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('shifts', [
        'id' => $shift->id,
    ]);
});

it('does not delete a shift through the wrong roster URL', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $otherRoster = createShiftHttpRoster($otherLocation, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->delete("/rosters/{$otherRoster->id}/shifts/{$shift->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('shifts', [
        'id' => $shift->id,
    ]);
});

it('does not delete shifts from published or locked rosters', function (): void {
    $pdl = createShiftHttpUser('PDL');

    foreach ([RosterStatus::Published, RosterStatus::Locked] as $index => $status) {
        $location = Location::factory()->create();
        $employee = createShiftHttpEmployee($location);
        $roster = createShiftHttpRoster($location, $pdl, [
            'month' => $index + 1,
            'status' => $status,
        ]);
        $shiftTemplate = createShiftHttpShiftTemplate($location);
        $shift = createShiftHttpShift($roster, $employee, $shiftTemplate, [
            'date' => sprintf('2027-%02d-10', $index + 1),
            'starts_at' => sprintf('2027-%02d-10 06:00:00', $index + 1),
            'ends_at' => sprintf('2027-%02d-10 14:00:00', $index + 1),
        ]);

        $this->actingAs($pdl)
            ->from('/rosters')
            ->delete("/rosters/{$roster->id}/shifts/{$shift->id}")
            ->assertRedirect('/rosters')
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
        ]);
    }
});

it('removes the shift from the database after deletion', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->delete("/rosters/{$roster->id}/shifts/{$shift->id}");

    expect(Shift::query()->whereKey($shift->id)->exists())->toBeFalse();
});

it('lets PDL users update a shift through http', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $oldEmployee = createShiftHttpEmployee($location);
    $newEmployee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $earlyShiftTemplate = createShiftHttpShiftTemplate($location);
    $lateShiftTemplate = createShiftHttpShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'color' => '#3B82F6',
    ]);
    $shift = createShiftHttpShift($roster, $oldEmployee, $earlyShiftTemplate, [
        'note' => 'Alt',
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $newEmployee->id,
            'shift_template_id' => $lateShiftTemplate->id,
            'date' => '2027-01-11',
            'note' => 'Aktualisiert',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'shift-updated');

    $shift->refresh();

    expect($shift->user_id)->toBe($newEmployee->id)
        ->and($shift->shift_template_id)->toBe($lateShiftTemplate->id)
        ->and($shift->date->toDateString())->toBe('2027-01-11')
        ->and($shift->starts_at->format('Y-m-d H:i:s'))->toBe('2027-01-11 14:00:00')
        ->and($shift->ends_at->format('Y-m-d H:i:s'))->toBe('2027-01-11 22:00:00')
        ->and($shift->note)->toBe('Aktualisiert')
        ->and($shift->source)->toBe(ShiftSource::Manual);
});

it('blocks non PDL users from updating shifts', function (): void {
    $user = createShiftHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $newEmployee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $createdBy);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate, [
        'note' => 'Alt',
    ]);

    $this->actingAs($user)
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $newEmployee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-11',
            'note' => 'Nicht erlaubt',
        ])
        ->assertForbidden();

    $shift->refresh();

    expect($shift->user_id)->toBe($employee->id)
        ->and($shift->date->toDateString())->toBe('2027-01-10')
        ->and($shift->note)->toBe('Alt');
});

it('does not update a shift through the wrong roster URL', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $otherRoster = createShiftHttpRoster($otherLocation, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate, [
        'note' => 'Alt',
    ]);

    $this->actingAs($pdl)
        ->patch("/rosters/{$otherRoster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-11',
            'note' => 'Falsch',
        ])
        ->assertNotFound();

    expect($shift->refresh()->note)->toBe('Alt');
});

it('does not update shifts from published or locked rosters', function (): void {
    $pdl = createShiftHttpUser('PDL');

    foreach ([RosterStatus::Published, RosterStatus::Locked] as $index => $status) {
        $location = Location::factory()->create();
        $employee = createShiftHttpEmployee($location);
        $roster = createShiftHttpRoster($location, $pdl, [
            'month' => $index + 1,
            'status' => $status,
        ]);
        $shiftTemplate = createShiftHttpShiftTemplate($location);
        $shift = createShiftHttpShift($roster, $employee, $shiftTemplate, [
            'date' => sprintf('2027-%02d-10', $index + 1),
            'starts_at' => sprintf('2027-%02d-10 06:00:00', $index + 1),
            'ends_at' => sprintf('2027-%02d-10 14:00:00', $index + 1),
            'note' => 'Alt',
        ]);

        $this->actingAs($pdl)
            ->from('/rosters')
            ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
                'user_id' => $employee->id,
                'shift_template_id' => $shiftTemplate->id,
                'date' => sprintf('2027-%02d-11', $index + 1),
                'note' => 'Nicht geändert',
            ])
            ->assertRedirect('/rosters')
            ->assertSessionHasErrors('status');

        expect($shift->refresh()->note)->toBe('Alt');
    }
});

it('returns a session error for invalid users when updating shifts', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => (string) Str::uuid(),
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-11',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('user_id');
});

it('returns a session error for invalid shift templates when updating shifts', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => (string) Str::uuid(),
            'date' => '2027-01-11',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('shift_template_id');
});

it('returns a shift template error when updating to a template from another location', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $otherShiftTemplate = createShiftHttpShiftTemplate($otherLocation);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => $otherShiftTemplate->id,
            'date' => '2027-01-11',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('shift_template_id');
});

it('returns a date error when updating a shift outside the roster month', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-02-01',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('date');
});

it('returns a user error when approved absence blocks shift updates', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $employee, $shiftTemplate);

    createShiftHttpAbsenceRequest($employee, $pdl, [
        'starts_on' => '2027-01-11',
        'ends_on' => '2027-01-11',
        'status' => AbsenceRequestStatus::Approved,
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-11',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('user_id');
});

it('returns a user error when updating to duplicate same shift on the same day', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location);
    $otherEmployee = createShiftHttpEmployee($location);
    $roster = createShiftHttpRoster($location, $pdl);
    $shiftTemplate = createShiftHttpShiftTemplate($location);
    $shift = createShiftHttpShift($roster, $otherEmployee, $shiftTemplate);
    createShiftHttpShift($roster, $employee, $shiftTemplate, [
        'date' => '2027-01-11',
        'starts_at' => '2027-01-11 06:00:00',
        'ends_at' => '2027-01-11 14:00:00',
    ]);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => '2027-01-11',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHasErrors('user_id');
});

it('calculates night shift end time on the following day when updating through http', function (): void {
    $pdl = createShiftHttpUser('PDL');
    $location = Location::factory()->create();
    $employee = createShiftHttpEmployee($location, ['can_work_night' => true]);
    $roster = createShiftHttpRoster($location, $pdl);
    $earlyShiftTemplate = createShiftHttpShiftTemplate($location);
    $nightShiftTemplate = createShiftHttpShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'color' => '#6366F1',
    ]);
    $shift = createShiftHttpShift($roster, $employee, $earlyShiftTemplate);

    $this->actingAs($pdl)
        ->from('/rosters')
        ->patch("/rosters/{$roster->id}/shifts/{$shift->id}", [
            'user_id' => $employee->id,
            'shift_template_id' => $nightShiftTemplate->id,
            'date' => '2027-01-12',
        ])
        ->assertRedirect('/rosters')
        ->assertSessionHas('status', 'shift-updated');

    $shift->refresh();

    expect($shift->starts_at->format('Y-m-d H:i:s'))->toBe('2027-01-12 22:00:00')
        ->and($shift->ends_at->format('Y-m-d H:i:s'))->toBe('2027-01-13 06:00:00');
});
