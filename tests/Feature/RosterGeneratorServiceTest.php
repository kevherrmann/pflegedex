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
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\RosterGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createRosterGeneratorRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createRosterGeneratorEmployee(Location $location, array $profileAttributes = [], array $userAttributes = []): User
{
    $employee = User::factory()->for($location)->create($userAttributes);

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

function createRosterGeneratorShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

function createRosterGeneratorStaffingRule(ShiftTemplate $shiftTemplate, array $attributes = []): ShiftStaffingRule
{
    return ShiftStaffingRule::query()->create([
        'location_id' => $shiftTemplate->location_id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => $attributes['weekday'] ?? null,
        'required_total_staff' => $attributes['required_total_staff'] ?? 1,
        'required_specialists' => $attributes['required_specialists'] ?? 0,
    ]);
}

function createRosterGeneratorShift(
    Roster $roster,
    User $employee,
    ShiftTemplate $shiftTemplate,
    string $date,
    ShiftSource $source = ShiftSource::Manual,
): Shift {
    $shiftDate = CarbonImmutable::parse($date)->startOfDay();
    $startsAt = CarbonImmutable::parse($shiftDate->toDateString() . ' ' . $shiftTemplate->starts_at);
    $endsAt = CarbonImmutable::parse($shiftDate->toDateString() . ' ' . $shiftTemplate->ends_at);

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

function createRosterGeneratorAbsenceRequest(User $employee, User $requestedBy, array $attributes = []): AbsenceRequest
{
    return AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => $attributes['type'] ?? AbsenceRequestType::Vacation,
        'starts_on' => $attributes['starts_on'] ?? '2027-01-01',
        'ends_on' => $attributes['ends_on'] ?? '2027-01-01',
        'days_count' => $attributes['days_count'] ?? 1,
        'status' => $attributes['status'] ?? AbsenceRequestStatus::Approved,
        'requested_by' => $requestedBy->id,
        'decided_by' => $attributes['decided_by'] ?? $requestedBy->id,
        'decided_at' => $attributes['decided_at'] ?? now(),
        'rejection_reason' => $attributes['rejection_reason'] ?? null,
        'note' => $attributes['note'] ?? null,
    ]);
}

function assertRosterGeneratorValidationField(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail("Expected ValidationException for field [{$field}].");
}

it('generates auto shifts for simple staffing requirements', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);

    $result = app(RosterGeneratorService::class)->generate($roster);

    expect($result->createdShifts)->toBe(31)
        ->and($result->deletedAutoShifts)->toBe(0)
        ->and(Shift::query()->count())->toBe(31)
        ->and(Shift::query()->where('source', ShiftSource::Auto->value)->count())->toBe(31)
        ->and(Shift::query()->where('user_id', $employee->id)->count())->toBe(31);
});

it('deletes old auto shifts and keeps manual shifts', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);
    $manualShift = createRosterGeneratorShift($roster, $employee, $shiftTemplate, '2027-01-01');
    $oldAutoShift = createRosterGeneratorShift($roster, $employee, $shiftTemplate, '2027-01-02', ShiftSource::Auto);

    $result = app(RosterGeneratorService::class)->generate($roster);

    expect($result->deletedAutoShifts)->toBe(1)
        ->and($result->createdShifts)->toBe(30)
        ->and(Shift::query()->whereKey($manualShift->id)->exists())->toBeTrue()
        ->and(Shift::query()->whereKey($oldAutoShift->id)->exists())->toBeFalse()
        ->and($manualShift->refresh()->source)->toBe(ShiftSource::Manual)
        ->and(Shift::query()->count())->toBe(31);
});

it('does not generate for published or locked rosters', function (RosterStatus $status): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $roster = createRosterGeneratorRoster($location, $pdl, ['status' => $status]);

    assertRosterGeneratorValidationField(
        fn () => app(RosterGeneratorService::class)->generate($roster),
        'status',
    );
})->with([RosterStatus::Published, RosterStatus::Locked]);

it('respects can work night', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $cannotWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => false], ['name' => 'Anna Tag']);
    $canWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => true], ['name' => 'Berta Nacht']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $nightShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    createRosterGeneratorStaffingRule($nightShiftTemplate);

    app(RosterGeneratorService::class)->generate($roster);

    expect(Shift::query()->where('user_id', $cannotWorkNight->id)->count())->toBe(0)
        ->and(Shift::query()->where('user_id', $canWorkNight->id)->count())->toBe(31);
});

it('respects approved absences', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $absentEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Abwesend']);
    $availableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Verfuegbar']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);
    createRosterGeneratorAbsenceRequest($absentEmployee, $pdl, [
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-31',
    ]);

    app(RosterGeneratorService::class)->generate($roster);

    expect(Shift::query()->where('user_id', $absentEmployee->id)->count())->toBe(0)
        ->and(Shift::query()->where('user_id', $availableEmployee->id)->count())->toBe(31);
});

it('prefers specialists for required specialist slots', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $nonSpecialist = createRosterGeneratorEmployee($location, ['is_nursing_specialist' => false], ['name' => 'Anna Pflege']);
    $specialist = createRosterGeneratorEmployee($location, ['is_nursing_specialist' => true], ['name' => 'Zoe Fachkraft']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate, [
        'required_total_staff' => 2,
        'required_specialists' => 1,
    ]);

    app(RosterGeneratorService::class)->generate($roster);

    $firstDayShifts = Shift::query()
        ->where('roster_id', $roster->id)
        ->whereDate('date', '2027-01-01')
        ->pluck('user_id')
        ->all();

    expect($firstDayShifts)->toContain($specialist->id)
        ->and($firstDayShifts)->toContain($nonSpecialist->id);
});

it('returns skipped entries when no candidate is available', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    createRosterGeneratorEmployee($location, ['can_work_night' => false]);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $nightShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    createRosterGeneratorStaffingRule($nightShiftTemplate);

    $result = app(RosterGeneratorService::class)->generate($roster);

    expect($result->createdShifts)->toBe(0)
        ->and($result->hasSkipped())->toBeTrue()
        ->and($result->skipped[0]['code'])->toBe('no_candidate')
        ->and($result->skipped[0]['context']['needSpecialist'])->toBeFalse();
});

it('roughly distributes by current shift count', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $alreadyPlanned = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Viele']);
    $lessPlanned = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Wenige']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);
    createRosterGeneratorShift($roster, $alreadyPlanned, $shiftTemplate, '2027-01-02');

    app(RosterGeneratorService::class)->generate($roster);

    $firstDayShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->whereDate('date', '2027-01-01')
        ->firstOrFail();

    expect($firstDayShift->user_id)->toBe($lessPlanned->id);
});
