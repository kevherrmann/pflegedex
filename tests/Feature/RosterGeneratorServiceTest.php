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
use App\Models\RosterBlackoutDay;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\RosterGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        'avoids_weekends' => $profileAttributes['avoids_weekends'] ?? false,
        'week_rotation' => $profileAttributes['week_rotation'] ?? null,
        'fixed_free_weekdays' => $profileAttributes['fixed_free_weekdays'] ?? [],
        'max_consecutive_days_override' => $profileAttributes['max_consecutive_days_override'] ?? null,
        'maternity_protection' => $profileAttributes['maternity_protection'] ?? false,
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
        'category' => $attributes['category'] ?? ($attributes['code'] ?? 'early'),
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
        'target_total_staff' => $attributes['target_total_staff'] ?? null,
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
    // Strikter Modus ohne Wochenend-Lockerung.
    config(['rostering.relax_weekend_limit_for_coverage' => false]);

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
    // Strikter Modus ohne Wochenend-Lockerung.
    config(['rostering.relax_weekend_limit_for_coverage' => false]);

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
    // Strikter Modus ohne Wochenend-Lockerung.
    config(['rostering.relax_weekend_limit_for_coverage' => false]);

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

it('prefers lower relative utilization', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $partTime = createRosterGeneratorEmployee($location, ['weekly_hours' => 20.00], ['name' => 'Anna Teilzeit']);
    $fullTime = createRosterGeneratorEmployee($location, ['weekly_hours' => 40.00], ['name' => 'Berta Vollzeit']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $existingShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Bestehender Dienst',
        'code' => 'existing_relative_utilization',
        'active' => false,
    ]);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_relative_utilization',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);
    createRosterGeneratorShift($roster, $partTime, $existingShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $fullTime, $existingShiftTemplate, '2027-01-01');

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($fullTime->id);
});

it('uses planned minutes as tie breaker when relative utilization is equal', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $higherPlanned = createRosterGeneratorEmployee($location, ['weekly_hours' => 40.00], ['name' => 'Anna Mehr']);
    $lowerPlanned = createRosterGeneratorEmployee($location, ['weekly_hours' => 40.00], ['name' => 'Berta Weniger']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $oneMinuteShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Eine Minute',
        'code' => 'one_minute_equal_utilization',
        'starts_at' => '06:00',
        'ends_at' => '06:01',
        'duration_minutes' => 1,
        'active' => false,
    ]);
    $twoMinuteShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zwei Minuten',
        'code' => 'two_minutes_equal_utilization',
        'starts_at' => '06:00',
        'ends_at' => '06:02',
        'duration_minutes' => 2,
        'active' => false,
    ]);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_equal_utilization',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);
    createRosterGeneratorShift($roster, $higherPlanned, $twoMinuteShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $lowerPlanned, $oneMinuteShiftTemplate, '2027-01-01');

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($lowerPlanned->id);
});

it('sorts employees without target hours behind employees with target hours', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    createRosterGeneratorEmployee($location, ['weekly_hours' => 0.00, 'regular_work_days_per_week' => 0], ['name' => 'Anna Ohne Soll']);
    $withTargetHours = createRosterGeneratorEmployee($location, ['weekly_hours' => 40.00], ['name' => 'Berta Mit Soll']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_missing_hours',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($withTargetHours->id);
});

it('roughly distributes by planned minutes', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $longPlanned = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Lang']);
    $shortPlanned = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Kurz']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $longShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Langer Dienst',
        'code' => 'long_existing',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'active' => false,
    ]);
    $shortShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Kurzer Dienst',
        'code' => 'short_existing',
        'starts_at' => '06:00',
        'ends_at' => '07:00',
        'duration_minutes' => 60,
        'active' => false,
    ]);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_planned_minutes',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);
    createRosterGeneratorShift($roster, $longPlanned, $longShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $shortPlanned, $shortShiftTemplate, '2027-01-01');

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($shortPlanned->id);
});

it('prefers fewer shifts when planned minutes are equal', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $moreShifts = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Mehr']);
    $fewerShifts = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Weniger']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shortShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Kurzer Dienst',
        'code' => 'short_equal_minutes',
        'starts_at' => '06:00',
        'ends_at' => '07:00',
        'duration_minutes' => 60,
        'active' => false,
    ]);
    $mediumShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Mittlerer Dienst',
        'code' => 'medium_equal_minutes',
        'starts_at' => '06:00',
        'ends_at' => '08:00',
        'duration_minutes' => 120,
        'active' => false,
    ]);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_shift_count_tie',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);
    createRosterGeneratorShift($roster, $moreShifts, $shortShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $moreShifts, $shortShiftTemplate, '2027-01-02');
    createRosterGeneratorShift($roster, $fewerShifts, $mediumShiftTemplate, '2027-01-01');

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($fewerShifts->id);
});

it('sorts by name when planned minutes and shift count are equal', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $anna = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Pflege']);
    createRosterGeneratorEmployee($location, [], ['name' => 'Berta Pflege']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_name_tie',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($anna->id);
});

it('counts auto shifts created earlier in the same generator run for relative utilization', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $anna = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Pflege']);
    $berta = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Pflege']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_run_minutes',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);

    app(RosterGeneratorService::class)->generate($roster);

    $firstTargetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();
    $secondTargetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-12')
        ->firstOrFail();

    expect($firstTargetShift->user_id)->toBe($anna->id)
        ->and($secondTargetShift->user_id)->toBe($berta->id);
});

it('counts overnight shift minutes correctly for relative utilization', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $nightPlanned = createRosterGeneratorEmployee($location, [], ['name' => 'Anna Nacht']);
    $shortPlanned = createRosterGeneratorEmployee($location, [], ['name' => 'Berta Kurz']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $nightShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night_existing',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'duration_minutes' => 480,
        'active' => false,
    ]);
    $shortShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Kurzer Dienst',
        'code' => 'short_for_night_compare',
        'starts_at' => '06:00',
        'ends_at' => '07:00',
        'duration_minutes' => 60,
        'active' => false,
    ]);
    $targetShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Zieldienst',
        'code' => 'target_overnight_minutes',
    ]);
    createRosterGeneratorStaffingRule($targetShiftTemplate, ['weekday' => 2]);
    createRosterGeneratorShift($roster, $nightPlanned, $nightShiftTemplate, '2027-01-01');
    createRosterGeneratorShift($roster, $shortPlanned, $shortShiftTemplate, '2027-01-01');

    app(RosterGeneratorService::class)->generate($roster);

    $targetShift = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $targetShiftTemplate->id)
        ->whereDate('date', '2027-01-05')
        ->firstOrFail();

    expect($targetShift->user_id)->toBe($shortPlanned->id);
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

it('respects rest periods across the month boundary', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location, ['can_work_night' => true]);
    $decemberRoster = createRosterGeneratorRoster($location, $pdl, ['year' => 2026, 'month' => 12]);
    $januaryRoster = createRosterGeneratorRoster($location, $pdl);
    $nightShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
    ]);
    createRosterGeneratorStaffingRule($earlyShiftTemplate);

    // Nachtdienst am 31.12. endet am 1.1. um 06:00 — Frühdienst am 1.1. wäre ohne Ruhezeit.
    createRosterGeneratorShift($decemberRoster, $employee, $nightShiftTemplate, '2026-12-31');

    $result = app(RosterGeneratorService::class)->generate($januaryRoster);

    $firstOfJanuarySkip = collect($result->skipped)
        ->first(fn (array $entry): bool => $entry['code'] === 'no_candidate'
            && $entry['context']['date'] === '2027-01-01');

    expect(Shift::query()
        ->where('roster_id', $januaryRoster->id)
        ->whereDate('date', '2027-01-01')
        ->exists())->toBeFalse()
        ->and(Shift::query()
            ->where('roster_id', $januaryRoster->id)
            ->whereDate('date', '2027-01-02')
            ->exists())->toBeTrue()
        ->and($firstOfJanuarySkip)->not->toBeNull()
        ->and($firstOfJanuarySkip['context']['rejections'])->toHaveKey('rest_period');
});

it('respects consecutive work days across the month boundary', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $decemberRoster = createRosterGeneratorRoster($location, $pdl, ['year' => 2026, 'month' => 12]);
    $januaryRoster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate);

    // 27.-31.12. bereits gearbeitet: Die Folge darf im Januar nicht über
    // insgesamt 6 Tage hinaus verlängert werden.
    foreach (['2026-12-27', '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31'] as $date) {
        createRosterGeneratorShift($decemberRoster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterGeneratorService::class)->generate($januaryRoster);

    // Alle Arbeitstage des Mitarbeiters über die Monatsgrenze hinweg.
    $workDates = Shift::query()
        ->where('user_id', $employee->id)
        ->pluck('date')
        ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
        ->unique()
        ->sort()
        ->values();

    $longestRun = 1;
    $currentRun = 1;

    for ($index = 1; $index < $workDates->count(); $index++) {
        $currentRun = CarbonImmutable::parse($workDates[$index])
            ->equalTo(CarbonImmutable::parse($workDates[$index - 1])->addDay())
            ? $currentRun + 1
            : 1;

        $longestRun = max($longestRun, $currentRun);
    }

    $consecutiveSkips = collect($result->skipped)
        ->filter(fn (array $entry): bool => $entry['code'] === 'no_candidate'
            && array_key_exists('consecutive_days', $entry['context']['rejections']));

    expect($longestRun)->toBeLessThanOrEqual(6)
        ->and($consecutiveSkips)->not->toBeEmpty();
});

it('respects rest conflicts with shifts in another location roster', function (): void {
    $homeLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = User::factory()->for($homeLocation)->create();
    $employee = createRosterGeneratorEmployee($homeLocation, ['can_work_night' => true]);
    $homeRoster = createRosterGeneratorRoster($homeLocation, $pdl);
    $otherRoster = createRosterGeneratorRoster($otherLocation, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($homeLocation);
    createRosterGeneratorStaffingRule($earlyShiftTemplate);
    $otherNightTemplate = createRosterGeneratorShiftTemplate($otherLocation, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);

    // Nachtdienst am anderen Standort am 14.1. endet am 15.1. um 06:00.
    createRosterGeneratorShift($otherRoster, $employee, $otherNightTemplate, '2027-01-14');

    $result = app(RosterGeneratorService::class)->generate($homeRoster);

    $fifteenthSkip = collect($result->skipped)
        ->first(fn (array $entry): bool => $entry['code'] === 'no_candidate'
            && $entry['context']['date'] === '2027-01-15');

    expect(Shift::query()
        ->where('roster_id', $homeRoster->id)
        ->whereDate('date', '2027-01-15')
        ->exists())->toBeFalse()
        ->and($fifteenthSkip)->not->toBeNull()
        ->and($fifteenthSkip['context']['rejections'])->toHaveKey('rest_period');
});

it('enforces the weekly maximum working minutes', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    createRosterGeneratorEmployee($location, ['weekly_hours' => 60.00, 'regular_work_days_per_week' => 7]);
    $roster = createRosterGeneratorRoster($location, $pdl);
    // 10-Stunden-Dienste (ArbZG-Tagesmax): Ab dem 5. Dienst je ISO-Woche ist die 48h-Grenze überschritten.
    $longShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Langdienst',
        'code' => 'long_day',
        'starts_at' => '06:00',
        'ends_at' => '16:00',
        'duration_minutes' => 600,
    ]);
    createRosterGeneratorStaffingRule($longShiftTemplate);

    $result = app(RosterGeneratorService::class)->generate($roster);

    // ISO-Woche 4.-10.1.2027: Maximal 4 Dienste à 720 Minuten.
    $minutesInWeek = Shift::query()
        ->where('roster_id', $roster->id)
        ->whereDate('date', '>=', '2027-01-04')
        ->whereDate('date', '<=', '2027-01-10')
        ->count() * 720;

    $weeklyCapSkips = collect($result->skipped)
        ->filter(fn (array $entry): bool => isset($entry['context']['rejections']['weekly_hours_cap']));

    expect($minutesInWeek)->toBeLessThanOrEqual(2880)
        ->and($weeklyCapSkips)->not->toBeEmpty();
});

it('enforces the daily maximum working minutes', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    createRosterGeneratorEmployee($location, ['weekly_hours' => 60.00, 'regular_work_days_per_week' => 7]);
    $roster = createRosterGeneratorRoster($location, $pdl);
    // 11-Stunden-Dienst überschreitet die Tagesgrenze (§ 3 ArbZG, max. 10 h).
    $tooLong = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Überlangdienst',
        'code' => 'too_long',
        'starts_at' => '06:00',
        'ends_at' => '17:00',
        'duration_minutes' => 660,
    ]);
    createRosterGeneratorStaffingRule($tooLong);

    $result = app(RosterGeneratorService::class)->generate($roster);

    $dailyCapSkips = collect($result->skipped)
        ->filter(fn (array $entry): bool => isset($entry['context']['rejections']['daily_hours_cap']));

    expect(Shift::query()->where('roster_id', $roster->id)->count())->toBe(0)
        ->and($dailyCapSkips)->not->toBeEmpty();
});

it('respects maternity protection (no night, sunday or holiday shifts)', function (): void {
    $location = Location::factory()->create(['state' => 'BY']);
    $pdl = User::factory()->for($location)->create();
    // Mitarbeiterin im Mutterschutz, könnte sonst Früh + Nacht arbeiten.
    $protected = createRosterGeneratorEmployee($location, [
        'maternity_protection' => true,
        'can_work_early' => true,
        'can_work_night' => true,
        'weekly_hours' => 40.00,
        'regular_work_days_per_week' => 5,
    ]);

    $roster = createRosterGeneratorRoster($location, $pdl);

    $early = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Frühdienst', 'code' => 'early', 'starts_at' => '06:00', 'ends_at' => '14:00', 'duration_minutes' => 480,
    ]);
    $night = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Nachtdienst', 'code' => 'night', 'starts_at' => '22:00', 'ends_at' => '06:00', 'duration_minutes' => 480,
    ]);
    createRosterGeneratorStaffingRule($early);
    createRosterGeneratorStaffingRule($night);

    app(RosterGeneratorService::class)->generate($roster);

    $shifts = Shift::query()
        ->where('roster_id', $roster->id)
        ->where('user_id', $protected->id)
        ->with('shiftTemplate')
        ->get();

    expect($shifts->where('shiftTemplate.code', 'night')->count())->toBe(0);

    foreach ($shifts as $shift) {
        expect($shift->date->isSunday())->toBeFalse()
            // Neujahr 2027 (Feiertag) ebenfalls gesperrt.
            ->and($shift->date->toDateString())->not->toBe('2027-01-01');
    }
});

it('fills blackout days before regular days', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    createRosterGeneratorEmployee($location, ['weekly_hours' => 60.00, 'regular_work_days_per_week' => 7]);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $longShiftTemplate = createRosterGeneratorShiftTemplate($location, [
        'name' => 'Langdienst',
        'code' => 'long_day',
        'starts_at' => '06:00',
        'ends_at' => '16:00',
        'duration_minutes' => 600,
    ]);
    createRosterGeneratorStaffingRule($longShiftTemplate);

    // Freitag 8.1. ist Urlaubssperre: Trotz Wochenstunden-Limit muss dieser Tag besetzt sein.
    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2027-01-08',
        'created_by' => $pdl->id,
    ]);

    app(RosterGeneratorService::class)->generate($roster);

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->whereDate('date', '2027-01-08')
        ->exists())->toBeTrue();
});

it('reports per-constraint rejection counts when no candidate is available', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    createRosterGeneratorEmployee($location, ['can_work_night' => false]);
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

    expect($result->skipped[0]['context']['rejections'])->toBe(['shift_capability' => 2]);
});

it('warns when an assigned employee has a pending absence request', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $shiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($shiftTemplate, ['weekday' => 5]);

    createRosterGeneratorAbsenceRequest($employee, $pdl, [
        // 8.1.2027 ist ein regulärer Freitag (1.1. wäre Feiertag -> Sonntagsbesetzung).
        'starts_on' => '2027-01-08',
        'ends_on' => '2027-01-08',
        'status' => AbsenceRequestStatus::Requested,
        'decided_by' => null,
        'decided_at' => null,
    ]);

    $result = app(RosterGeneratorService::class)->generate($roster);

    $pendingWarning = collect($result->warnings)
        ->first(fn (array $entry): bool => $entry['code'] === 'pending_absence_overlap');

    expect($result->createdShifts)->toBeGreaterThan(0)
        ->and($pendingWarning)->not->toBeNull()
        ->and($pendingWarning['context']['date'])->toBe('2027-01-08')
        ->and($pendingWarning['context']['userId'])->toBe($employee->id);
});

it('generates with a bounded number of database queries', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    foreach (range(1, 12) as $index) {
        createRosterGeneratorEmployee($location, [
            'is_nursing_specialist' => $index <= 5,
            'can_work_night' => $index % 2 === 0,
        ], ['name' => sprintf('Pflegekraft %02d', $index)]);
    }

    $roster = createRosterGeneratorRoster($location, $pdl);

    foreach ([
        ['name' => 'Frühdienst', 'code' => 'early', 'starts_at' => '06:00', 'ends_at' => '14:00'],
        ['name' => 'Spätdienst', 'code' => 'late', 'starts_at' => '14:00', 'ends_at' => '22:00'],
        ['name' => 'Nachtdienst', 'code' => 'night', 'starts_at' => '22:00', 'ends_at' => '06:00'],
    ] as $template) {
        createRosterGeneratorStaffingRule(
            createRosterGeneratorShiftTemplate($location, $template),
            ['required_total_staff' => 2, 'required_specialists' => 1],
        );
    }

    DB::enableQueryLog();
    $result = app(RosterGeneratorService::class)->generate($roster);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $selectQueries = collect($queries)
        ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'));

    // Der Planungszustand wird einmal geladen: Lesezugriffe wachsen nicht mit der Slot-Anzahl.
    expect($result->createdShifts)->toBeGreaterThan(50)
        ->and($selectQueries->count())->toBeLessThan(30);
});

it('relaxes the weekend limit when the slot would otherwise stay unfilled', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $earlyShiftTemplate = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($earlyShiftTemplate, ['weekday' => 6]);

    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-02');
    createRosterGeneratorShift($roster, $employee, $earlyShiftTemplate, '2027-01-09');

    $result = app(RosterGeneratorService::class)->generate($roster);

    // Besetzung schlägt Wochenend-Empfehlung: Das dritte Wochenende wird
    // besetzt statt den Samstag unbesetzt zu lassen (Validator warnt dann).
    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('shift_template_id', $earlyShiftTemplate->id)
        ->whereDate('date', '2027-01-16')
        ->exists())->toBeTrue()
        ->and(collect($result->skipped)->where('code', 'no_candidate'))->toBeEmpty();
});

it('fills beyond the minimum up to the ideal staffing when employees are below target', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    $early = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($early, [
        'required_total_staff' => 1,
        'target_total_staff' => 3,
        'required_specialists' => 0,
    ]);

    // Genug frühdienstfähige Vollzeit-Mitarbeiter, damit die Idealbesetzung erreichbar ist.
    foreach (range(1, 10) as $ignored) {
        createRosterGeneratorEmployee($location);
    }

    $roster = createRosterGeneratorRoster($location, $pdl);
    app(RosterGeneratorService::class)->generate($roster);

    $countsPerDay = Shift::query()
        ->where('roster_id', $roster->id)
        ->get()
        ->groupBy(fn (Shift $shift): string => $shift->date->toDateString())
        ->map(fn ($shifts): int => $shifts->count());

    // Aufgestockt: mehr als die reine Mindestbesetzung (1 * 31 Tage).
    expect(Shift::query()->where('roster_id', $roster->id)->count())->toBeGreaterThan(31);
    // Nie über die Idealbesetzung und nie unter die Mindestbesetzung.
    expect($countsPerDay->max())->toBeLessThanOrEqual(3)
        ->and($countsPerDay->min())->toBeGreaterThanOrEqual(1);
    // Mindestens an einem Tag wurde die Idealbesetzung tatsächlich erreicht.
    expect($countsPerDay->contains(3))->toBeTrue();

    // Kein Mitarbeiter über Monats-Soll: Vollzeit (39h/5 Tage) im Januar 2027
    // = min(10363, 10629) = 10363 min => höchstens 21 Dienste à 480 min.
    $shiftsPerEmployee = Shift::query()
        ->where('roster_id', $roster->id)
        ->get()
        ->groupBy(fn (Shift $shift): string => (string) $shift->user_id)
        ->map(fn ($shifts): int => $shifts->count());

    expect($shiftsPerEmployee->max())->toBeLessThanOrEqual(21);
});

it('plans only the minimum staffing when no ideal staffing is configured', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    $early = createRosterGeneratorShiftTemplate($location);
    // Kein target_total_staff -> keine Aufstockung (Rückwärtskompatibilität).
    createRosterGeneratorStaffingRule($early, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    foreach (range(1, 10) as $ignored) {
        createRosterGeneratorEmployee($location);
    }

    $roster = createRosterGeneratorRoster($location, $pdl);
    app(RosterGeneratorService::class)->generate($roster);

    // Genau ein Dienst pro Tag im Januar (31 Tage), keine Aufstockung.
    expect(Shift::query()->where('roster_id', $roster->id)->count())->toBe(31);
});

it('plans multiple shifts of the same category, each with its own hours', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    $early1 = createRosterGeneratorShiftTemplate($location, [
        'code' => 'early',
        'category' => 'early',
        'name' => 'Früh 1',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
    ]);
    $early2 = createRosterGeneratorShiftTemplate($location, [
        'code' => 'early2',
        'category' => 'early',
        'name' => 'Früh 2',
        'starts_at' => '07:00',
        'ends_at' => '13:00',
        'duration_minutes' => 360,
        'color' => '#10B981',
    ]);
    // Besetzung gilt pro Kategorie (Früh): min 2 → verteilt sich auf Früh 1 + Früh 2.
    createRosterGeneratorStaffingRule($early1, ['required_total_staff' => 2, 'required_specialists' => 0]);

    // Frühdienstfähige Mitarbeiter (Default can_work_early = true).
    foreach (range(1, 8) as $ignored) {
        createRosterGeneratorEmployee($location);
    }

    $roster = createRosterGeneratorRoster($location, $pdl);
    app(RosterGeneratorService::class)->generate($roster);

    $day = '2027-01-15';

    // Beide Früh-Schichten werden besetzt – Kategorie-Fähigkeit greift für beide.
    expect(Shift::query()->where('roster_id', $roster->id)
        ->where('shift_template_id', $early1->id)->where('date', $day)->exists())->toBeTrue()
        ->and(Shift::query()->where('roster_id', $roster->id)
            ->where('shift_template_id', $early2->id)->where('date', $day)->exists())->toBeTrue();

    // Die 6-h-Schicht zählt mit 360 Minuten in die geplanten Minuten.
    $early2Shift = Shift::query()->where('shift_template_id', $early2->id)->first();
    expect((int) $early2Shift->starts_at->diffInMinutes($early2Shift->ends_at, true))->toBe(360);
});

it('books overtime onto the employee balance when a roster is published and reverses on reopen', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $template = createRosterGeneratorShiftTemplate($location);
    $employee = createRosterGeneratorEmployee($location, [
        'weekly_hours' => 39,
        'regular_work_days_per_week' => 5,
    ]);

    $roster = createRosterGeneratorRoster($location, $pdl);

    // 25 Dienste à 480 min = 12000 min; Monats-Soll Januar 2027 = 10363 min.
    foreach (range(1, 25) as $day) {
        createRosterGeneratorShift($roster, $employee, $template, sprintf('2027-01-%02d', $day));
    }

    $service = app(App\Services\Rosters\OvertimeBookingService::class);

    $service->bookForRoster($roster->refresh());
    expect($employee->employeeProfile->refresh()->overtime_minutes_balance)->toBe(12000 - 10363);

    // Idempotent: erneutes Buchen ändert nichts.
    $service->bookForRoster($roster->refresh());
    expect($employee->employeeProfile->refresh()->overtime_minutes_balance)->toBe(12000 - 10363);

    // Wieder-Öffnen nimmt die Buchung zurück.
    $service->reverseForRoster($roster->refresh());
    expect($employee->employeeProfile->refresh()->overtime_minutes_balance)->toBe(0);
});

it('lowers the planned target for an employee carrying an overtime surplus', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $template = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($template, ['required_total_staff' => 1, 'target_total_staff' => 2]);

    // Gleiches Profil, aber einer hat ein großes Überstunden-Guthaben.
    $rested = createRosterGeneratorEmployee($location, [], ['name' => 'Ohne Guthaben']);
    $surplus = createRosterGeneratorEmployee($location, [
        'overtime_minutes_balance' => 6000,
    ], ['name' => 'Mit Guthaben']);

    foreach (range(1, 4) as $ignored) {
        createRosterGeneratorEmployee($location);
    }

    $roster = createRosterGeneratorRoster($location, $pdl);
    app(RosterGeneratorService::class)->generate($roster);

    $restedShifts = Shift::query()->where('roster_id', $roster->id)->where('user_id', $rested->id)->count();
    $surplusShifts = Shift::query()->where('roster_id', $roster->id)->where('user_id', $surplus->id)->count();

    // Wer Überstunden mitbringt, wird weniger eingeplant (reduziertes Soll).
    expect($surplusShifts)->toBeLessThan($restedShifts);
});

it('respects the no-weekend special rule', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location, ['avoids_weekends' => true]);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $template = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($template);

    app(RosterGeneratorService::class)->generate($roster);

    $shifts = Shift::query()->where('user_id', $employee->id)->get();

    expect($shifts)->not->toBeEmpty()
        ->and($shifts->every(
            fn (Shift $s): bool => ! in_array(CarbonImmutable::parse($s->date)->dayOfWeekIso, [6, 7], true)
        ))->toBeTrue();
});

it('respects fixed free weekdays', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location, ['fixed_free_weekdays' => [1]]); // immer montags frei
    $roster = createRosterGeneratorRoster($location, $pdl);
    $template = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($template);

    app(RosterGeneratorService::class)->generate($roster);

    $shifts = Shift::query()->where('user_id', $employee->id)->get();

    expect($shifts)->not->toBeEmpty()
        ->and($shifts->every(
            fn (Shift $s): bool => CarbonImmutable::parse($s->date)->dayOfWeekIso !== 1
        ))->toBeTrue();
});

it('respects the alternating week rotation', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location, ['week_rotation' => 'even']);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $template = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($template);

    app(RosterGeneratorService::class)->generate($roster);

    $shifts = Shift::query()->where('user_id', $employee->id)->get();

    expect($shifts)->not->toBeEmpty()
        ->and($shifts->every(
            fn (Shift $s): bool => CarbonImmutable::parse($s->date)->isoWeek() % 2 === 0
        ))->toBeTrue();
});

it('respects an individual max consecutive days override', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterGeneratorEmployee($location, ['max_consecutive_days_override' => 2]);
    $roster = createRosterGeneratorRoster($location, $pdl);
    $template = createRosterGeneratorShiftTemplate($location);
    createRosterGeneratorStaffingRule($template);

    app(RosterGeneratorService::class)->generate($roster);

    $workDates = Shift::query()
        ->where('user_id', $employee->id)
        ->pluck('date')
        ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
        ->unique()
        ->sort()
        ->values();

    $longestRun = $workDates->count() > 0 ? 1 : 0;
    $currentRun = $longestRun;

    for ($index = 1; $index < $workDates->count(); $index++) {
        $currentRun = CarbonImmutable::parse($workDates[$index])
            ->equalTo(CarbonImmutable::parse($workDates[$index - 1])->addDay())
            ? $currentRun + 1
            : 1;
        $longestRun = max($longestRun, $currentRun);
    }

    expect($workDates)->not->toBeEmpty()
        ->and($longestRun)->toBeLessThanOrEqual(2);
});
