<?php

declare(strict_types=1);

use App\Enums\MedicationAdministrationStatus;
use App\Enums\MedicationForm;
use App\Enums\MedicationSlot;
use App\Models\Location;
use App\Models\Medication;
use App\Models\MedicationAdministration;
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

function medUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);

    return $user;
}

function makeMedication(Resident $resident, Location $location, bool $btm = false): Medication
{
    return Medication::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'name' => $btm ? 'Morphin' : 'Ramipril',
        'form' => MedicationForm::Tablette,
        'strength' => '5 mg',
        'dose_morning' => '1',
        'is_btm' => $btm,
        'active' => true,
    ]);
}

it('legt ein Medikament im Plan an', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->post(route('residents.medications.store', $resident), [
            'name' => 'Ibuprofen',
            'form' => MedicationForm::Tablette->value,
            'strength' => '400 mg',
            'dose_morning' => '1',
            'dose_evening' => '1',
            'prn' => true,
            'prn_instruction' => 'bei Schmerzen, max 3x',
            'is_btm' => false,
            'prescriber' => 'Dr. Schmidt',
        ])
        ->assertRedirect(route('residents.medications.index', $resident));

    $med = Medication::query()->sole();

    expect($med->name)->toBe('Ibuprofen')
        ->and($med->form)->toBe(MedicationForm::Tablette)
        ->and($med->prn)->toBeTrue()
        ->and($med->prn_instruction)->toBe('bei Schmerzen, max 3x')
        ->and($med->active)->toBeTrue();

    // Bedarfs-Hinweis verschluesselt at-rest.
    expect(DB::table('medications')->value('prn_instruction'))->not->toBe('bei Schmerzen, max 3x');
});

it('quittiert eine normale Gabe ohne Zweitkraft', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $med = makeMedication($resident, $location);

    $this->actingAs($nurse)
        ->post(route('residents.medications.administer', [$resident, $med]), [
            'administered_on' => today()->toDateString(),
            'slot' => MedicationSlot::Morning->value,
            'status' => MedicationAdministrationStatus::Administered->value,
        ])
        ->assertRedirect();

    $administration = MedicationAdministration::query()->sole();

    expect($administration->medication_id)->toBe($med->id)
        ->and($administration->status)->toBe(MedicationAdministrationStatus::Administered)
        ->and($administration->administered_by)->toBe($nurse->id)
        ->and($administration->witness_by)->toBeNull();
});

it('verlangt bei BTM-Gabe eine Zweitkraft', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $btm = makeMedication($resident, $location, btm: true);

    $this->actingAs($nurse)
        ->from(route('residents.medications.index', $resident))
        ->post(route('residents.medications.administer', [$resident, $btm]), [
            'administered_on' => today()->toDateString(),
            'slot' => MedicationSlot::Morning->value,
            'status' => MedicationAdministrationStatus::Administered->value,
        ])
        ->assertSessionHasErrors('witness_by');

    expect(MedicationAdministration::query()->count())->toBe(0);
});

it('lehnt die abgebende Person als eigene Zweitkraft ab', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    $btm = makeMedication($resident, $location, btm: true);

    $this->actingAs($nurse)
        ->from(route('residents.medications.index', $resident))
        ->post(route('residents.medications.administer', [$resident, $btm]), [
            'administered_on' => today()->toDateString(),
            'slot' => MedicationSlot::Morning->value,
            'status' => MedicationAdministrationStatus::Administered->value,
            'witness_by' => $nurse->id,
        ])
        ->assertSessionHasErrors('witness_by');

    expect(MedicationAdministration::query()->count())->toBe(0);
});

it('quittiert eine BTM-Gabe mit gueltiger Zweitkraft', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $witness = medUser('PDL', $location);
    $resident = Resident::factory()->for($location)->create();
    $btm = makeMedication($resident, $location, btm: true);

    $this->actingAs($nurse)
        ->post(route('residents.medications.administer', [$resident, $btm]), [
            'administered_on' => today()->toDateString(),
            'slot' => MedicationSlot::Morning->value,
            'status' => MedicationAdministrationStatus::Administered->value,
            'witness_by' => $witness->id,
        ])
        ->assertRedirect();

    expect(MedicationAdministration::query()->sole()->witness_by)->toBe($witness->id);
});

it('setzt ein Medikament ab und erhaelt den Nachweis', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('PDL', $location);
    $resident = Resident::factory()->for($location)->create();
    $med = makeMedication($resident, $location);

    MedicationAdministration::query()->create([
        'medication_id' => $med->id,
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'administered_on' => today()->toDateString(),
        'slot' => MedicationSlot::Morning,
        'status' => MedicationAdministrationStatus::Administered,
        'administered_by' => $nurse->id,
        'administered_at' => now(),
    ]);

    $this->actingAs($nurse)
        ->delete(route('residents.medications.destroy', [$resident, $med]))
        ->assertRedirect(route('residents.medications.index', $resident));

    expect($med->fresh()->active)->toBeFalse()
        ->and(MedicationAdministration::query()->count())->toBe(1);
});

it('zeigt den Medikationsplan', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();
    makeMedication($resident, $location, btm: true);

    $this->actingAs($nurse)
        ->get(route('residents.medications.index', $resident))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Medications/Index')
            ->has('medications', 1)
            ->where('medications.0.name', 'Morphin')
            ->where('medications.0.isBtm', true)
            ->has('staff'));
});

it('verwehrt Zugriff auf fremde Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $ownLocation);
    $foreignResident = Resident::factory()->for($otherLocation)->create();

    $this->actingAs($nurse)->get(route('residents.medications.index', $foreignResident))->assertForbidden();
    $this->actingAs($nurse)
        ->post(route('residents.medications.store', $foreignResident), [
            'name' => 'X',
            'form' => MedicationForm::Tablette->value,
        ])
        ->assertForbidden();
});

it('verwehrt Medikation fuer Nicht-Pflegepersonal', function (): void {
    $location = Location::factory()->create();
    $cleaner = medUser('Putzkraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($cleaner)->get(route('residents.medications.index', $resident))->assertForbidden();
});

it('verhindert das Quittieren ueber einen fremden Bewohner', function (): void {
    $location = Location::factory()->create();
    $nurse = medUser('Pflegekraft', $location);
    $residentA = Resident::factory()->for($location)->create();
    $residentB = Resident::factory()->for($location)->create();
    $med = makeMedication($residentA, $location);

    $this->actingAs($nurse)
        ->post(route('residents.medications.administer', [$residentB, $med]), [
            'administered_on' => today()->toDateString(),
            'slot' => MedicationSlot::Morning->value,
            'status' => MedicationAdministrationStatus::Administered->value,
        ])
        ->assertNotFound();

    expect(MedicationAdministration::query()->count())->toBe(0);
});
