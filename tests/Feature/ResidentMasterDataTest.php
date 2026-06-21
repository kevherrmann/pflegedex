<?php

declare(strict_types=1);

use App\Enums\ResidentStatus;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function masterDataPdl(Location $location): User
{
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return $pdl;
}

it('legt einen Bewohner mit erweiterten Stammdaten an (verschlüsselt at-rest)', function (): void {
    $location = Location::factory()->create();
    $pdl = masterDataPdl($location);

    $this->actingAs($pdl)
        ->post(route('residents.store'), [
            'salutation' => 'frau',
            'first_name' => 'Erika',
            'last_name' => 'Muster',
            'care_level' => 3,
            'health_insurance' => 'AOK Bayern',
            'insurance_number' => 'A123456789',
            'family_doctor' => 'Dr. Schmidt',
            'guardian_name' => 'Hans Muster',
            'has_living_will' => true,
            'has_healthcare_proxy' => true,
            'allergies' => 'Penicillin',
            'diagnoses' => 'I10 Hypertonie',
        ])
        ->assertRedirect();

    $resident = Resident::query()->sole();

    expect($resident->status)->toBe(ResidentStatus::Present)
        ->and($resident->active)->toBeTrue()
        ->and($resident->admitted_on->toDateString())->toBe(today()->toDateString())
        ->and($resident->health_insurance)->toBe('AOK Bayern')
        ->and($resident->insurance_number)->toBe('A123456789')
        ->and($resident->family_doctor)->toBe('Dr. Schmidt')
        ->and($resident->guardian_name)->toBe('Hans Muster')
        ->and($resident->has_living_will)->toBeTrue()
        ->and($resident->has_healthcare_proxy)->toBeTrue()
        ->and($resident->allergies)->toBe('Penicillin')
        ->and($resident->diagnoses)->toBe('I10 Hypertonie');

    // Gesundheits-/Identifikationsdaten verschlüsselt in der DB.
    $raw = DB::table('residents')->where('id', $resident->id)->first();
    expect($raw->insurance_number)->not->toBe('A123456789')
        ->and($raw->allergies)->not->toBe('Penicillin')
        ->and($raw->diagnoses)->not->toBe('I10 Hypertonie');
});

it('setzt bei Entlassung den Status inaktiv und das Entlassdatum', function (): void {
    $location = Location::factory()->create();
    $pdl = masterDataPdl($location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($pdl)
        ->patch(route('residents.update', $resident), [
            'salutation' => $resident->salutation->value,
            'first_name' => $resident->first_name,
            'last_name' => $resident->last_name,
            'status' => ResidentStatus::Discharged->value,
        ])
        ->assertRedirect();

    $resident->refresh();

    expect($resident->status)->toBe(ResidentStatus::Discharged)
        ->and($resident->active)->toBeFalse()
        ->and($resident->discharged_on)->not->toBeNull();
});

it('hält einen Bewohner im Krankenhaus weiterhin aktiv', function (): void {
    $location = Location::factory()->create();
    $pdl = masterDataPdl($location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($pdl)
        ->patch(route('residents.update', $resident), [
            'salutation' => $resident->salutation->value,
            'first_name' => $resident->first_name,
            'last_name' => $resident->last_name,
            'status' => ResidentStatus::Hospital->value,
        ])
        ->assertRedirect();

    expect($resident->refresh()->active)->toBeTrue();
});
