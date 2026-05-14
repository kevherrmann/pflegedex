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
use App\Services\Rosters\RosterValidationResult;
use App\Services\Rosters\RosterValidator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRosterValidatorRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createRosterValidatorShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

function createRosterValidatorStaffingRule(ShiftTemplate $shiftTemplate, array $attributes = []): ShiftStaffingRule
{
    return ShiftStaffingRule::query()->create([
        'location_id' => $shiftTemplate->location_id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => $attributes['weekday'] ?? null,
        'required_total_staff' => $attributes['required_total_staff'] ?? 1,
        'required_specialists' => $attributes['required_specialists'] ?? 0,
    ]);
}

function createRosterValidatorEmployee(Location $location, array $profileAttributes = []): User
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

function createRosterValidatorShift(
    Roster $roster,
    User $employee,
    ShiftTemplate $shiftTemplate,
    string $date,
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
        'source' => ShiftSource::Manual,
        'note' => null,
    ]);
}

function rosterValidatorDatesForJanuary2027(): array
{
    $dates = [];

    for ($date = CarbonImmutable::create(2027, 1, 1); $date->month === 1; $date = $date->addDay()) {
        $dates[] = $date->toDateString();
    }

    return $dates;
}

function rosterValidatorCodes(array $entries): array
{
    return array_map(fn (array $entry): string => $entry['code'], $entries);
}

it('is green when every shift in the month meets staffing and specialist requirements', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $specialist = createRosterValidatorEmployee($location, ['is_nursing_specialist' => true]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 1,
    ]);

    foreach (rosterValidatorDatesForJanuary2027() as $date) {
        createRosterValidatorShift($roster, $specialist, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->isGreen())->toBeTrue()
        ->and($result->errors)->toBeEmpty()
        ->and($result->warnings)->toBeEmpty();
});

it('adds a missing staffing rule warning', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterValidatorRoster($location, $createdBy);

    createRosterValidatorShiftTemplate($location);

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->warnings)->not->toBeEmpty()
        ->and(rosterValidatorCodes($result->warnings))->toContain('missing_staffing_rule')
        ->and($result->isYellow())->toBeTrue();
});

it('adds an understaffed shift error', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorStaffingRule($shiftTemplate, [
        'required_total_staff' => 2,
        'required_specialists' => 0,
    ]);
    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->errors))->toContain('understaffed_shift')
        ->and($result->isRed())->toBeTrue();
});

it('adds a missing specialist error', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['is_nursing_specialist' => false]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 1,
    ]);
    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->errors))->toContain('missing_specialist');
});

it('lets weekday specific staffing rules override default rules', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorStaffingRule($shiftTemplate, [
        'weekday' => null,
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);
    createRosterValidatorStaffingRule($shiftTemplate, [
        'weekday' => 1,
        'required_total_staff' => 2,
        'required_specialists' => 0,
    ]);

    foreach (rosterValidatorDatesForJanuary2027() as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);
    $mondayError = collect($result->errors)->first(
        fn (array $error): bool => $error['code'] === 'understaffed_shift'
            && $error['context']['date'] === '2027-01-04',
    );

    expect($mondayError)->not->toBeNull()
        ->and($mondayError['context']['requiredTotalStaff'])->toBe(2)
        ->and($mondayError['context']['actualTotalStaff'])->toBe(1);
});

it('detects rest period violations', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $lateShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'color' => '#3B82F6',
    ]);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
    ]);

    $previousShift = createRosterValidatorShift($roster, $employee, $lateShiftTemplate, '2027-01-10');
    $nextShift = createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, '2027-01-11');

    $result = app(RosterValidator::class)->validate($roster);
    $restError = collect($result->errors)->first(
        fn (array $error): bool => $error['code'] === 'rest_period_violation',
    );

    expect($restError)->not->toBeNull()
        ->and($restError['context']['previousShiftId'])->toBe($previousShift->id)
        ->and($restError['context']['nextShiftId'])->toBe($nextShift->id)
        ->and($restError['context']['restMinutes'])->toBe(480)
        ->and($restError['context']['requiredRestMinutes'])->toBe(660);
});

it('does not add rest period errors when rest is sufficient', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, '2027-01-10');
    createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, '2027-01-11');

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->errors))->not->toContain('rest_period_violation');
});

it('reports green yellow and red result states', function (): void {
    $green = new RosterValidationResult();
    $yellow = new RosterValidationResult();
    $red = new RosterValidationResult();

    $yellow->addWarning('warning', 'Hinweis');
    $red->addError('error', 'Fehler');

    expect($green->isGreen())->toBeTrue()
        ->and($green->isYellow())->toBeFalse()
        ->and($green->isRed())->toBeFalse()
        ->and($yellow->isGreen())->toBeFalse()
        ->and($yellow->isYellow())->toBeTrue()
        ->and($yellow->isRed())->toBeFalse()
        ->and($red->isGreen())->toBeFalse()
        ->and($red->isYellow())->toBeFalse()
        ->and($red->isRed())->toBeTrue();
});
