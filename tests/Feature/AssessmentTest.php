<?php

declare(strict_types=1);

use App\Enums\AssessmentType;
use App\Models\Assessment;
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

function assessmentUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);

    return $user;
}

function bradenAnswers(int $each = 3): array
{
    return [
        'sensory_perception' => $each,
        'moisture' => $each,
        'activity' => $each,
        'mobility' => $each,
        'nutrition' => $each,
        'friction_shear' => min($each, 3),
    ];
}

it('erfasst ein Braden-Assessment mit berechnetem Score und Risiko', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->post(route('residents.assessments.store', $resident), [
            'type' => AssessmentType::Braden->value,
            'assessed_on' => '2026-06-01',
            'answers' => bradenAnswers(3), // 6*3 = 18 -> Geringes Risiko
            'note' => 'Haut intakt.',
        ])
        ->assertRedirect(route('residents.assessments.index', $resident));

    $assessment = Assessment::query()->sole();

    expect($assessment->type)->toBe(AssessmentType::Braden)
        ->and($assessment->total_score)->toBe(18)
        ->and($assessment->risk_level)->toBe('Geringes Risiko')
        ->and($assessment->next_due->toDateString())->toBe('2026-06-29') // +4 Wochen
        ->and($assessment->answers['mobility'])->toBe(3)
        ->and($assessment->note)->toBe('Haut intakt.');
});

it('berechnet hohes Risiko bei niedrigem Braden-Score', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)->post(route('residents.assessments.store', $resident), [
        'type' => AssessmentType::Braden->value,
        'assessed_on' => '2026-06-01',
        'answers' => bradenAnswers(2), // 5*2 + 2 = 12 -> Hohes Risiko
    ])->assertRedirect();

    expect(Assessment::query()->sole()->risk_level)->toBe('Hohes Risiko');
});

it('erfasst ein Schmerz-Assessment (NRS)', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)->post(route('residents.assessments.store', $resident), [
        'type' => AssessmentType::Pain->value,
        'assessed_on' => '2026-06-01',
        'answers' => ['nrs' => 8],
    ])->assertRedirect();

    $assessment = Assessment::query()->sole();

    expect($assessment->total_score)->toBe(8)
        ->and($assessment->risk_level)->toBe('Starke Schmerzen');
});

it('speichert die Antworten verschluesselt (at-rest)', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)->post(route('residents.assessments.store', $resident), [
        'type' => AssessmentType::Pain->value,
        'assessed_on' => '2026-06-01',
        'answers' => ['nrs' => 5],
    ])->assertRedirect();

    $raw = DB::table('assessments')->value('answers');

    // Klartext-JSON darf nicht in der DB stehen.
    expect($raw)->not->toContain('nrs')
        ->and(Assessment::query()->sole()->answers)->toBe(['nrs' => 5]);
});

it('validiert fehlende und unzulaessige Braden-Items', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($nurse)
        ->from(route('residents.assessments.index', $resident))
        ->post(route('residents.assessments.store', $resident), [
            'type' => AssessmentType::Braden->value,
            'assessed_on' => '2026-06-01',
            'answers' => [
                'sensory_perception' => 9, // ungueltig (max 4)
                // restliche Items fehlen
            ],
        ])
        ->assertSessionHasErrors(['answers.sensory_perception', 'answers.mobility']);

    expect(Assessment::query()->count())->toBe(0);
});

it('zeigt den Verlauf der Assessments', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    Assessment::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'type' => AssessmentType::Braden,
        'assessed_on' => '2026-06-01',
        'answers' => bradenAnswers(3),
        'total_score' => 18,
        'risk_level' => 'Geringes Risiko',
        'next_due' => '2026-06-29',
        'assessed_by' => $nurse->id,
    ]);

    $this->actingAs($nurse)
        ->get(route('residents.assessments.index', $resident))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assessments/Index')
            ->has('assessments', 1)
            ->where('assessments.0.totalScore', 18)
            ->where('assessments.0.riskLevel', 'Geringes Risiko')
            ->has('definitions', 6));
});

it('erfasst Norton, Sturzrisiko, MNA und Kontinenzprofil mit korrektem Scoring', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $cases = [
        [
            'type' => AssessmentType::Norton->value,
            'answers' => ['physical_condition' => 2, 'mental_condition' => 2, 'activity' => 2, 'mobility' => 2, 'incontinence' => 2],
            'total' => 10,
            'risk' => 'Erhöhtes Dekubitusrisiko',
        ],
        [
            'type' => AssessmentType::Fall->value,
            'answers' => ['prior_fall' => 1, 'gait_balance' => 1, 'vision' => 1, 'cognition' => 1, 'medication' => 0, 'continence' => 0, 'assistive_device' => 0],
            'total' => 4,
            'risk' => 'Hohes Risiko',
        ],
        [
            'type' => AssessmentType::Mna->value,
            'answers' => ['food_intake' => 0, 'weight_loss' => 0, 'mobility' => 0, 'acute_disease' => 0, 'neuropsych' => 0, 'bmi' => 0],
            'total' => 0,
            'risk' => 'Mangelernährung',
        ],
        [
            'type' => AssessmentType::Continence->value,
            'answers' => ['continence_profile' => 1],
            'total' => 1,
            'risk' => 'Nicht kompensierte Inkontinenz',
        ],
    ];

    foreach ($cases as $case) {
        $this->actingAs($nurse)->post(route('residents.assessments.store', $resident), [
            'type' => $case['type'],
            'assessed_on' => '2026-06-01',
            'answers' => $case['answers'],
        ])->assertRedirect();

        $assessment = Assessment::query()->where('type', $case['type'])->latest('created_at')->firstOrFail();

        expect($assessment->total_score)->toBe($case['total'])
            ->and($assessment->risk_level)->toBe($case['risk']);
    }

    expect(Assessment::query()->count())->toBe(4);
});

it('verwehrt Zugriff auf fremde Wohnbereiche', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $nurse = assessmentUser('Pflegekraft', $ownLocation);
    $foreignResident = Resident::factory()->for($otherLocation)->create();

    $this->actingAs($nurse)->get(route('residents.assessments.index', $foreignResident))->assertForbidden();
    $this->actingAs($nurse)
        ->post(route('residents.assessments.store', $foreignResident), [
            'type' => AssessmentType::Pain->value,
            'assessed_on' => '2026-06-01',
            'answers' => ['nrs' => 3],
        ])
        ->assertForbidden();
});

it('verwehrt Assessments fuer Nicht-Pflegepersonal', function (): void {
    $location = Location::factory()->create();
    $cleaner = assessmentUser('Putzkraft', $location);
    $resident = Resident::factory()->for($location)->create();

    $this->actingAs($cleaner)->get(route('residents.assessments.index', $resident))->assertForbidden();
});

it('verhindert das Loeschen eines Assessments eines fremden Bewohners', function (): void {
    $location = Location::factory()->create();
    $nurse = assessmentUser('PDL', $location);
    $residentA = Resident::factory()->for($location)->create();
    $residentB = Resident::factory()->for($location)->create();

    $assessment = Assessment::query()->create([
        'resident_id' => $residentA->id,
        'location_id' => $location->id,
        'type' => AssessmentType::Pain,
        'assessed_on' => '2026-06-01',
        'answers' => ['nrs' => 4],
        'total_score' => 4,
        'risk_level' => 'Mittlere Schmerzen',
        'assessed_by' => $nurse->id,
    ]);

    $this->actingAs($nurse)
        ->delete(route('residents.assessments.destroy', [$residentB, $assessment]))
        ->assertNotFound();

    expect(Assessment::query()->count())->toBe(1);
});
