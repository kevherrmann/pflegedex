<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-05 10:00:00');
});

function makePdlWithSis(): array
{
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'completed_at' => null,
        'next_evaluation_due' => null,
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return [$location, $resident, $sis, $pdl];
}

it('PDL kann SIS fertigstellen — setzt completed_at, next_evaluation_due, schreibt Snapshot', function (): void {
    [$location, $resident, $sis, $pdl] = makePdlWithSis();

    $this->actingAs($pdl)
        ->post(route('residents.sis.complete', $resident))
        ->assertRedirect(route('residents.sis.show', $resident));

    $sis->refresh();
    expect($sis->completed_at?->toDateString())->toBe('2026-05-05')
        ->and($sis->next_evaluation_due?->toDateString())->toBe('2026-06-30')
        ->and($sis->updated_by)->toBe($pdl->id);

    $version = SisVersion::query()->where('sis_id', $sis->id)->latest('created_at')->first();
    expect($version)->not->toBeNull()
        ->and($version->snapshot_reason)->toBe('completed')
        ->and($version->created_by)->toBe($pdl->id);
});

it('Pflegekraft darf SIS NICHT fertigstellen', function (): void {
    [$location, $resident, $sis, $pdl] = makePdlWithSis();

    $pflege = User::factory()->for($location)->create();
    $pflege->assignRole('Pflegekraft');
    $pflege->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pflege)
        ->post(route('residents.sis.complete', $resident))
        ->assertForbidden();

    $sis->refresh();
    expect($sis->completed_at)->toBeNull();
});

it('doppeltes Fertigstellen wird abgelehnt', function (): void {
    [$location, $resident, $sis, $pdl] = makePdlWithSis();

    $this->actingAs($pdl)
        ->post(route('residents.sis.complete', $resident))
        ->assertRedirect();

    $this->actingAs($pdl)
        ->from(route('residents.sis.show', $resident))
        ->post(route('residents.sis.complete', $resident))
        ->assertRedirect(route('residents.sis.show', $resident))
        ->assertSessionHasErrors('completed_at');

    // Es soll nur EIN completed-Snapshot existieren
    $count = SisVersion::query()
        ->where('sis_id', $sis->id)
        ->where('snapshot_reason', 'completed')
        ->count();
    expect($count)->toBe(1);
});

it('PDL aus fremder Location wird abgelehnt', function (): void {
    [$location, $resident, $sis, $_] = makePdlWithSis();

    $other = Location::factory()->create();
    $foreign = User::factory()->for($other)->create();
    $foreign->assignRole('PDL');
    $foreign->locations()->syncWithoutDetaching([$other->id]);

    $this->actingAs($foreign)
        ->post(route('residents.sis.complete', $resident))
        ->assertForbidden();
});

it('Show-Seite zeigt completedAt nach Fertigstellung im Inertia-Payload', function (): void {
    [$location, $resident, $sis, $pdl] = makePdlWithSis();

    $this->actingAs($pdl)->post(route('residents.sis.complete', $resident));

    $this->actingAs($pdl)
        ->get(route('residents.sis.show', $resident))
        ->assertInertia(fn($page) => $page
            ->component('Sis/Show')
            ->where('sis.completedAt', '2026-05-05'));
});
