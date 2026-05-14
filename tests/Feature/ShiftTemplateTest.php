<?php

use App\Models\Location;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createShiftTemplateForTest(Location $location, array $attributes = []): ShiftTemplate
{
    return ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => $attributes['name'] ?? 'Frühdienst',
        'code' => $attributes['code'] ?? 'early',
        'starts_at' => $attributes['starts_at'] ?? '06:00',
        'ends_at' => $attributes['ends_at'] ?? '14:00',
        'duration_minutes' => $attributes['duration_minutes'] ?? 480,
        'color' => $attributes['color'] ?? null,
        'active' => $attributes['active'] ?? true,
    ]);
}

it('stores an early shift template for a location', function (): void {
    $location = Location::factory()->create();

    $shiftTemplate = createShiftTemplateForTest($location);

    expect($shiftTemplate->id)->not->toBeNull()
        ->and($shiftTemplate->location_id)->toBe($location->id)
        ->and($shiftTemplate->name)->toBe('Frühdienst')
        ->and($shiftTemplate->code)->toBe('early')
        ->and($shiftTemplate->starts_at)->toBe('06:00')
        ->and($shiftTemplate->ends_at)->toBe('14:00')
        ->and($shiftTemplate->duration_minutes)->toBe(480)
        ->and($shiftTemplate->active)->toBeTrue()
        ->and($shiftTemplate->location->id)->toBe($location->id);
});

it('stores late and night shift templates', function (): void {
    $location = Location::factory()->create();

    $lateShift = createShiftTemplateForTest($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
    ]);

    $nightShift = createShiftTemplateForTest($location, [
        'name' => 'Nachtdienst',
        'code' => 'night',
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);

    expect($lateShift->code)->toBe('late')
        ->and($lateShift->starts_at)->toBe('14:00')
        ->and($lateShift->ends_at)->toBe('22:00')
        ->and($lateShift->duration_minutes)->toBe(480)
        ->and($nightShift->code)->toBe('night')
        ->and($nightShift->starts_at)->toBe('22:00')
        ->and($nightShift->ends_at)->toBe('06:00')
        ->and($nightShift->duration_minutes)->toBe(480);
});

it('prevents duplicate shift template codes for the same location', function (): void {
    $location = Location::factory()->create();

    createShiftTemplateForTest($location);

    expect(fn () => createShiftTemplateForTest($location, [
        'name' => 'Frühdienst Kopie',
    ]))->toThrow(QueryException::class);
});

it('allows the same shift template code for different locations', function (): void {
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();

    createShiftTemplateForTest($firstLocation);
    createShiftTemplateForTest($secondLocation);

    expect(ShiftTemplate::query()->count())->toBe(2);
});

it('stores a default staffing rule for a shift template', function (): void {
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateForTest($location);

    $staffingRule = ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => null,
        'required_total_staff' => 5,
        'required_specialists' => 1,
    ]);

    expect($staffingRule->id)->not->toBeNull()
        ->and($staffingRule->location_id)->toBe($location->id)
        ->and($staffingRule->shift_template_id)->toBe($shiftTemplate->id)
        ->and($staffingRule->weekday)->toBeNull()
        ->and($staffingRule->required_total_staff)->toBe(5)
        ->and($staffingRule->required_specialists)->toBe(1)
        ->and($staffingRule->location->id)->toBe($location->id)
        ->and($staffingRule->shiftTemplate->id)->toBe($shiftTemplate->id)
        ->and($shiftTemplate->staffingRules()->count())->toBe(1);
});

it('stores a weekday specific staffing rule', function (): void {
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateForTest($location);

    $staffingRule = ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => 1,
        'required_total_staff' => 6,
        'required_specialists' => 2,
    ]);

    expect($staffingRule->weekday)->toBe(1)
        ->and($staffingRule->required_total_staff)->toBe(6)
        ->and($staffingRule->required_specialists)->toBe(2);
});

it('prevents duplicate staffing rules for the same shift template and weekday', function (): void {
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateForTest($location);

    ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => 1,
        'required_total_staff' => 6,
        'required_specialists' => 2,
    ]);

    expect(fn () => ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => 1,
        'required_total_staff' => 7,
        'required_specialists' => 2,
    ]))->toThrow(QueryException::class);
});

it('prevents multiple default staffing rules with nullable weekdays for the same shift template', function (): void {
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateForTest($location);

    ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => null,
        'required_total_staff' => 5,
        'required_specialists' => 1,
    ]);

    expect(fn () => ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $shiftTemplate->id,
        'weekday' => null,
        'required_total_staff' => 6,
        'required_specialists' => 2,
    ]))->toThrow(QueryException::class);
});
