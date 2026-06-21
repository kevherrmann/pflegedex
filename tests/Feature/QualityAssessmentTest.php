<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\QualityAssessment;
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

function qualityUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);
    $user->locations()->syncWithoutDetaching([$location->id]);

    return $user;
}

it('erfasst eine QI-Erhebung verschlüsselt und idempotent je Halbjahr', function (): void {
    $location = Location::factory()->create();
    $pdl = qualityUser('PDL', $location);
    $resident = Resident::factory()->for($location)->create();

    $payload = [
        'period' => '2026-H1',
        'assessed_on' => '2026-06-15',
        'answers' => [
            'mobility' => 'maintained',
            'decubitus' => 'no',
            'pain_assessment' => 'yes',
        ],
    ];

    $this->actingAs($pdl)->post(route('residents.quality.store', $resident), $payload)->assertRedirect();
    // Zweite Erhebung im selben Halbjahr aktualisiert statt zu duplizieren.
    $this->actingAs($pdl)->post(route('residents.quality.store', $resident), $payload)->assertRedirect();

    $assessment = QualityAssessment::query()->sole();

    expect($assessment->period)->toBe('2026-H1')
        ->and($assessment->answers['mobility'])->toBe('maintained')
        ->and($assessment->answers['decubitus'])->toBe('no');

    expect(DB::table('quality_assessments')->value('answers'))->not->toContain('mobility');
});

it('wertet die Indikatoren je Halbjahr aggregiert aus', function (): void {
    $location = Location::factory()->create();
    $pdl = qualityUser('PDL', $location);
    $residentA = Resident::factory()->for($location)->create();
    $residentB = Resident::factory()->for($location)->create();

    QualityAssessment::query()->create([
        'resident_id' => $residentA->id,
        'location_id' => $location->id,
        'period' => '2026-H1',
        'assessed_on' => '2026-06-15',
        'answers' => ['mobility' => 'maintained', 'decubitus' => 'no'],
        'assessed_by' => $pdl->id,
    ]);
    QualityAssessment::query()->create([
        'resident_id' => $residentB->id,
        'location_id' => $location->id,
        'period' => '2026-H1',
        'assessed_on' => '2026-06-15',
        'answers' => ['mobility' => 'declined', 'decubitus' => 'yes'],
        'assessed_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->get(route('quality.evaluation', ['period' => '2026-H1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quality/Evaluation')
            ->where('period', '2026-H1')
            ->where('residentsAssessed', 2)
            // mobility ist der erste Indikator (Bereich 1): 1 gut / 1 schlecht = 50 %.
            ->where('results.0.value', 'mobility')
            ->where('results.0.good', 1)
            ->where('results.0.bad', 1)
            ->where('results.0.percentGood', 50));
});

it('erlaubt Pflegekräften die lesende Auswertung', function (): void {
    $location = Location::factory()->create();
    $nurse = qualityUser('Pflegekraft', $location);

    $this->actingAs($nurse)
        ->get(route('quality.evaluation', ['period' => '2026-H1']))
        ->assertOk();
});

it('verwehrt die Auswertung für pflegefremde Rollen', function (): void {
    $location = Location::factory()->create();
    $cleaner = qualityUser('Putzkraft', $location);

    $this->actingAs($cleaner)->get(route('quality.evaluation'))->assertForbidden();
});

it('verwehrt die QI-Erhebung für fremde Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = qualityUser('PDL', $ownLocation);
    $foreignResident = Resident::factory()->for($otherLocation)->create();

    $this->actingAs($pdl)->get(route('residents.quality.index', $foreignResident))->assertForbidden();
});

it('verwehrt die QI-Erhebung für Nicht-Pflegepersonal', function (): void {
    $location = Location::factory()->create();
    $cleaner = qualityUser('Putzkraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($cleaner)->get(route('residents.quality.index', $resident))->assertForbidden();
});
