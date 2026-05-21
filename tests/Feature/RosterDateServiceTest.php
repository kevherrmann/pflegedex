<?php

use App\Enums\RosterStatus;
use App\Models\Location;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\RosterDateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRosterDateServiceRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createRosterDateServiceShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

it('returns every date for January 2027', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterDateServiceRoster($location, $createdBy, [
        'year' => 2027,
        'month' => 1,
    ]);

    $dates = app(RosterDateService::class)->datesForRosterMonth($roster);

    expect($dates)->toHaveCount(31)
        ->and($dates[0]->toDateString())->toBe('2027-01-01')
        ->and($dates[30]->toDateString())->toBe('2027-01-31')
        ->and($dates[0]->format('H:i:s'))->toBe('00:00:00');
});

it('returns every date for February 2028 leap year', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterDateServiceRoster($location, $createdBy, [
        'year' => 2028,
        'month' => 2,
    ]);

    $dates = app(RosterDateService::class)->datesForRosterMonth($roster);

    expect($dates)->toHaveCount(29)
        ->and($dates[0]->toDateString())->toBe('2028-02-01')
        ->and($dates[28]->toDateString())->toBe('2028-02-29');
});

it('builds same day shift times for early shifts', function (): void {
    $location = Location::factory()->create();
    $shiftTemplate = createRosterDateServiceShiftTemplate($location, [
        'starts_at' => '06:00',
        'ends_at' => '14:00',
    ]);

    [$startsAt, $endsAt] = app(RosterDateService::class)->buildShiftTimes(
        CarbonImmutable::parse('2027-01-10')->startOfDay(),
        $shiftTemplate,
    );

    expect($startsAt->format('Y-m-d H:i:s'))->toBe('2027-01-10 06:00:00')
        ->and($endsAt->format('Y-m-d H:i:s'))->toBe('2027-01-10 14:00:00');
});

it('builds next day shift times for night shifts', function (): void {
    $location = Location::factory()->create();
    $shiftTemplate = createRosterDateServiceShiftTemplate($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);

    [$startsAt, $endsAt] = app(RosterDateService::class)->buildShiftTimes(
        CarbonImmutable::parse('2027-01-10')->startOfDay(),
        $shiftTemplate,
    );

    expect($startsAt->format('Y-m-d H:i:s'))->toBe('2027-01-10 22:00:00')
        ->and($endsAt->format('Y-m-d H:i:s'))->toBe('2027-01-11 06:00:00');
});

it('detects dates inside and outside the roster month', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $roster = createRosterDateServiceRoster($location, $createdBy, [
        'year' => 2027,
        'month' => 1,
    ]);
    $service = app(RosterDateService::class);

    expect($service->isDateInRosterMonth($roster, CarbonImmutable::parse('2027-01-10')->startOfDay()))->toBeTrue()
        ->and($service->isDateInRosterMonth($roster, CarbonImmutable::parse('2027-02-01')->startOfDay()))->toBeFalse();
});
