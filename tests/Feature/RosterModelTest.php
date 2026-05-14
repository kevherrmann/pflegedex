<?php

use App\Enums\RosterStatus;
use App\Enums\ShiftSource;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRosterModelRoster(Location $location, User $createdBy, array $attributes = []): Roster
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

function createRosterModelShiftTemplate(Location $location, array $attributes = []): ShiftTemplate
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

function createRosterModelShift(
    Roster $roster,
    Location $location,
    User $user,
    ShiftTemplate $shiftTemplate,
    array $attributes = [],
): Shift {
    return Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $location->id,
        'user_id' => $user->id,
        'shift_template_id' => $shiftTemplate->id,
        'date' => $attributes['date'] ?? '2027-01-10',
        'starts_at' => $attributes['starts_at'] ?? '2027-01-10 06:00:00',
        'ends_at' => $attributes['ends_at'] ?? '2027-01-10 14:00:00',
        'source' => $attributes['source'] ?? ShiftSource::Manual,
        'note' => $attributes['note'] ?? null,
    ]);
}

it('stores a monthly roster for a location', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->for($location)->create();

    $roster = createRosterModelRoster($location, $createdBy);

    expect($roster->id)->not->toBeNull()
        ->and($roster->location_id)->toBe($location->id)
        ->and($roster->year)->toBe(2027)
        ->and($roster->month)->toBe(1)
        ->and($roster->status)->toBe(RosterStatus::Draft)
        ->and($roster->created_by)->toBe($createdBy->id)
        ->and($roster->location->id)->toBe($location->id)
        ->and($roster->createdBy->id)->toBe($createdBy->id);
});

it('prevents duplicate rosters for the same location year and month', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->for($location)->create();

    createRosterModelRoster($location, $createdBy);

    expect(fn () => createRosterModelRoster($location, $createdBy))->toThrow(QueryException::class);
});

it('allows the same roster year and month for different locations', function (): void {
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();
    $createdBy = User::factory()->create();

    createRosterModelRoster($firstLocation, $createdBy);
    createRosterModelRoster($secondLocation, $createdBy);

    expect(Roster::query()->count())->toBe(2);
});

it('marks draft generated and reviewed rosters as editable', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();

    expect(createRosterModelRoster($location, $createdBy, ['status' => RosterStatus::Draft])->isEditable())->toBeTrue()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 2, 'status' => RosterStatus::Generated])->isEditable())->toBeTrue()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 3, 'status' => RosterStatus::Reviewed])->isEditable())->toBeTrue()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 4, 'status' => RosterStatus::Published])->isEditable())->toBeFalse()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 5, 'status' => RosterStatus::Locked])->isEditable())->toBeFalse();
});

it('marks published and locked rosters as published', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();

    expect(createRosterModelRoster($location, $createdBy, ['status' => RosterStatus::Draft])->isPublished())->toBeFalse()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 2, 'status' => RosterStatus::Generated])->isPublished())->toBeFalse()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 3, 'status' => RosterStatus::Reviewed])->isPublished())->toBeFalse()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 4, 'status' => RosterStatus::Published])->isPublished())->toBeTrue()
        ->and(createRosterModelRoster($location, $createdBy, ['month' => 5, 'status' => RosterStatus::Locked])->isPublished())->toBeTrue();
});

it('stores shifts for a roster', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->for($location)->create();
    $employee = User::factory()->for($location)->create();
    $roster = createRosterModelRoster($location, $createdBy);
    $shiftTemplate = createRosterModelShiftTemplate($location);

    $shift = createRosterModelShift($roster, $location, $employee, $shiftTemplate);

    expect($shift->id)->not->toBeNull()
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
        ->and($shift->shiftTemplate->id)->toBe($shiftTemplate->id)
        ->and($roster->shifts()->count())->toBe(1);
});

it('stores auto shift sources', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = User::factory()->create();
    $roster = createRosterModelRoster($location, $createdBy);
    $shiftTemplate = createRosterModelShiftTemplate($location);

    $shift = createRosterModelShift($roster, $location, $employee, $shiftTemplate, [
        'source' => ShiftSource::Auto,
    ]);

    expect($shift->source)->toBe(ShiftSource::Auto);
});

it('prevents assigning the same employee to the exact same shift template on the same day twice', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = User::factory()->create();
    $roster = createRosterModelRoster($location, $createdBy);
    $shiftTemplate = createRosterModelShiftTemplate($location);

    createRosterModelShift($roster, $location, $employee, $shiftTemplate);

    expect(fn () => createRosterModelShift($roster, $location, $employee, $shiftTemplate, [
        'starts_at' => '2027-01-10 06:30:00',
        'ends_at' => '2027-01-10 14:30:00',
    ]))->toThrow(QueryException::class);
});

it('allows assigning the same employee to different shift templates on the same day', function (): void {
    $location = Location::factory()->create();
    $createdBy = User::factory()->create();
    $employee = User::factory()->create();
    $roster = createRosterModelRoster($location, $createdBy);
    $earlyShiftTemplate = createRosterModelShiftTemplate($location);
    $lateShiftTemplate = createRosterModelShiftTemplate($location, [
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
    ]);

    createRosterModelShift($roster, $location, $employee, $earlyShiftTemplate);
    createRosterModelShift($roster, $location, $employee, $lateShiftTemplate, [
        'starts_at' => '2027-01-10 14:00:00',
        'ends_at' => '2027-01-10 22:00:00',
    ]);

    expect(Shift::query()->count())->toBe(2);
});
