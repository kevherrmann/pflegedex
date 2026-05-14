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
use App\Services\Rosters\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createShiftServiceRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createShiftServiceShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

function createShiftServiceEmployee(Location $location, array $profileAttributes = []): User
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

function assertShiftServiceValidationField(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail("Expected ValidationException for field [{$field}].");
}

it('assigns an early manual shift with relations and times', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    $shift = app(ShiftService::class)->assignManualShift(
        $roster,
        $employee,
        $shiftTemplate,
        '2027-01-10',
    );

    expect($shift)->toBeInstanceOf(Shift::class)
        ->and($shift->roster_id)->toBe($roster->id)
        ->and($shift->location_id)->toBe($location->id)
        ->and($shift->user_id)->toBe($employee->id)
        ->and($shift->shift_template_id)->toBe($shiftTemplate->id)
        ->and($shift->date->toDateString())->toBe('2027-01-10')
        ->and($shift->starts_at->format('Y-m-d H:i:s'))->toBe('2027-01-10 06:00:00')
        ->and($shift->ends_at->format('Y-m-d H:i:s'))->toBe('2027-01-10 14:00:00')
        ->and($shift->source)->toBe(ShiftSource::Manual)
        ->and($shift->roster->id)->toBe($roster->id)
        ->and($shift->location->id)->toBe($location->id)
        ->and($shift->user->id)->toBe($employee->id)
        ->and($shift->shiftTemplate->id)->toBe($shiftTemplate->id);
});

it('ends night shifts on the following day', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location, ['can_work_night' => true]);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'color' => '#6366F1',
    ]);

    $shift = app(ShiftService::class)->assignManualShift(
        $roster,
        $employee,
        $shiftTemplate,
        '2027-01-10',
    );

    expect($shift->starts_at->format('Y-m-d H:i:s'))->toBe('2027-01-10 22:00:00')
        ->and($shift->ends_at->format('Y-m-d H:i:s'))->toBe('2027-01-11 06:00:00');
});

it('blocks non editable published and locked rosters', function (RosterStatus $status): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy, ['status' => $status]);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'status',
    );
})->with([
    RosterStatus::Published,
    RosterStatus::Locked,
]);

it('blocks employees without an employee profile', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = User::factory()->for($location)->create();
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'user_id',
    );
});

it('blocks inactive employee profiles', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location, ['active' => false]);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'user_id',
    );
});

it('blocks cleaning staff', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location, ['employment_area' => EmploymentArea::Cleaning]);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'user_id',
    );
});

it('blocks caretakers', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location, ['employment_area' => EmploymentArea::Caretaker]);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'user_id',
    );
});

it('blocks shift templates from another location', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($otherLocation);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'shift_template_id',
    );
});

it('blocks dates outside the roster month', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-02-01'),
        'date',
    );
});

it('blocks employees from night shifts when they cannot work nights', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location, ['can_work_night' => false]);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);

    assertShiftServiceValidationField(
        fn () => app(ShiftService::class)->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'shift_template_id',
    );
});

it('allows employees to work night shifts when they can work nights', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location, ['can_work_night' => true]);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);

    $shift = app(ShiftService::class)->assignManualShift(
        $roster,
        $employee,
        $shiftTemplate,
        '2027-01-10',
    );

    expect($shift->shift_template_id)->toBe($shiftTemplate->id)
        ->and($shift->source)->toBe(ShiftSource::Manual);
});

it('blocks duplicate same shifts on the same day before the database constraint', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);
    $service = app(ShiftService::class);

    $service->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10');

    assertShiftServiceValidationField(
        fn () => $service->assignManualShift($roster, $employee, $shiftTemplate, '2027-01-10'),
        'user_id',
    );
});

it('allows assigning different shift templates to the same employee on the same day', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy);
    $earlyShiftTemplate = createShiftServiceShiftTemplate($location);
    $lateShiftTemplate = createShiftServiceShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'color' => '#3B82F6',
    ]);
    $service = app(ShiftService::class);

    $service->assignManualShift($roster, $employee, $earlyShiftTemplate, '2027-01-10');
    $service->assignManualShift($roster, $employee, $lateShiftTemplate, '2027-01-10');

    expect(Shift::query()->count())->toBe(2);
});

it('stores notes on manual shifts', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createShiftServiceEmployee($location);
    $roster = createShiftServiceRoster($location, $createdBy);
    $shiftTemplate = createShiftServiceShiftTemplate($location);

    $shift = app(ShiftService::class)->assignManualShift(
        $roster,
        $employee,
        $shiftTemplate,
        '2027-01-10',
        'Einarbeitung beachten',
    );

    expect($shift->note)->toBe('Einarbeitung beachten');
});
