<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use App\Models\VitalSign;
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

function vitalUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);

    return $user;
}

it('erfasst einen Vitalwert', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->post(route('residents.vitals.store', $resident), [
            'measured_at' => '2026-06-01 08:30',
            'systolic' => 128,
            'diastolic' => 82,
            'pulse' => 72,
            'temperature' => 36.8,
            'oxygen_saturation' => 97,
            'note' => 'Patientin wohlauf.',
        ])
        ->assertRedirect(route('residents.vitals.index', $resident));

    $vital = VitalSign::query()->sole();

    expect($vital->resident_id)->toBe($resident->id)
        ->and($vital->location_id)->toBe($location->id)
        ->and($vital->recorded_by)->toBe($nurse->id)
        ->and($vital->systolic)->toBe(128)
        ->and($vital->diastolic)->toBe(82)
        ->and((float) $vital->temperature)->toBe(36.8)
        ->and($vital->note)->toBe('Patientin wohlauf.');
});

it('speichert die Notiz verschluesselt (at-rest)', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)->post(route('residents.vitals.store', $resident), [
        'measured_at' => '2026-06-01 08:30',
        'pulse' => 70,
        'note' => 'Geheime Beobachtung.',
    ])->assertRedirect();

    $raw = DB::table('vital_signs')->value('note');

    expect($raw)->not->toBe('Geheime Beobachtung.')
        ->and(VitalSign::query()->sole()->note)->toBe('Geheime Beobachtung.');
});

it('erfordert mindestens einen Messwert', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->from(route('residents.vitals.index', $resident))
        ->post(route('residents.vitals.store', $resident), [
            'measured_at' => '2026-06-01 08:30',
        ])
        ->assertRedirect(route('residents.vitals.index', $resident))
        ->assertSessionHasErrors('systolic');

    expect(VitalSign::query()->count())->toBe(0);
});

it('validiert unplausible Wertebereiche', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->from(route('residents.vitals.index', $resident))
        ->post(route('residents.vitals.store', $resident), [
            'measured_at' => '2026-06-01 08:30',
            'systolic' => 999,
            'temperature' => 60,
        ])
        ->assertSessionHasErrors(['systolic', 'temperature']);

    expect(VitalSign::query()->count())->toBe(0);
});

it('zeigt den Verlauf der Vitalwerte', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    VitalSign::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'recorded_by' => $nurse->id,
        'measured_at' => '2026-06-01 08:30',
        'pulse' => 72,
    ]);

    $this->actingAs($nurse)
        ->get(route('residents.vitals.index', $resident))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Vitals/Index')
            ->where('resident.fullName', $resident->full_name)
            ->has('vitalSigns', 1)
            ->where('vitalSigns.0.pulse', 72));
});

it('verwehrt Zugriff auf Vitalwerte eines fremden Wohnbereichs', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $nurse = vitalUser('Pflegekraft', $ownLocation);
    $foreignResident = Resident::factory()->for($otherLocation)->create();

    $this->actingAs($nurse)->get(route('residents.vitals.index', $foreignResident))->assertForbidden();
    $this->actingAs($nurse)
        ->post(route('residents.vitals.store', $foreignResident), [
            'measured_at' => '2026-06-01 08:30',
            'pulse' => 72,
        ])
        ->assertForbidden();
});

it('verwehrt Vitalwerte fuer Nicht-Pflegepersonal', function (): void {
    $location = Location::factory()->create();
    $cleaner = vitalUser('Putzkraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($cleaner)->get(route('residents.vitals.index', $resident))->assertForbidden();
});

it('loescht einen Vitalwert im eigenen Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('PDL', $location);
    $resident = Resident::factory()->for($location)->create();

    $vital = VitalSign::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'recorded_by' => $nurse->id,
        'measured_at' => '2026-06-01 08:30',
        'pulse' => 72,
    ]);

    $this->actingAs($nurse)
        ->delete(route('residents.vitals.destroy', [$resident, $vital]))
        ->assertRedirect(route('residents.vitals.index', $resident));

    expect(VitalSign::query()->count())->toBe(0);
});

it('verhindert das Loeschen eines Werts ueber einen fremden Bewohner', function (): void {
    $location = Location::factory()->create();
    $nurse = vitalUser('PDL', $location);
    $residentA = Resident::factory()->for($location)->create();
    $residentB = Resident::factory()->for($location)->create();

    $vital = VitalSign::query()->create([
        'resident_id' => $residentA->id,
        'location_id' => $location->id,
        'recorded_by' => $nurse->id,
        'measured_at' => '2026-06-01 08:30',
        'pulse' => 72,
    ]);

    // Loeschen ueber den falschen Bewohner-Pfad -> 404 (Objektbezug).
    $this->actingAs($nurse)
        ->delete(route('residents.vitals.destroy', [$residentB, $vital]))
        ->assertNotFound();

    expect(VitalSign::query()->count())->toBe(1);
});
