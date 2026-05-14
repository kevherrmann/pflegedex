<?php

use App\Enums\RosterStatus;
use App\Models\Location;
use App\Models\Roster;
use App\Models\User;
use App\Services\Rosters\RosterService;
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
