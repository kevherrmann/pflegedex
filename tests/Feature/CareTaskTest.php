<?php

declare(strict_types=1);

use App\Enums\CareTaskCategory;
use App\Enums\CareTaskCompletionStatus;
use App\Models\CareTask;
use App\Models\CareTaskCompletion;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
    Role::findOrCreate('Putzkraft');
});

function careTaskUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);

    return $user;
}

function makeCareTask(Resident $resident, Location $location): CareTask
{
    return CareTask::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'title' => 'Morgendliche Grundpflege',
        'category' => CareTaskCategory::Grundpflege,
        'schedule' => 'täglich morgens',
        'active' => true,
    ]);
}

it('legt eine geplante Massnahme an', function (): void {
    $location = Location::factory()->create();
    $nurse = careTaskUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->post(route('residents.care-tasks.store', $resident), [
            'title' => 'Mobilisation',
            'category' => CareTaskCategory::Mobilitaet->value,
            'schedule' => '2x täglich',
            'description' => 'Mit Rollator über den Flur.',
        ])
        ->assertRedirect(route('residents.care-tasks.index', $resident));

    $task = CareTask::query()->sole();

    expect($task->resident_id)->toBe($resident->id)
        ->and($task->location_id)->toBe($location->id)
        ->and($task->category)->toBe(CareTaskCategory::Mobilitaet)
        ->and($task->active)->toBeTrue()
        ->and($task->description)->toBe('Mit Rollator über den Flur.');

    // Beschreibung verschluesselt at-rest.
    expect(DB::table('care_tasks')->value('description'))->not->toBe('Mit Rollator über den Flur.');
});

it('quittiert die Durchfuehrung einer Massnahme', function (): void {
    $location = Location::factory()->create();
    $nurse = careTaskUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $task = makeCareTask($resident, $location);

    $this->actingAs($nurse)
        ->post(route('residents.care-tasks.complete', [$resident, $task]), [
            'performed_on' => today()->toDateString(),
            'status' => CareTaskCompletionStatus::Done->value,
            'note' => 'Vollständig übernommen.',
        ])
        ->assertRedirect();

    $completion = CareTaskCompletion::query()->sole();

    expect($completion->care_task_id)->toBe($task->id)
        ->and($completion->status)->toBe(CareTaskCompletionStatus::Done)
        ->and($completion->performed_by)->toBe($nurse->id)
        ->and($completion->note)->toBe('Vollständig übernommen.');
});

it('validiert den Durchfuehrungs-Status', function (): void {
    $location = Location::factory()->create();
    $nurse = careTaskUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $task = makeCareTask($resident, $location);

    $this->actingAs($nurse)
        ->from(route('residents.care-tasks.index', $resident))
        ->post(route('residents.care-tasks.complete', [$resident, $task]), [
            'performed_on' => today()->toDateString(),
            'status' => 'erledigt-irgendwie',
        ])
        ->assertSessionHasErrors('status');

    expect(CareTaskCompletion::query()->count())->toBe(0);
});

it('deaktiviert eine Massnahme und erhaelt die Nachweise', function (): void {
    $location = Location::factory()->create();
    $nurse = careTaskUser('PDL', $location);
    $resident = Resident::factory()->for($location)->create();
    $task = makeCareTask($resident, $location);

    CareTaskCompletion::query()->create([
        'care_task_id' => $task->id,
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'performed_on' => today()->toDateString(),
        'status' => CareTaskCompletionStatus::Done,
        'performed_by' => $nurse->id,
        'performed_at' => now(),
    ]);

    $this->actingAs($nurse)
        ->delete(route('residents.care-tasks.destroy', [$resident, $task]))
        ->assertRedirect(route('residents.care-tasks.index', $resident));

    expect($task->fresh()->active)->toBeFalse()
        // Nachweis bleibt erhalten (Leistungsnachweis).
        ->and(CareTaskCompletion::query()->count())->toBe(1);
});

it('zeigt Massnahmen und Quittierungen des gewaehlten Tages', function (): void {
    $location = Location::factory()->create();
    $nurse = careTaskUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $task = makeCareTask($resident, $location);

    CareTaskCompletion::query()->create([
        'care_task_id' => $task->id,
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'performed_on' => '2026-06-01',
        'status' => CareTaskCompletionStatus::Done,
        'performed_by' => $nurse->id,
        'performed_at' => '2026-06-01 07:30',
    ]);

    $this->actingAs($nurse)
        ->get(route('residents.care-tasks.index', ['resident' => $resident->id, 'date' => '2026-06-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CareTasks/Index')
            ->where('selectedDate', '2026-06-01')
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Morgendliche Grundpflege')
            ->has('tasks.0.completions', 1)
            ->where('tasks.0.completions.0.status', 'done'));
});

it('verwehrt Zugriff auf fremde Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $nurse = careTaskUser('Pflegekraft', $ownLocation);
    $foreignResident = Resident::factory()->for($otherLocation)->create();

    $this->actingAs($nurse)->get(route('residents.care-tasks.index', $foreignResident))->assertForbidden();
    $this->actingAs($nurse)
        ->post(route('residents.care-tasks.store', $foreignResident), [
            'title' => 'X',
            'category' => CareTaskCategory::Grundpflege->value,
        ])
        ->assertForbidden();
});

it('verwehrt Durchfuehrungsnachweis fuer Nicht-Pflegepersonal', function (): void {
    $location = Location::factory()->create();
    $cleaner = careTaskUser('Putzkraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($cleaner)->get(route('residents.care-tasks.index', $resident))->assertForbidden();
});

it('verhindert das Quittieren einer Massnahme eines fremden Bewohners', function (): void {
    $location = Location::factory()->create();
    $nurse = careTaskUser('Pflegekraft', $location);
    $residentA = Resident::factory()->for($location)->create();
    $residentB = Resident::factory()->for($location)->create();
    $task = makeCareTask($residentA, $location);

    // Quittieren ueber den falschen Bewohner-Pfad -> 404 (Objektbezug).
    $this->actingAs($nurse)
        ->post(route('residents.care-tasks.complete', [$residentB, $task]), [
            'performed_on' => today()->toDateString(),
            'status' => CareTaskCompletionStatus::Done->value,
        ])
        ->assertNotFound();

    expect(CareTaskCompletion::query()->count())->toBe(0);
});
