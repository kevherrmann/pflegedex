<?php

declare(strict_types=1);

use App\Enums\WoundStage;
use App\Enums\WoundStatus;
use App\Enums\WoundType;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use App\Models\Wound;
use App\Models\WoundAssessment;
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

function woundUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);

    return $user;
}

function makeWound(Resident $resident, Location $location): Wound
{
    return Wound::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'body_site' => 'Steiß',
        'type' => WoundType::Dekubitus,
        'acquired_in_house' => true,
        'opened_on' => '2026-06-01',
        'status' => WoundStatus::Open,
    ]);
}

it('legt eine Wunde an', function (): void {
    $location = Location::factory()->create();
    $nurse = woundUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->post(route('residents.wounds.store', $resident), [
            'body_site' => 'Ferse links',
            'type' => WoundType::Dekubitus->value,
            'acquired_in_house' => true,
            'opened_on' => '2026-06-01',
            'note' => 'Bei Aufnahme festgestellt.',
        ])
        ->assertRedirect(route('residents.wounds.index', $resident));

    $wound = Wound::query()->sole();

    expect($wound->body_site)->toBe('Ferse links')
        ->and($wound->type)->toBe(WoundType::Dekubitus)
        ->and($wound->acquired_in_house)->toBeTrue()
        ->and($wound->status)->toBe(WoundStatus::Open)
        ->and($wound->note)->toBe('Bei Aufnahme festgestellt.');

    expect(DB::table('wounds')->value('note'))->not->toBe('Bei Aufnahme festgestellt.');
});

it('erfasst einen Verlaufseintrag (verschlüsselt)', function (): void {
    $location = Location::factory()->create();
    $nurse = woundUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $wound = makeWound($resident, $location);

    $this->actingAs($nurse)
        ->post(route('residents.wounds.assessments.store', [$resident, $wound]), [
            'assessed_on' => '2026-06-05',
            'stage' => WoundStage::Grade2->value,
            'length_mm' => 30,
            'width_mm' => 20,
            'depth_mm' => 5,
            'pain' => 4,
            'wound_description' => 'Granulationsgewebe sichtbar.',
            'measures' => 'Hydrokolloidverband.',
        ])
        ->assertRedirect();

    $assessment = WoundAssessment::query()->sole();

    expect($assessment->wound_id)->toBe($wound->id)
        ->and($assessment->stage)->toBe(WoundStage::Grade2)
        ->and($assessment->length_mm)->toBe(30)
        ->and($assessment->pain)->toBe(4)
        ->and($assessment->measures)->toBe('Hydrokolloidverband.');

    expect(DB::table('wound_assessments')->value('measures'))->not->toBe('Hydrokolloidverband.');
});

it('markiert eine Wunde als abgeheilt und setzt das Abschlussdatum', function (): void {
    $location = Location::factory()->create();
    $nurse = woundUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $wound = makeWound($resident, $location);

    $this->actingAs($nurse)
        ->patch(route('residents.wounds.status', [$resident, $wound]), [
            'status' => WoundStatus::Healed->value,
        ])
        ->assertRedirect();

    $wound->refresh();

    expect($wound->status)->toBe(WoundStatus::Healed)
        ->and($wound->closed_on)->not->toBeNull();
});

it('zeigt die Wundübersicht', function (): void {
    $location = Location::factory()->create();
    $nurse = woundUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    makeWound($resident, $location);

    $this->actingAs($nurse)
        ->get(route('residents.wounds.index', $resident))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Wounds/Index')
            ->has('wounds', 1)
            ->where('wounds.0.bodySite', 'Steiß')
            ->where('wounds.0.acquiredInHouse', true)
            ->has('types')
            ->has('stages'));
});

it('verhindert das Löschen einer Wunde mit Verlaufseinträgen', function (): void {
    $location = Location::factory()->create();
    $nurse = woundUser('PDL', $location);
    $resident = Resident::factory()->for($location)->create();
    $wound = makeWound($resident, $location);

    WoundAssessment::query()->create([
        'wound_id' => $wound->id,
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'assessed_on' => '2026-06-05',
        'assessed_by' => $nurse->id,
    ]);

    $this->actingAs($nurse)
        ->delete(route('residents.wounds.destroy', [$resident, $wound]))
        ->assertRedirect();

    // Wunde bleibt erhalten (revisionssicher).
    expect(Wound::query()->whereKey($wound->id)->exists())->toBeTrue();
});

it('verwehrt Zugriff auf fremde Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $nurse = woundUser('Pflegekraft', $ownLocation);
    $foreignResident = Resident::factory()->for($otherLocation)->create();

    $this->actingAs($nurse)->get(route('residents.wounds.index', $foreignResident))->assertForbidden();
    $this->actingAs($nurse)
        ->post(route('residents.wounds.store', $foreignResident), [
            'body_site' => 'X',
            'type' => WoundType::Other->value,
            'opened_on' => '2026-06-01',
        ])
        ->assertForbidden();
});

it('verwehrt Wunddoku für Nicht-Pflegepersonal', function (): void {
    $location = Location::factory()->create();
    $cleaner = woundUser('Putzkraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($cleaner)->get(route('residents.wounds.index', $resident))->assertForbidden();
});

it('verhindert Verlaufseinträge über einen fremden Bewohner', function (): void {
    $location = Location::factory()->create();
    $nurse = woundUser('Pflegekraft', $location);
    $residentA = Resident::factory()->for($location)->create();
    $residentB = Resident::factory()->for($location)->create();
    $wound = makeWound($residentA, $location);

    $this->actingAs($nurse)
        ->post(route('residents.wounds.assessments.store', [$residentB, $wound]), [
            'assessed_on' => '2026-06-05',
        ])
        ->assertNotFound();

    expect(WoundAssessment::query()->count())->toBe(0);
});
