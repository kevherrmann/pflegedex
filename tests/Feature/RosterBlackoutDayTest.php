<?php

use App\Models\Location;
use App\Models\RosterBlackoutDay;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores roster blackout days for a location', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $blackoutDay = RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-12-24',
        'reason' => 'Weihnachten - keine Urlaubsfreigabe',
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => true,
        'created_by' => $pdl->id,
    ]);

    expect($blackoutDay->id)->not->toBeNull()
        ->and($blackoutDay->location_id)->toBe($location->id)
        ->and($blackoutDay->date->toDateString())->toBe('2026-12-24')
        ->and($blackoutDay->reason)->toBe('Weihnachten - keine Urlaubsfreigabe')
        ->and($blackoutDay->blocks_vacation)->toBeTrue()
        ->and($blackoutDay->blocks_overtime_compensation)->toBeTrue()
        ->and($blackoutDay->created_by)->toBe($pdl->id)
        ->and($blackoutDay->location->id)->toBe($location->id)
        ->and($blackoutDay->createdBy->id)->toBe($pdl->id);
});

it('uses sensible defaults for blocking vacation and overtime compensation', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $blackoutDay = RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-12-31',
        'reason' => 'Silvester',
        'created_by' => $pdl->id,
    ]);

    expect($blackoutDay->blocks_vacation)->toBeTrue()
        ->and($blackoutDay->blocks_overtime_compensation)->toBeTrue();
});

it('prevents duplicate blackout days for the same location and date', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-12-24',
        'reason' => 'Weihnachten',
        'created_by' => $pdl->id,
    ]);

    expect(fn () => RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-12-24',
        'reason' => 'Doppelter Eintrag',
        'created_by' => $pdl->id,
    ]))->toThrow(QueryException::class);
});

it('allows the same blackout date for different locations', function (): void {
    $firstLocation = Location::factory()->create();
    $secondLocation = Location::factory()->create();

    $pdl = User::factory()
        ->for($firstLocation)
        ->create();

    RosterBlackoutDay::query()->create([
        'location_id' => $firstLocation->id,
        'date' => '2026-12-24',
        'reason' => 'Wohnbereich A gesperrt',
        'created_by' => $pdl->id,
    ]);

    RosterBlackoutDay::query()->create([
        'location_id' => $secondLocation->id,
        'date' => '2026-12-24',
        'reason' => 'Wohnbereich B gesperrt',
        'created_by' => $pdl->id,
    ]);

    expect(RosterBlackoutDay::query()->count())->toBe(2);
});

it('finds blackout days for a location between dates', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-05-10',
        'reason' => 'Innerhalb',
        'created_by' => $pdl->id,
    ]);

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-05-20',
        'reason' => 'Außerhalb',
        'created_by' => $pdl->id,
    ]);

    RosterBlackoutDay::query()->create([
        'location_id' => $otherLocation->id,
        'date' => '2026-05-10',
        'reason' => 'Anderer Wohnbereich',
        'created_by' => $pdl->id,
    ]);

    $days = RosterBlackoutDay::query()
        ->forLocation($location)
        ->betweenDates('2026-05-01', '2026-05-15')
        ->get();

    expect($days)->toHaveCount(1)
        ->and($days->first()->reason)->toBe('Innerhalb');
});

it('can filter blackout days that block vacation only', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-06-01',
        'reason' => 'Blockiert Urlaub',
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => false,
        'created_by' => $pdl->id,
    ]);

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-06-02',
        'reason' => 'Blockiert nur Überstundenfrei',
        'blocks_vacation' => false,
        'blocks_overtime_compensation' => true,
        'created_by' => $pdl->id,
    ]);

    $vacationBlocks = RosterBlackoutDay::query()
        ->blockingVacation()
        ->pluck('reason')
        ->all();

    $overtimeBlocks = RosterBlackoutDay::query()
        ->blockingOvertimeCompensation()
        ->pluck('reason')
        ->all();

    expect($vacationBlocks)->toBe(['Blockiert Urlaub'])
        ->and($overtimeBlocks)->toBe(['Blockiert nur Überstundenfrei']);
});
