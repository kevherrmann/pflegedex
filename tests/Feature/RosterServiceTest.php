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
use App\Services\Rosters\RosterService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createRosterServiceRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createRosterServiceEmployee(Location $location, array $profileAttributes = []): User
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

function createRosterServiceShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

function createRosterServiceStaffingRule(ShiftTemplate $shiftTemplate, array $attributes = []): ShiftStaffingRule
{
    return ShiftStaffingRule::query()->create([
        'location_id' => $shiftTemplate->location_id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => $attributes['weekday'] ?? null,
        'required_total_staff' => $attributes['required_total_staff'] ?? 1,
        'required_specialists' => $attributes['required_specialists'] ?? 0,
    ]);
}

function createRosterServiceShift(
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

function rosterServiceJanuary2027Dates(): array
{
    $dates = [];

    for ($date = CarbonImmutable::create(2027, 1, 1); $date->month === 1; $date = $date->addDay()) {
        $dates[] = $date->toDateString();
    }

    return $dates;
}

function assertRosterServiceValidationField(callable $callback, string $field): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail("Expected ValidationException for field [{$field}].");
}

it('creates a new draft roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->for($location)->create();

    $roster = app(RosterService::class)->createOrGetDraft($location, $createdBy, 2027, 4);

    expect($roster->id)->not->toBeNull()
        ->and($roster->location_id)->toBe($location->id)
        ->and($roster->year)->toBe(2027)
        ->and($roster->month)->toBe(4)
        ->and($roster->status)->toBe(RosterStatus::Draft)
        ->and($roster->created_by)->toBe($createdBy->id)
        ->and(Roster::query()->count())->toBe(1);
});

it('returns an existing roster for location year and month without creating a second one', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $existing = createRosterServiceRoster($location, $createdBy, [
        'year' => 2027,
        'month' => 5,
        'status' => RosterStatus::Reviewed,
    ]);

    $roster = app(RosterService::class)->createOrGetDraft($location, User::factory()->create(), 2027, 5);

    expect($roster->id)->toBe($existing->id)
        ->and($roster->status)->toBe(RosterStatus::Reviewed)
        ->and(Roster::query()->count())->toBe(1);
});

it('allows the same month for different locations', function (): void {
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();
    $createdBy = User::factory()->create();
    $service = app(RosterService::class);

    $service->createOrGetDraft($firstLocation, $createdBy, 2027, 6);
    $service->createOrGetDraft($secondLocation, $createdBy, 2027, 6);

    expect(Roster::query()->count())->toBe(2);
});

it('rejects invalid months', function (int $month): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->createOrGetDraft($location, $createdBy, 2027, $month),
        'month',
    );
})->with([0, 13]);

it('rejects invalid years', function (int $year): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->createOrGetDraft($location, $createdBy, $year, 1),
        'year',
    );
})->with([2019, 2101]);

it('publishes a draft roster and sets published at', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Draft]);

    $publishedRoster = app(RosterService::class)->publish($roster);

    expect($publishedRoster->status)->toBe(RosterStatus::Published)
        ->and($publishedRoster->published_at)->not->toBeNull();
});

it('publishes a reviewed roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Reviewed]);

    $publishedRoster = app(RosterService::class)->publish($roster);

    expect($publishedRoster->status)->toBe(RosterStatus::Published)
        ->and($publishedRoster->published_at)->not->toBeNull();
});

it('rejects publishing a locked roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Locked]);

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->publish($roster),
        'status',
    );
});

it('blocks publishing a roster with validator errors', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Draft]);
    $shiftTemplate = createRosterServiceShiftTemplate($location);

    createRosterServiceStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->publish($roster),
        'status',
    );

    expect($roster->refresh()->status)->toBe(RosterStatus::Draft)
        ->and($roster->published_at)->toBeNull();
});

it('publishes a roster with warnings only', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Draft]);

    createRosterServiceShiftTemplate($location);

    $publishedRoster = app(RosterService::class)->publish($roster);

    expect($publishedRoster->status)->toBe(RosterStatus::Published)
        ->and($publishedRoster->published_at)->not->toBeNull();
});

it('publishes a green roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = createRosterServiceEmployee($location);
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Draft]);
    $shiftTemplate = createRosterServiceShiftTemplate($location);

    createRosterServiceStaffingRule($shiftTemplate, [
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    foreach (rosterServiceJanuary2027Dates() as $date) {
        createRosterServiceShift($roster, $employee, $shiftTemplate, $date);
    }

    $publishedRoster = app(RosterService::class)->publish($roster);

    expect($publishedRoster->status)->toBe(RosterStatus::Published)
        ->and($publishedRoster->published_at)->not->toBeNull();
});

it('locks a published roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, [
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);

    $lockedRoster = app(RosterService::class)->lock($roster);

    expect($lockedRoster->status)->toBe(RosterStatus::Locked);
});

it('rejects locking draft generated and reviewed rosters', function (RosterStatus $status): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => $status]);

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->lock($roster),
        'status',
    );
})->with([
    RosterStatus::Draft,
    RosterStatus::Generated,
    RosterStatus::Reviewed,
]);

it('reopens a published roster as reviewed and clears published at', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, [
        'status' => RosterStatus::Published,
        'published_at' => now(),
    ]);

    $reopenedRoster = app(RosterService::class)->reopen($roster);

    expect($reopenedRoster->status)->toBe(RosterStatus::Reviewed)
        ->and($reopenedRoster->published_at)->toBeNull();
});

it('rejects reopening a locked roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => RosterStatus::Locked]);

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->reopen($roster),
        'status',
    );
});

it('rejects reopening draft generated and reviewed rosters', function (RosterStatus $status): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterServiceRoster($location, $createdBy, ['status' => $status]);

    assertRosterServiceValidationField(
        fn () => app(RosterService::class)->reopen($roster),
        'status',
    );
})->with([
    RosterStatus::Draft,
    RosterStatus::Generated,
    RosterStatus::Reviewed,
]);
