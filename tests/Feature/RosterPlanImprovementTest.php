<?php

use App\Enums\EmploymentArea;
use App\Enums\RosterStatus;
use App\Enums\ShiftWishKind;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\ShiftWish;
use App\Models\User;
use App\Services\Rosters\RosterGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planImprovementRoster(Location $location, User $createdBy, array $attributes = []): Roster
{
    return Roster::query()->create([
        'location_id' => $location->id,
        'year' => $attributes['year'] ?? 2027,
        'month' => $attributes['month'] ?? 1,
        'status' => RosterStatus::Draft,
        'created_by' => $createdBy->id,
    ]);
}

function planImprovementEmployee(Location $location, array $profileAttributes = [], array $userAttributes = []): User
{
    $employee = User::factory()->for($location)->create($userAttributes);

    EmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employment_area' => EmploymentArea::Nursing,
        'is_nursing_specialist' => $profileAttributes['is_nursing_specialist'] ?? false,
        'weekly_hours' => $profileAttributes['weekly_hours'] ?? 39.00,
        'regular_work_days_per_week' => $profileAttributes['regular_work_days_per_week'] ?? 5,
        'annual_vacation_days' => 30,
        'vacation_days_carried_over' => 0,
        'overtime_minutes_balance' => 0,
        'can_work_early' => $profileAttributes['can_work_early'] ?? true,
        'can_work_late' => $profileAttributes['can_work_late'] ?? true,
        'can_work_night' => $profileAttributes['can_work_night'] ?? false,
        'active' => true,
    ]);

    return $employee->refresh();
}

function planImprovementTemplate(Location $location, array $attributes = []): ShiftTemplate
{
    return ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => $attributes['name'] ?? 'Frühdienst',
        'code' => $attributes['code'] ?? 'early',
        'starts_at' => $attributes['starts_at'] ?? '06:00',
        'ends_at' => $attributes['ends_at'] ?? '14:00',
        'duration_minutes' => $attributes['duration_minutes'] ?? 480,
        'color' => $attributes['color'] ?? '#F59E0B',
        'active' => true,
    ]);
}

function planImprovementRule(ShiftTemplate $shiftTemplate, array $attributes = []): ShiftStaffingRule
{
    return ShiftStaffingRule::query()->create([
        'location_id' => $shiftTemplate->location_id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => $attributes['weekday'] ?? null,
        'required_total_staff' => $attributes['required_total_staff'] ?? 1,
        'required_specialists' => $attributes['required_specialists'] ?? 0,
    ]);
}

function planImprovementShiftTuples(Roster $roster): array
{
    return Shift::query()
        ->where('roster_id', $roster->id)
        ->get()
        ->map(fn (Shift $shift): string => implode('|', [
            $shift->user_id,
            $shift->shift_template_id,
            $shift->date->toDateString(),
        ]))
        ->sort()
        ->values()
        ->all();
}

it('produces identical rosters for identical input', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    foreach (range(1, 4) as $index) {
        planImprovementEmployee($location, ['can_work_night' => true], ['name' => sprintf('Pflegekraft %02d', $index)]);
    }

    $roster = planImprovementRoster($location, $pdl);
    planImprovementRule(planImprovementTemplate($location), ['required_total_staff' => 2]);
    planImprovementRule(planImprovementTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'color' => '#1D4ED8',
    ]));

    $generator = app(RosterGeneratorService::class);

    $generator->generate($roster);
    $firstRun = planImprovementShiftTuples($roster);

    $generator->generate($roster->refresh());
    $secondRun = planImprovementShiftTuples($roster);

    expect($firstRun)->not->toBeEmpty()
        ->and($secondRun)->toBe($firstRun);
});

it('does not increase the soft penalty during improvement', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    foreach (range(1, 6) as $index) {
        planImprovementEmployee($location, [
            'can_work_night' => $index % 2 === 0,
            'weekly_hours' => $index <= 3 ? 39.00 : 25.00,
        ], ['name' => sprintf('Pflegekraft %02d', $index)]);
    }

    $roster = planImprovementRoster($location, $pdl);
    planImprovementRule(planImprovementTemplate($location), ['required_total_staff' => 2]);
    planImprovementRule(planImprovementTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'color' => '#7C3AED',
    ]));

    $result = app(RosterGeneratorService::class)->generate($roster);

    // penaltyTotal trägt die Strafsenkung der Verbesserungsphase (<= 0).
    expect($result->penaltyTotal)->toBeLessThanOrEqual(0);
});

it('distributes night shifts evenly across capable employees', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    $employees = collect(range(1, 4))->map(fn (int $index): User => planImprovementEmployee(
        $location,
        ['can_work_night' => true],
        ['name' => sprintf('Nachtkraft %02d', $index)],
    ));

    $roster = planImprovementRoster($location, $pdl);
    planImprovementRule(planImprovementTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'color' => '#1D4ED8',
    ]));

    app(RosterGeneratorService::class)->generate($roster);

    $nightCounts = $employees->map(fn (User $employee): int => Shift::query()
        ->where('roster_id', $roster->id)
        ->where('user_id', $employee->id)
        ->count());

    expect($nightCounts->sum())->toBe(31)
        ->and($nightCounts->max() - $nightCounts->min())->toBeLessThanOrEqual(1);
});

it('respects wish-free days when coverage allows it', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $wishing = planImprovementEmployee($location, [], ['name' => 'Anna Wunsch']);
    planImprovementEmployee($location, [], ['name' => 'Berta Deckung']);

    $roster = planImprovementRoster($location, $pdl);
    planImprovementRule(planImprovementTemplate($location));

    // Dienstag, 5.1.2027 als Wunschfrei.
    ShiftWish::query()->create([
        'user_id' => $wishing->id,
        'location_id' => $location->id,
        'date' => '2027-01-05',
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $pdl->id,
    ]);

    $result = app(RosterGeneratorService::class)->generate($roster);

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('user_id', $wishing->id)
        ->whereDate('date', '2027-01-05')
        ->exists())->toBeFalse()
        ->and(collect($result->warnings)->where('code', 'wish_free_overridden'))->toBeEmpty();
});

it('overrides wish-free days when coverage demands it and reports a warning', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $onlyEmployee = planImprovementEmployee($location, [], ['name' => 'Anna Allein']);

    $roster = planImprovementRoster($location, $pdl);
    planImprovementRule(planImprovementTemplate($location), ['weekday' => 2]);

    ShiftWish::query()->create([
        'user_id' => $onlyEmployee->id,
        'location_id' => $location->id,
        'date' => '2027-01-05',
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $pdl->id,
    ]);

    $result = app(RosterGeneratorService::class)->generate($roster);

    $overrideWarning = collect($result->warnings)
        ->first(fn (array $entry): bool => $entry['code'] === 'wish_free_overridden');

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->where('user_id', $onlyEmployee->id)
        ->whereDate('date', '2027-01-05')
        ->exists())->toBeTrue()
        ->and($overrideWarning)->not->toBeNull()
        ->and($overrideWarning['context']['date'])->toBe('2027-01-05');
});

it('fulfills shift wishes when possible', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $wishing = planImprovementEmployee($location, [], ['name' => 'Zoe Wunsch']);
    planImprovementEmployee($location, [], ['name' => 'Anna Andere']);

    $roster = planImprovementRoster($location, $pdl);
    $template = planImprovementTemplate($location);
    planImprovementRule($template, ['weekday' => 2]);

    // Ohne Wunsch bekäme "Anna Andere" den Dienst (Namens-Tiebreaker).
    ShiftWish::query()->create([
        'user_id' => $wishing->id,
        'location_id' => $location->id,
        'date' => '2027-01-05',
        'kind' => ShiftWishKind::WishShift,
        'shift_template_id' => $template->id,
        'created_by' => $pdl->id,
    ]);

    app(RosterGeneratorService::class)->generate($roster);

    expect(Shift::query()
        ->where('roster_id', $roster->id)
        ->whereDate('date', '2027-01-05')
        ->value('user_id'))->toBe($wishing->id);
});

it('keeps improvement within the configured iteration budget', function (): void {
    config([
        'rostering.improvement.max_iterations' => 50,
        'rostering.improvement.stall_iterations' => 50,
    ]);

    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();

    foreach (range(1, 4) as $index) {
        planImprovementEmployee($location, [], ['name' => sprintf('Pflegekraft %02d', $index)]);
    }

    $roster = planImprovementRoster($location, $pdl);
    planImprovementRule(planImprovementTemplate($location), ['required_total_staff' => 2]);

    $startedAt = microtime(true);
    $result = app(RosterGeneratorService::class)->generate($roster);
    $elapsedSeconds = microtime(true) - $startedAt;

    expect($result->createdShifts)->toBeGreaterThan(0)
        ->and($elapsedSeconds)->toBeLessThan(5.0);
});
