<?php

use App\Models\Location;
use App\Models\ShiftCategoryStaffingRule;
use App\Models\ShiftTemplate;
use App\Services\Rosters\DefaultShiftSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runDefaultShiftSetupForTest(Location $location): void
{
    app(DefaultShiftSetupService::class)->createForLocation($location);
}

it('creates early late and night shift templates for a location', function (): void {
    $location = Location::factory()->create();

    runDefaultShiftSetupForTest($location);

    $shifts = ShiftTemplate::query()
        ->where('location_id', $location->id)
        ->orderBy('starts_at')
        ->get()
        ->keyBy('code');

    expect($shifts)->toHaveCount(3)
        ->and($shifts['early']->name)->toBe('Frühdienst')
        ->and($shifts['early']->starts_at)->toBe('06:00')
        ->and($shifts['early']->ends_at)->toBe('14:00')
        ->and($shifts['early']->duration_minutes)->toBe(480)
        ->and($shifts['early']->color)->toBe('#F59E0B')
        ->and($shifts['early']->active)->toBeTrue()
        ->and($shifts['late']->name)->toBe('Spätdienst')
        ->and($shifts['late']->starts_at)->toBe('14:00')
        ->and($shifts['late']->ends_at)->toBe('22:00')
        ->and($shifts['late']->duration_minutes)->toBe(480)
        ->and($shifts['late']->color)->toBe('#3B82F6')
        ->and($shifts['late']->active)->toBeTrue()
        ->and($shifts['night']->name)->toBe('Nachtdienst')
        ->and($shifts['night']->starts_at)->toBe('22:00')
        ->and($shifts['night']->ends_at)->toBe('06:00')
        ->and($shifts['night']->duration_minutes)->toBe(480)
        ->and($shifts['night']->color)->toBe('#6366F1')
        ->and($shifts['night']->active)->toBeTrue();
});

it('creates the matching default category staffing rules', function (): void {
    $location = Location::factory()->create();

    runDefaultShiftSetupForTest($location);

    $rulesByCategory = ShiftCategoryStaffingRule::query()
        ->where('location_id', $location->id)
        ->get()
        ->keyBy('category');

    expect($rulesByCategory)->toHaveCount(3)
        ->and($rulesByCategory['early']->weekday)->toBeNull()
        ->and($rulesByCategory['early']->required_total_staff)->toBe(5)
        ->and($rulesByCategory['early']->required_specialists)->toBe(1)
        ->and($rulesByCategory['late']->weekday)->toBeNull()
        ->and($rulesByCategory['late']->required_total_staff)->toBe(3)
        ->and($rulesByCategory['late']->required_specialists)->toBe(1)
        ->and($rulesByCategory['night']->weekday)->toBeNull()
        ->and($rulesByCategory['night']->required_total_staff)->toBe(1)
        ->and($rulesByCategory['night']->required_specialists)->toBe(1);
});

it('is idempotent for one location', function (): void {
    $location = Location::factory()->create();

    runDefaultShiftSetupForTest($location);
    runDefaultShiftSetupForTest($location);

    expect(ShiftTemplate::query()->where('location_id', $location->id)->count())->toBe(3)
        ->and(ShiftCategoryStaffingRule::query()->where('location_id', $location->id)->count())->toBe(3);
});

it('can run for two different locations', function (): void {
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();

    runDefaultShiftSetupForTest($firstLocation);
    runDefaultShiftSetupForTest($secondLocation);

    expect(ShiftTemplate::query()->count())->toBe(6)
        ->and(ShiftCategoryStaffingRule::query()->count())->toBe(6);
});

it('requires at least one specialist for the night category', function (): void {
    $location = Location::factory()->create();

    runDefaultShiftSetupForTest($location);

    $nightRule = ShiftCategoryStaffingRule::query()
        ->where('location_id', $location->id)
        ->where('category', 'night')
        ->whereNull('weekday')
        ->firstOrFail();

    expect($nightRule->required_total_staff)->toBe(1)
        ->and($nightRule->required_specialists)->toBe(1);
});

it('updates existing default shifts and category staffing instead of duplicating them', function (): void {
    $location = Location::factory()->create();

    $earlyShift = ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => 'Alter Frühdienst',
        'code' => 'early',
        'category' => 'early',
        'starts_at' => '07:00',
        'ends_at' => '15:00',
        'duration_minutes' => 480,
        'color' => '#000000',
        'active' => false,
    ]);

    ShiftCategoryStaffingRule::query()->create([
        'location_id' => $location->id,
        'category' => 'early',
        'weekday' => null,
        'required_total_staff' => 2,
        'required_specialists' => 0,
    ]);

    runDefaultShiftSetupForTest($location);

    $earlyShift->refresh();
    $earlyRule = ShiftCategoryStaffingRule::query()
        ->where('location_id', $location->id)
        ->where('category', 'early')
        ->whereNull('weekday')
        ->firstOrFail();

    expect(ShiftTemplate::query()->where('location_id', $location->id)->count())->toBe(3)
        ->and(ShiftCategoryStaffingRule::query()->where('location_id', $location->id)->count())->toBe(3)
        ->and($earlyShift->name)->toBe('Frühdienst')
        ->and($earlyShift->starts_at)->toBe('06:00')
        ->and($earlyShift->ends_at)->toBe('14:00')
        ->and($earlyShift->color)->toBe('#F59E0B')
        ->and($earlyShift->active)->toBeTrue()
        ->and($earlyRule->required_total_staff)->toBe(5)
        ->and($earlyRule->required_specialists)->toBe(1);
});

it('creates default shifts for all locations through the artisan command', function (): void {
    Location::factory()->count(2)->create();

    $this->artisan('pflegedex:create-default-shifts')
        ->expectsOutput('Standardschichten für 2 Wohnbereiche erstellt oder aktualisiert.')
        ->assertSuccessful();

    expect(ShiftTemplate::query()->count())->toBe(6)
        ->and(ShiftCategoryStaffingRule::query()->count())->toBe(6);
});
