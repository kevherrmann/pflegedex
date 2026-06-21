<?php

declare(strict_types=1);

use App\Enums\CarePlanTopic;
use App\Models\CarePlan;
use App\Models\CarePlanTopicEntry;
use App\Models\CarePlanVersion;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-08 10:00:00');
});

it('CarePlan hat 1:1 zu Resident', function (): void {
    $resident = Resident::factory()->create();
    $cp = CarePlan::factory()->create(['resident_id' => $resident->id, 'location_id' => $resident->location_id]);

    expect($resident->fresh()->carePlan?->id)->toBe($cp->id);
});

it('appendVersion schreibt einen JSON-Snapshot mit Topics', function (): void {
    $resident = Resident::factory()->create();
    $cp = CarePlan::factory()
        ->withSampleTopics()
        ->create(['resident_id' => $resident->id, 'location_id' => $resident->location_id]);

    $user = User::factory()->create();
    $version = $cp->refresh()->appendVersion('test', $user);

    expect($version)->toBeInstanceOf(CarePlanVersion::class)
        ->and($version->snapshot_reason)->toBe('test')
        ->and($version->created_by)->toBe($user->id);

    $snapshot = json_decode($version->content_snapshot, true);
    expect($snapshot)->toHaveKey('grundbotschaft')
        ->and($snapshot)->toHaveKey('topics')
        ->and($snapshot['topics'])->toHaveCount(3);
});

it('markEvaluated setzt evaluated_at, next_evaluation_due und schreibt Snapshot', function (): void {
    $resident = Resident::factory()->create();
    $cp = CarePlan::factory()->create(['resident_id' => $resident->id, 'location_id' => $resident->location_id]);

    $user = User::factory()->create();
    $cp->markEvaluated($user);
    $cp->refresh();

    expect($cp->evaluated_at?->toDateString())->toBe('2026-05-08')
        ->and($cp->next_evaluation_due?->toDateString())->toBe('2026-07-03')
        ->and($cp->updated_by)->toBe($user->id);

    $version = CarePlanVersion::query()->where('care_plan_id', $cp->id)->latest('created_at')->first();
    expect($version->snapshot_reason)->toBe('evaluated');
});

it('isOverdue erkennt ueberfaellige MPs', function (): void {
    $cp = CarePlan::factory()->overdue()->create();
    expect($cp->isOverdue())->toBeTrue();

    $cp2 = CarePlan::factory()->create(['next_evaluation_due' => today()->addDays(5)]);
    expect($cp2->isOverdue())->toBeFalse();

    $cp3 = CarePlan::factory()->create(['next_evaluation_due' => null]);
    expect($cp3->isOverdue())->toBeFalse();
});

it('Enum hat 16 Themenbloecke in Doku-Reihenfolge', function (): void {
    expect(CarePlanTopic::numbers())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16])
        ->and(CarePlanTopic::Mobilitaet->value)->toBe(1)
        ->and(CarePlanTopic::FreiheitsentziehendeMassnahmen->value)->toBe(16)
        ->and(CarePlanTopic::Mobilitaet->label())->toBe('Mobilität und Beweglichkeit');
});

it('unique-Constraint verhindert doppelte Topic-Eintraege fuer dieselbe Topic-Nummer', function (): void {
    $cp = CarePlan::factory()->create();

    CarePlanTopicEntry::factory()->create([
        'care_plan_id' => $cp->id,
        'topic_number' => 1,
    ]);

    expect(fn () => CarePlanTopicEntry::factory()->create([
        'care_plan_id' => $cp->id,
        'topic_number' => 1,
    ]))->toThrow(QueryException::class);
});
