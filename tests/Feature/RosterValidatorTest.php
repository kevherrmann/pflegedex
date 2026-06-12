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
        'source' => ShiftSource::Manual,
        'note' => null,
    ]);
}

function createRosterValidatorAbsenceRequest(User $employee, User $requestedBy, array $attributes = []): AbsenceRequest
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
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 1,
    ]);

    foreach (rosterValidatorDatesForJanuary2027() as $date) {
        // Jeweils eine eigene Aushilfe ohne vertragliches Soll (weder Stunden
        // noch Regeltage), damit der Tag besetzt ist und keine Soll-Warnung entsteht.
        $specialist = createRosterValidatorEmployee($location, [
            'is_nursing_specialist' => true,
            'weekly_hours' => 0.00,
            'regular_work_days_per_week' => 0,
        ]);

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
    $error = collect($result->errors)->first(
        fn (array $error): bool => $error['code'] === 'understaffed_shift',
    );

    expect($error)->not->toBeNull()
        ->and($error['context']['shiftTemplateName'])->toBe('Frühdienst')
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
        ->and($restError['context']['employeeName'])->toBe($employee->name)
        ->and($restError['context']['previousShiftId'])->toBe($previousShift->id)
        ->and($restError['context']['previousShiftDate'])->toBe('2027-01-10')
        ->and($restError['context']['previousShiftTemplateName'])->toBe('Spätdienst')
        ->and($restError['context']['previousShiftEndsAt'])->toBe('2027-01-10 22:00:00')
        ->and($restError['context']['nextShiftId'])->toBe($nextShift->id)
        ->and($restError['context']['nextShiftDate'])->toBe('2027-01-11')
        ->and($restError['context']['nextShiftTemplateName'])->toBe('Frühdienst')
        ->and($restError['context']['nextShiftStartsAt'])->toBe('2027-01-11 06:00:00')
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
    $green = new RosterValidationResult;
    $yellow = new RosterValidationResult;
    $red = new RosterValidationResult;

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

it('stores title and details for validation errors', function (): void {
    $result = new RosterValidationResult;

    $result->addError(
        'test_error',
        'Fehler',
        ['field' => 'value'],
        'Fehlertitel',
        'Ausführlicher Fehlerhinweis.',
    );

    expect($result->errors[0])->toMatchArray([
        'code' => 'test_error',
        'message' => 'Fehler',
        'context' => ['field' => 'value'],
        'title' => 'Fehlertitel',
        'details' => 'Ausführlicher Fehlerhinweis.',
    ]);
});

it('stores title and details for validation warnings', function (): void {
    $result = new RosterValidationResult;

    $result->addWarning(
        'test_warning',
        'Hinweis',
        ['field' => 'value'],
        'Hinweistitel',
        'Ausführlicher Hinweis.',
    );

    expect($result->warnings[0])->toMatchArray([
        'code' => 'test_warning',
        'message' => 'Hinweis',
        'context' => ['field' => 'value'],
        'title' => 'Hinweistitel',
        'details' => 'Ausführlicher Hinweis.',
    ]);
});

it('stores null title and details when they are omitted', function (): void {
    $result = new RosterValidationResult;

    $result->addError('test_error', 'Fehler');
    $result->addWarning('test_warning', 'Hinweis');

    expect($result->errors[0]['title'])->toBeNull()
        ->and($result->errors[0]['details'])->toBeNull()
        ->and($result->warnings[0]['title'])->toBeNull()
        ->and($result->warnings[0]['details'])->toBeNull();
});

it('adds an employee absent error when a shift overlaps approved absence', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);
    $shift = createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');
    $absenceRequest = createRosterValidatorAbsenceRequest($employee, $createdBy, [
        'starts_on' => '2027-01-10',
        'ends_on' => '2027-01-10',
        'type' => AbsenceRequestType::Vacation,
        'status' => AbsenceRequestStatus::Approved,
    ]);

    $result = app(RosterValidator::class)->validate($roster);
    $absenceError = collect($result->errors)->first(
        fn (array $error): bool => $error['code'] === 'employee_absent',
    );

    expect($absenceError)->not->toBeNull()
        ->and($absenceError['context']['userId'])->toBe($employee->id)
        ->and($absenceError['context']['employeeName'])->toBe($employee->name)
        ->and($absenceError['context']['shiftId'])->toBe($shift->id)
        ->and($absenceError['context']['shiftTemplateName'])->toBe('Frühdienst')
        ->and($absenceError['context']['date'])->toBe('2027-01-10')
        ->and($absenceError['context']['absenceRequestId'])->toBe($absenceRequest->id)
        ->and($absenceError['context']['absenceType'])->toBe(AbsenceRequestType::Vacation->value)
        ->and($absenceError['context']['absenceStartsOn'])->toBe('2027-01-10')
        ->and($absenceError['context']['absenceEndsOn'])->toBe('2027-01-10');
});

it('does not add employee absent errors for requested or rejected absences', function (AbsenceRequestStatus $status): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');
    createRosterValidatorAbsenceRequest($employee, $createdBy, [
        'starts_on' => '2027-01-10',
        'ends_on' => '2027-01-10',
        'status' => $status,
        'decided_by' => $status === AbsenceRequestStatus::Requested ? null : $createdBy->id,
        'decided_at' => $status === AbsenceRequestStatus::Requested ? null : now(),
        'rejection_reason' => $status === AbsenceRequestStatus::Rejected ? 'Nicht genehmigt' : null,
    ]);

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->errors))->not->toContain('employee_absent');
})->with([
    AbsenceRequestStatus::Requested,
    AbsenceRequestStatus::Rejected,
]);

it('detects employee absent errors for night shifts when absence is on the following day', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['can_work_night' => true]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    $shift = createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');

    createRosterValidatorAbsenceRequest($employee, $createdBy, [
        'starts_on' => '2027-01-11',
        'ends_on' => '2027-01-11',
        'status' => AbsenceRequestStatus::Approved,
    ]);

    $result = app(RosterValidator::class)->validate($roster);
    $absenceError = collect($result->errors)->first(
        fn (array $error): bool => $error['code'] === 'employee_absent',
    );

    expect($absenceError)->not->toBeNull()
        ->and($absenceError['context']['shiftId'])->toBe($shift->id)
        ->and($absenceError['context']['absenceStartsOn'])->toBe('2027-01-11');
});

it('adds an employee over planned hours warning when planned minutes exceed target plus tolerance', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 1.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');
    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-11');

    $result = app(RosterValidator::class)->validate($roster);
    $warning = collect($result->warnings)->first(
        fn (array $warning): bool => $warning['code'] === 'employee_over_planned_hours',
    );

    expect($warning)->not->toBeNull()
        ->and($warning['context']['userId'])->toBe($employee->id)
        ->and($warning['context']['plannedMinutes'])->toBe(960)
        ->and($warning['context']['targetMinutes'])->toBe(266)
        ->and($warning['context']['overtimeMinutes'])->toBe(694)
        ->and($warning['context']['weeklyHours'])->toBe(1.0)
        ->and($warning['context']['month'])->toBe(1)
        ->and($warning['context']['year'])->toBe(2027);
});

it('does not add an employee over planned hours warning within target plus tolerance', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 40.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_over_planned_hours');
});

it('reports over planned working hours as warning without errors', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 1.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');
    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-11');

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->errors)->toBeEmpty()
        ->and(rosterValidatorCodes($result->warnings))->toContain('employee_over_planned_hours')
        ->and($result->isYellow())->toBeTrue();
});

it('ignores planned working hours for employees without employee profiles', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = User::factory()->for($location)->create();
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-10');
    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-11');

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_over_planned_hours');
});

it('adds an under planned warning when an employee is planned well below capacity', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    // Voller Vertrag (39 h / 5 Tage), aber nur ein einziger Dienst geplant.
    $employee = createRosterValidatorEmployee($location, [
        'weekly_hours' => 39.00,
        'regular_work_days_per_week' => 5,
    ]);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-05');

    $result = app(RosterValidator::class)->validate($roster);

    $warning = collect($result->warnings)
        ->first(fn (array $warning): bool => $warning['code'] === 'employee_under_planned_hours');

    expect($warning)->not->toBeNull()
        ->and($warning['context']['userId'])->toBe($employee->id)
        ->and($result->isYellow())->toBeTrue();
});

it('does not add an under planned warning for employees without contractual capacity', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    // Aushilfe ohne vertragliches Soll (weder Stunden noch Regeltage).
    $employee = createRosterValidatorEmployee($location, [
        'weekly_hours' => 0.00,
        'regular_work_days_per_week' => 0,
    ]);

    createRosterValidatorShift($roster, $employee, $shiftTemplate, '2027-01-05');

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_under_planned_hours');
});

it('adds a too many consecutive work days warning for seven planned days in a row', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 80.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(1, 7) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);
    $warning = collect($result->warnings)->first(
        fn (array $warning): bool => $warning['code'] === 'employee_too_many_consecutive_work_days',
    );

    expect($warning)->not->toBeNull()
        ->and($warning['context']['userId'])->toBe($employee->id)
        ->and($warning['context']['consecutiveDays'])->toBe(7)
        ->and($warning['context']['maxAllowedConsecutiveDays'])->toBe(6)
        ->and($warning['context']['startsOn'])->toBe('2027-01-01')
        ->and($warning['context']['endsOn'])->toBe('2027-01-07')
        ->and($warning['context']['month'])->toBe(1)
        ->and($warning['context']['year'])->toBe(2027);
});

it('does not add a too many consecutive work days warning for six planned days in a row', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 80.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(1, 6) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_too_many_consecutive_work_days');
});

it('counts multiple shifts on the same date as one consecutive work day', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 80.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'active' => false,
        'starts_at' => '06:00',
        'ends_at' => '07:00',
        'duration_minutes' => 60,
    ]);
    $lateShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'active' => false,
        'starts_at' => '18:00',
        'ends_at' => '19:00',
        'duration_minutes' => 60,
    ]);

    createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, '2027-01-01');
    createRosterValidatorShift($roster, $employee, $lateShiftTemplate, '2027-01-01');

    foreach (range(2, 6) as $day) {
        createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_too_many_consecutive_work_days');
});

it('adds separate warnings for separate too long consecutive work day sequences', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 80.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach ([...range(1, 7), ...range(9, 15)] as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);
    $warnings = collect($result->warnings)
        ->filter(fn (array $warning): bool => $warning['code'] === 'employee_too_many_consecutive_work_days')
        ->values();

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0]['context']['startsOn'])->toBe('2027-01-01')
        ->and($warnings[0]['context']['endsOn'])->toBe('2027-01-07')
        ->and($warnings[1]['context']['startsOn'])->toBe('2027-01-09')
        ->and($warnings[1]['context']['endsOn'])->toBe('2027-01-15');
});

it('reports too many consecutive work days as warning without errors', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 80.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(1, 7) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->errors)->toBeEmpty()
        ->and(rosterValidatorCodes($result->warnings))->toContain('employee_too_many_consecutive_work_days')
        ->and($result->isYellow())->toBeTrue();
});

it('adds a too many weekends warning when an employee works three weekends', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 120.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (['2027-01-02', '2027-01-09', '2027-01-16'] as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);
    $warning = collect($result->warnings)->first(
        fn (array $warning): bool => $warning['code'] === 'employee_too_many_weekends',
    );

    expect($warning)->not->toBeNull()
        ->and($warning['context']['userId'])->toBe($employee->id)
        ->and($warning['context']['employeeName'])->toBe($employee->name)
        ->and($warning['context']['workedWeekends'])->toBe(3)
        ->and($warning['context']['maxAllowedWeekends'])->toBe(2)
        ->and($warning['context']['weekendStartsOn'])->toBe([
            '2027-01-02',
            '2027-01-09',
            '2027-01-16',
        ])
        ->and($warning['context']['month'])->toBe(1)
        ->and($warning['context']['year'])->toBe(2027)
        ->and($warning['title'])->toBe('Zu viele Wochenenden geplant')
        ->and($warning['details'])->toBe('Der Mitarbeiter ist an mehr Wochenenden eingeplant als empfohlen.');
});

it('does not add a too many weekends warning when an employee works two weekends', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 120.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (['2027-01-02', '2027-01-09'] as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_too_many_weekends');
});

it('counts Saturday and Sunday of the same weekend once', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 120.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (['2027-01-02', '2027-01-03', '2027-01-09', '2027-01-16'] as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);
    $warning = collect($result->warnings)->first(
        fn (array $warning): bool => $warning['code'] === 'employee_too_many_weekends',
    );

    expect($warning)->not->toBeNull()
        ->and($warning['context']['workedWeekends'])->toBe(3)
        ->and($warning['context']['weekendStartsOn'])->toBe([
            '2027-01-02',
            '2027-01-09',
            '2027-01-16',
        ]);
});

it('does not count regular weekdays as worked weekends', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 120.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (['2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08'] as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_too_many_weekends');
});

it('reports too many weekends as warning without errors', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 120.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (['2027-01-02', '2027-01-09', '2027-01-16'] as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->errors)->toBeEmpty()
        ->and(rosterValidatorCodes($result->warnings))->toContain('employee_too_many_weekends')
        ->and($result->isYellow())->toBeTrue();
});

it('adds a no free day in month warning when an employee works every day in January', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (rosterValidatorDatesForJanuary2027() as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);
    $warning = collect($result->warnings)->first(
        fn (array $warning): bool => $warning['code'] === 'employee_has_no_free_day_in_month',
    );

    expect($warning)->not->toBeNull()
        ->and($warning['context']['userId'])->toBe($employee->id)
        ->and($warning['context']['workedDays'])->toBe(31)
        ->and($warning['context']['daysInMonth'])->toBe(31)
        ->and($warning['context']['month'])->toBe(1)
        ->and($warning['context']['year'])->toBe(2027);
});

it('does not add a no free day in month warning when an employee has at least one day off', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(1, 30) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_has_no_free_day_in_month');
});

it('counts multiple shifts on the same date as one worked day for monthly free days', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'active' => false,
        'starts_at' => '06:00',
        'ends_at' => '07:00',
        'duration_minutes' => 60,
    ]);
    $lateShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'active' => false,
        'starts_at' => '18:00',
        'ends_at' => '19:00',
        'duration_minutes' => 60,
    ]);

    createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, '2027-01-01');
    createRosterValidatorShift($roster, $employee, $lateShiftTemplate, '2027-01-01');

    foreach (range(2, 30) as $day) {
        createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('employee_has_no_free_day_in_month');
});

it('reports no free day in month as warning without errors', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (rosterValidatorDatesForJanuary2027() as $date) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->errors)->toBeEmpty()
        ->and(rosterValidatorCodes($result->warnings))->toContain('employee_has_no_free_day_in_month')
        ->and($result->isYellow())->toBeTrue();
});

it('adds a missing sunday compensation rest day warning when no known free day exists in the window', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(4, 17) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);
    $warning = collect($result->warnings)->first(
        fn (array $warning): bool => $warning['code'] === 'missing_sunday_compensation_rest_day'
            && $warning['context']['sunday'] === '2027-01-10',
    );

    expect($warning)->not->toBeNull()
        ->and($warning['context']['userId'])->toBe($employee->id)
        ->and($warning['context']['compensationWindowStartsOn'])->toBe('2027-01-04')
        ->and($warning['context']['compensationWindowEndsOn'])->toBe('2027-01-17')
        ->and($warning['context']['month'])->toBe(1)
        ->and($warning['context']['year'])->toBe(2027);
});

it('does not add a missing sunday compensation rest day warning when a known free day exists in the window', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(4, 17) as $day) {
        if ($day === 13) {
            continue;
        }

        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('missing_sunday_compensation_rest_day');
});

it('adds only one missing sunday compensation rest day warning for multiple shifts on the same sunday', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'active' => false,
        'starts_at' => '06:00',
        'ends_at' => '07:00',
        'duration_minutes' => 60,
    ]);
    $lateShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'active' => false,
        'starts_at' => '18:00',
        'ends_at' => '19:00',
        'duration_minutes' => 60,
    ]);

    foreach (range(4, 17) as $day) {
        createRosterValidatorShift($roster, $employee, $earlyShiftTemplate, sprintf('2027-01-%02d', $day));
    }

    createRosterValidatorShift($roster, $employee, $lateShiftTemplate, '2027-01-10');

    $result = app(RosterValidator::class)->validate($roster);
    $warnings = collect($result->warnings)
        ->filter(fn (array $warning): bool => $warning['code'] === 'missing_sunday_compensation_rest_day'
            && $warning['context']['sunday'] === '2027-01-10')
        ->values();

    expect($warnings)->toHaveCount(1);
});

it('does not run sunday compensation checks for weekdays', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(4, 9) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect(rosterValidatorCodes($result->warnings))->not->toContain('missing_sunday_compensation_rest_day');
});

it('reports missing sunday compensation rest days as warning without errors', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterValidatorEmployee($location, ['weekly_hours' => 300.00]);
    $roster = createRosterValidatorRoster($location, $createdBy);
    $shiftTemplate = createRosterValidatorShiftTemplate($location, ['active' => false]);

    foreach (range(4, 17) as $day) {
        createRosterValidatorShift($roster, $employee, $shiftTemplate, sprintf('2027-01-%02d', $day));
    }

    $result = app(RosterValidator::class)->validate($roster);

    expect($result->errors)->toBeEmpty()
        ->and(rosterValidatorCodes($result->warnings))->toContain('missing_sunday_compensation_rest_day')
        ->and($result->isYellow())->toBeTrue();
});

it('reports rest period violations across the month boundary', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterValidatorEmployee($location, ['can_work_night' => true]);
    $decemberRoster = createRosterValidatorRoster($location, $pdl, ['year' => 2026, 'month' => 12]);
    $januaryRoster = createRosterValidatorRoster($location, $pdl);
    $nightShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
    ]);

    // Nachtdienst am 31.12. endet am 1.1. um 06:00 — der Frühdienst am 1.1. startet ohne Ruhezeit.
    createRosterValidatorShift($decemberRoster, $employee, $nightShiftTemplate, '2026-12-31');
    createRosterValidatorShift($januaryRoster, $employee, $earlyShiftTemplate, '2027-01-01');

    $result = app(RosterValidator::class)->validate($januaryRoster);

    $restViolation = collect($result->errors)
        ->first(fn (array $entry): bool => $entry['code'] === 'rest_period_violation');

    expect($restViolation)->not->toBeNull()
        ->and($restViolation['context']['previousShiftDate'])->toBe('2026-12-31')
        ->and($restViolation['context']['nextShiftDate'])->toBe('2027-01-01');
});

it('reports consecutive work day warnings across the month boundary', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterValidatorEmployee($location);
    $decemberRoster = createRosterValidatorRoster($location, $pdl, ['year' => 2026, 'month' => 12]);
    $januaryRoster = createRosterValidatorRoster($location, $pdl);
    $shiftTemplate = createRosterValidatorShiftTemplate($location);

    // 28.-31.12. plus 1.-3.1.: zusammen 7 Tage am Stück über die Monatsgrenze.
    foreach (['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31'] as $date) {
        createRosterValidatorShift($decemberRoster, $employee, $shiftTemplate, $date);
    }

    foreach (['2027-01-01', '2027-01-02', '2027-01-03'] as $date) {
        createRosterValidatorShift($januaryRoster, $employee, $shiftTemplate, $date);
    }

    $result = app(RosterValidator::class)->validate($januaryRoster);

    $warning = collect($result->warnings)
        ->first(fn (array $entry): bool => $entry['code'] === 'employee_too_many_consecutive_work_days');

    expect($warning)->not->toBeNull()
        ->and($warning['context']['consecutiveDays'])->toBe(7)
        ->and($warning['context']['startsOn'])->toBe('2026-12-28')
        ->and($warning['context']['endsOn'])->toBe('2027-01-03');
});

it('does not report violations that only concern foreign rosters', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $employee = createRosterValidatorEmployee($location, ['can_work_night' => true]);
    $decemberRoster = createRosterValidatorRoster($location, $pdl, ['year' => 2026, 'month' => 12]);
    $januaryRoster = createRosterValidatorRoster($location, $pdl);
    $nightShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);
    $earlyShiftTemplate = createRosterValidatorShiftTemplate($location, [
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
    ]);

    // Ruhezeitverstoß komplett im Dezember-Plan: Der Januar-Plan meldet ihn nicht.
    createRosterValidatorShift($decemberRoster, $employee, $nightShiftTemplate, '2026-12-30');
    createRosterValidatorShift($decemberRoster, $employee, $earlyShiftTemplate, '2026-12-31');
    createRosterValidatorShift($januaryRoster, $employee, $earlyShiftTemplate, '2027-01-15');

    $result = app(RosterValidator::class)->validate($januaryRoster);

    expect(collect($result->errors)
        ->where('code', 'rest_period_violation'))->toBeEmpty();
});

it('counts weekend shifts from other rosters of the same month for the weekend load', function (): void {
    $homeLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = User::factory()->for($homeLocation)->create();
    $employee = createRosterValidatorEmployee($homeLocation);
    $homeRoster = createRosterValidatorRoster($homeLocation, $pdl);
    $otherRoster = createRosterValidatorRoster($otherLocation, $pdl);
    $homeTemplate = createRosterValidatorShiftTemplate($homeLocation);
    $otherTemplate = createRosterValidatorShiftTemplate($otherLocation);

    // Zwei Wochenenden im eigenen Plan (2./3.1. und 9.1.), eines am anderen Standort (16.1.).
    foreach (['2027-01-02', '2027-01-09'] as $date) {
        createRosterValidatorShift($homeRoster, $employee, $homeTemplate, $date);
    }

    createRosterValidatorShift($otherRoster, $employee, $otherTemplate, '2027-01-16');

    $result = app(RosterValidator::class)->validate($homeRoster);

    $warning = collect($result->warnings)
        ->first(fn (array $entry): bool => $entry['code'] === 'employee_too_many_weekends');

    expect($warning)->not->toBeNull()
        ->and($warning['context']['workedWeekends'])->toBe(3);
});
