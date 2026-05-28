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
    $firstEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Pflege']);
    $secondEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Pflege']);
    $thirdEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Clara Pflege']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);

    $result = app(RosterGeneratorService::class)->generate($roster);

    $firstEmployeeShiftCount = Shift::query()->where('user_id', $firstEmployee->id)->count();
    $secondEmployeeShiftCount = Shift::query()->where('user_id', $secondEmployee->id)->count();
    $thirdEmployeeShiftCount = Shift::query()->where('user_id', $thirdEmployee->id)->count();

    expect($result->createdShifts)->toBe(31)
        ->and($result->deletedAutoShifts)->toBe(0)
        ->and(Shift::query()->count())->toBe(31)
        ->and(Shift::query()->where('source', ShiftSource::Auto->value)->count())->toBe(31)
        ->and($firstEmployeeShiftCount + $secondEmployeeShiftCount + $thirdEmployeeShiftCount)->toBe(31)
        ->and($firstEmployeeShiftCount)->toBeGreaterThan(0)
        ->and($secondEmployeeShiftCount)->toBeGreaterThan(0)
        ->and($thirdEmployeeShiftCount)->toBeGreaterThan(0);
});

it('deletes old auto shifts and keeps manual shifts', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    createRosterGeneratorEmployee($location, [], ['name' => 'Berta Pflege']);
    createRosterGeneratorEmployee($location, [], ['name' => 'Clara Pflege']);
    createRosterGeneratorEmployee($location, [], ['name' => 'Dora Pflege']);
    createRosterGeneratorEmployee($location, [], ['name' => 'Eva Pflege']);
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
    $firstCanWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => true], ['name' => 'Berta Nacht']);
    $secondCanWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => true], ['name' => 'Clara Nacht']);
    $thirdCanWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => true], ['name' => 'Dora Nacht']);
    $fourthCanWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => true], ['name' => 'Eva Nacht']);
    $fifthCanWorkNight = createRosterGeneratorEmployee($location, ['can_work_night' => true], ['name' => 'Franka Nacht']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $nightShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    createRosterGeneratorStaffingRule($nightShiftTemplate);

    app(RosterGeneratorService::class)->generate($roster);

    $firstNightShiftCount = Shift::query()->where('user_id', $firstCanWorkNight->id)->count();
    $secondNightShiftCount = Shift::query()->where('user_id', $secondCanWorkNight->id)->count();
    $thirdNightShiftCount = Shift::query()->where('user_id', $thirdCanWorkNight->id)->count();
    $fourthNightShiftCount = Shift::query()->where('user_id', $fourthCanWorkNight->id)->count();
    $fifthNightShiftCount = Shift::query()->where('user_id', $fifthCanWorkNight->id)->count();

    expect(Shift::query()->where('user_id', $cannotWorkNight->id)->count())->toBe(0)
        ->and($firstNightShiftCount + $secondNightShiftCount + $thirdNightShiftCount + $fourthNightShiftCount + $fifthNightShiftCount)->toBe(31)
        ->and($firstNightShiftCount)->toBeGreaterThan(0)
        ->and($secondNightShiftCount)->toBeGreaterThan(0)
        ->and($thirdNightShiftCount)->toBeGreaterThan(0);
});

it('respects approved absences', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $absentEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Abwesend']);
    $firstAvailableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Verfuegbar']);
    $secondAvailableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Clara Verfuegbar']);
    $thirdAvailableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Dora Verfuegbar']);
    $fourthAvailableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Eva Verfuegbar']);
    $fifthAvailableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Franka Verfuegbar']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);
    createRosterGeneratorAbsenceRequest($absentEmployee, $pdl, [
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-31',
    ]);

    app(RosterGeneratorService::class)->generate($roster);

    $firstAvailableShiftCount = Shift::query()->where('user_id', $firstAvailableEmployee->id)->count();
    $secondAvailableShiftCount = Shift::query()->where('user_id', $secondAvailableEmployee->id)->count();
    $thirdAvailableShiftCount = Shift::query()->where('user_id', $thirdAvailableEmployee->id)->count();
    $fourthAvailableShiftCount = Shift::query()->where('user_id', $fourthAvailableEmployee->id)->count();
    $fifthAvailableShiftCount = Shift::query()->where('user_id', $fifthAvailableEmployee->id)->count();

    expect(Shift::query()->where('user_id', $absentEmployee->id)->count())->toBe(0)
        ->and($firstAvailableShiftCount + $secondAvailableShiftCount + $thirdAvailableShiftCount + $fourthAvailableShiftCount + $fifthAvailableShiftCount)->toBe(31)
        ->and($firstAvailableShiftCount)->toBeGreaterThan(0)
        ->and($secondAvailableShiftCount)->toBeGreaterThan(0)
        ->and($thirdAvailableShiftCount)->toBeGreaterThan(0);
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
        ->and($result->skipped[0]['context']['needSpecialist'])->toBeFalse()
        ->and($result->skipped[0]['context']['reason'])->toBe('no_available_employee');
});

it('avoids early shift after a late previous day for the same employee', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $restConflictEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Konflikt']);
    $availableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Frei']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    $lateShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'active' => false,
    ]);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);
    createRosterGeneratorShift($roster, $restConflictEmployee, $lateShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $availableEmployee, $earlyShiftTemplate, '2027-01-31');

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-02')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($availableEmployee->id);
});

it('avoids late shift before an early next day for the same employee', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $restConflictEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Konflikt']);
    $availableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Frei']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $lateShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
    ]);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'active' => false,
    ]);
    createRosterGeneratorStaffingRule($lateShiftTemplate, ['weekday' => 6]);
    createRosterGeneratorShift($roster, $restConflictEmployee, $earlyShiftTemplate, '2027-01-03');
    createRosterGeneratorShift($roster, $availableEmployee, $earlyShiftTemplate, '2027-01-31');

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $lateShiftTemplate->id)
        ->whereDate('date', '2027-01-02')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($availableEmployee->id);
});

it('returns no candidate when the only employee would violate rest time', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    $lateShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'active' => false,
    ]);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);
    createRosterGeneratorShift($roster, $employee, $lateShiftTemplate, '2027-01-01');

    $result = app(RosterGeneratorService::class)->generate($roster);

    $skippedForTargetDate = collect($result->skipped)->firstWhere('context.date', '2027-01-02');

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-02')
        ->exists())->toBeFalse()
        ->and($skippedForTargetDate['code'])->toBe('no_candidate')
        ->and($skippedForTargetDate['context']['reason'])->toBe('no_available_employee');
});

it('allows shifts when the required rest time is met', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);
    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-01');

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-02')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($employee->id);
});

it('avoids the seventh consecutive work day for the same employee', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $consecutiveEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Sechs']);
    $availableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Frei']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 4]);

    foreach (['2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04', '2027-01-05', '2027-01-06'] as $date) {
        createRosterGeneratorShift($roster, $consecutiveEmployee, $earlyShiftTemplate, $date);
    }

    foreach (['2027-01-10', '2027-01-11', '2027-01-12', '2027-01-13', '2027-01-14', '2027-01-15'] as $date) {
        createRosterGeneratorShift($roster, $availableEmployee, $earlyShiftTemplate, $date);
    }

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-07')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($availableEmployee->id);
});

it('returns no candidate when the only employee would get a seventh consecutive work day', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 4]);

    foreach (['2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04', '2027-01-05', '2027-01-06'] as $date) {
        createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, $date);
    }

    $result = app(RosterGeneratorService::class)->generate($roster);

    $skippedForTargetDate = collect($result->skipped)->firstWhere('context.date', '2027-01-07');

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-07')
        ->exists())->toBeFalse()
        ->and($skippedForTargetDate['code'])->toBe('no_candidate')
        ->and($skippedForTargetDate['context']['reason'])->toBe('no_available_employee');
});

it('allows the sixth consecutive work day', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 3]);

    foreach (['2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04', '2027-01-05'] as $date) {
        createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, $date);
    }

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-06')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($employee->id);
});

it('counts multiple shifts on the same date as one work day for consecutive work days', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    $lateShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'active' => false,
    ]);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 3]);

    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $employee, $lateShiftTemplate, '2027-01-01');

    foreach (['2027-01-02', '2027-01-03', '2027-01-04', '2027-01-05'] as $date) {
        createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, $date);
    }

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-06')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($employee->id);
});

it('counts auto shifts created earlier in the same generator run for consecutive work days', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);

    foreach ([5, 6, 7, 1, 2, 3, 4] as $weekday) {
        createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => $weekday]);
    }

    createRosterGeneratorAbsenceRequest($employee, $pdl, [
        'starts_on' => '2027-01-08',
        'ends_on' => '2027-01-31',
        'days_count' => 24,
    ]);

    $result = app(RosterGeneratorService::class)->generate($roster);

    $skippedForSeventhDay = collect($result->skipped)->firstWhere('context.date', '2027-01-07');

    expect($result->createdShifts)->toBe(6)
        ->and(Shift::query()
            ->where('roster_id', $roster->id)
            ->where('user_id', $employee->id)
            ->where('source', ShiftSource::Auto->value)
            ->count())->toBe(6)
        ->and(Shift::query()
            ->where('roster_id', $roster->id)
            ->whereDate('date', '2027-01-07')
            ->exists())->toBeFalse()
        ->and($skippedForSeventhDay['code'])->toBe('no_candidate')
        ->and($skippedForSeventhDay['context']['reason'])->toBe('no_available_employee');
});

it('avoids the third weekend for the same employee', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $weekendEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Wochenende']);
    $availableEmployee = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Frei']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    createRosterGeneratorShift($roster, $weekendEmployee, $earlyShiftTemplate, '2027-01-02');
    createRosterGeneratorShift($roster, $weekendEmployee, $earlyShiftTemplate, '2027-01-09');
    createRosterGeneratorShift($roster, $availableEmployee, $earlyShiftTemplate, '2027-01-04');
    createRosterGeneratorShift($roster, $availableEmployee, $earlyShiftTemplate, '2027-01-05');

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-16')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($availableEmployee->id);
});

it('returns no candidate when the only employee would get a third weekend', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-02');
    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-09');

    $result = app(RosterGeneratorService::class)->generate($roster);

    $skippedForTargetDate = collect($result->skipped)->firstWhere('context.date', '2027-01-16');

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-16')
        ->exists())->toBeFalse()
        ->and($skippedForTargetDate['code'])->toBe('no_candidate')
        ->and($skippedForTargetDate['context']['reason'])->toBe('no_available_employee');
});

it('counts Saturday and Sunday of the same weekend once for weekend load', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-02');
    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-03');
    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-09');

    $result = app(RosterGeneratorService::class)->generate($roster);

    $skippedForTargetDate = collect($result->skipped)->firstWhere('context.date', '2027-01-16');

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-16')
        ->exists())->toBeFalse()
        ->and($skippedForTargetDate['code'])->toBe('no_candidate');
});

it('allows the second weekend', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-02');

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-09')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($employee->id);
});

it('ignores weekdays for weekend load', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    foreach (['2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08'] as $date) {
        createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, $date);
    }

    app(RosterGeneratorService::class)->generate($roster);

    $generatedShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-02')
        ->firstOrFail();

    expect($generatedShift->user_id)->toBe($employee->id);
});

it('counts auto shifts created earlier in the same generator run for weekend load', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    $result = app(RosterGeneratorService::class)->generate($roster);

    $skippedForThirdWeekend = collect($result->skipped)->firstWhere('context.date', '2027-01-16');

    expect($result->createdShifts)->toBe(2)
        ->and(Shift::query()
            ->where('roster_id', $roster->id)
            ->where('user_id', $employee->id)
            ->where('source', ShiftSource::Auto->value)
            ->count())->toBe(2)
        ->and(Shift::query()
            ->where('roster_id', $roster->id)
            ->whereDate('date', '2027-01-16')
            ->exists())->toBeFalse()
        ->and($skippedForThirdWeekend['code'])->toBe('no_candidate')
        ->and($skippedForThirdWeekend['context']['reason'])->toBe('no_available_employee');
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

it('delete auto shifts deletes only auto shifts and keeps manual shifts', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    $manualShift = createRosterGeneratorShift($roster, $employee, $shiftTemplate, '2027-01-01');
    $autoShift = createRosterGeneratorShift($roster, $employee, $shiftTemplate, '2027-01-02', ShiftSource::Auto);

    $result = app(RosterGeneratorService::class)->deleteAutoShifts($roster);

    expect($result->createdShifts)->toBe(0)
        ->and($result->deletedAutoShifts)->toBe(1)
        ->and($result->skipped)->toBe([])
        ->and(Shift::query()->whereKey($manualShift->id)->exists())->toBeTrue()
        ->and(Shift::query()->whereKey($autoShift->id)->exists())->toBeFalse()
        ->and($manualShift->refresh()->source)->toBe(ShiftSource::Manual);
});

it('delete auto shifts returns the deleted auto shift count', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);

    createRosterGeneratorShift($roster, $employee, $shiftTemplate, '2027-01-01', ShiftSource::Auto);
    createRosterGeneratorShift($roster, $employee, $shiftTemplate, '2027-01-02', ShiftSource::Auto);

    $result = app(RosterGeneratorService::class)->deleteAutoShifts($roster);

    expect($result->deletedAutoShifts)->toBe(2)
        ->and($result->createdShifts)->toBe(0)
        ->and($result->skipped)->toBe([])
        ->and(Shift::query()->where('roster_id', $roster->id)->count())->toBe(0);
});

it('delete auto shifts blocks published or locked rosters', function (RosterStatus $status): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $roster = createRosterGeneratorRoster($location, $pdl, ['status' => $status]);

    assertRosterGeneratorValidationField(
        fn () => app(RosterGeneratorService::class)->deleteAutoShifts($roster),
        'status',
    );
})->with([RosterStatus::Published, RosterStatus::Locked]);
