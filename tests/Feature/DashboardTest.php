<?php

declare(strict_types=1);

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-15 10:00:00');
});

function dashLocationAndPdl(): array
{
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return [$location, $pdl];
}

it('Nicht-PDL wird vom Dashboard zur Bewohner-Liste umgeleitet', function (): void {
    $location = Location::factory()->create();
    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->get(route('dashboard'))
        ->assertRedirect(route('residents.index'));
});

it('Admin wird vom Dashboard zur Benutzerverwaltung umgeleitet statt 403', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('users.index'));
});

it('PDL sieht leeres Dashboard wenn keine Daten existieren', function (): void {
    [$location, $pdl] = dashLocationAndPdl();

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('todo.totalRed', 0)
            ->where('todo.totalYellow', 0)
            ->where('todo.totalGap', 0)
            ->has('todo.sisOverdueAdmission', 0)
            ->has('todo.sisEvalOverdue', 0)
            ->has('todo.mpEvalOverdue', 0)
            ->has('running.sisActive', 0)
            ->has('recent', 0));
});

it('Dashboard zeigt SIS ueber 14 Tage ohne completed_at als rot', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();

    // SIS vor 20 Tagen begonnen, nicht fertiggestellt
    Sis::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'started_at' => today()->subDays(20),
        'completed_at' => null,
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('todo.sisOverdueAdmission', 1)
            ->where('todo.sisOverdueAdmission.0.residentId', $resident->id)
            ->where('todo.sisOverdueAdmission.0.severity', 'red')
            ->where('todo.totalRed', 1));
});

it('Dashboard zeigt SIS unter 14 Tagen NICHT als ueberfaellig', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();

    Sis::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'started_at' => today()->subDays(10),
        'completed_at' => null,
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('todo.sisOverdueAdmission', 0));
});

it('Dashboard zeigt ueberfaellige SIS-Evaluationen als rot', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();

    Sis::factory()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'next_evaluation_due' => today()->subDays(3),
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('todo.sisEvalOverdue', 1)
            ->where('todo.sisEvalOverdue.0.severity', 'red')
            ->where('todo.totalRed', 1));
});

it('Dashboard zeigt bald-faellige SIS-Evaluationen als gelb', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();

    Sis::factory()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'next_evaluation_due' => today()->addDays(5),
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('todo.sisEvalSoon', 1)
            ->where('todo.sisEvalSoon.0.severity', 'yellow')
            ->where('todo.totalYellow', 1));
});

it('Dashboard zeigt ueberfaellige MP-Evaluationen als rot', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'next_evaluation_due' => today()->subDays(2),
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('todo.mpEvalOverdue', 1)
            ->where('todo.mpEvalOverdue.0.severity', 'red'));
});

it('Dashboard zeigt SIS fertig aber MP fehlt als gelb', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();

    Sis::factory()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    // KEIN CarePlan

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('todo.sisCompletedNoMp', 1)
            ->where('todo.sisCompletedNoMp.0.severity', 'yellow'));
});

it('Dashboard filtert nach Wohnbereich-Zugehoerigkeit des PDL', function (): void {
    [$myLocation, $pdl] = dashLocationAndPdl();
    $otherLocation = Location::factory()->create();
    $otherResident = Resident::factory()->for($otherLocation)->create();

    Sis::factory()->create([
        'resident_id' => $otherResident->id,
        'location_id' => $otherLocation->id,
        'started_at' => today()->subDays(20),
        'completed_at' => null,
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('todo.sisOverdueAdmission', 0)
            ->has('recent', 0));
});

it('Dashboard listet aktive SIS-Generationen', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'running',
        'progress' => 4,
        'total_steps' => 7,
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('running.sisActive', 1)
            ->where('running.sisActive.0.status', 'running')
            ->where('running.sisActive.0.progress', 4)
            ->where('running.sisActive.0.kind', 'sis'));
});

it('Dashboard listet aktive MP-Generationen', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    CarePlanGeneration::query()->create([
        'care_plan_id' => $cp->id,
        'triggered_by' => $pdl->id,
        'status' => 'pending',
        'progress' => 0,
        'total_steps' => 17,
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('running.mpActive', 1)
            ->where('running.mpActive.0.kind', 'mp'));
});

it('Dashboard listet fehlgeschlagene Generationen', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    SisGeneration::query()->create([
        'sis_id' => $sis->id,
        'triggered_by' => $pdl->id,
        'status' => 'failed',
        'progress' => 3,
        'total_steps' => 7,
        'error_message' => 'Ollama timeout nach 30s',
        'finished_at' => now(),
    ]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('running.sisFailed', 1)
            ->where('running.sisFailed.0.errorMessage', 'Ollama timeout nach 30s'));
});

it('Dashboard recent-Block zeigt zuletzt aufgenommene Bewohner', function (): void {
    [$location, $pdl] = dashLocationAndPdl();

    $r1 = Resident::factory()->for($location)->create();
    $r2 = Resident::factory()->for($location)->create();
    $r3 = Resident::factory()->for($location)->create();

    Sis::factory()->completed()->create(['resident_id' => $r1->id, 'location_id' => $location->id]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('recent', 3, fn ($item) => $item
                ->has('id')
                ->has('pseudonym')
                ->has('name')
                ->has('locationName')
                ->has('createdAt')
                ->has('hasSis')
                ->has('sisCompleted')
                ->has('hasCarePlan')));
});

it('Dashboard recent-Block zeigt SIS-Status korrekt pro Bewohner', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->completed()->create(['resident_id' => $resident->id, 'location_id' => $location->id]);

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('recent', 1)
            ->where('recent.0.id', $resident->id)
            ->where('recent.0.hasSis', true)
            ->where('recent.0.sisCompleted', true)
            ->where('recent.0.hasCarePlan', false));
});

function dashOverdueAssessment(Location $location, User $pdl, Resident $resident, $nextDue): Assessment
{
    return Assessment::query()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'type' => AssessmentType::Braden,
        'assessed_on' => today()->subWeeks(5),
        'answers' => ['sensory_perception' => 3],
        'total_score' => 18,
        'risk_level' => 'Geringes Risiko',
        'next_due' => $nextDue,
        'assessed_by' => $pdl->id,
    ]);
}

it('Dashboard zeigt überfällige Assessment-Wiedervorlagen als rot', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    dashOverdueAssessment($location, $pdl, $resident, today()->subDays(3));

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('todo.assessmentEvalOverdue', 1)
            ->where('todo.assessmentEvalOverdue.0.residentId', $resident->id)
            ->where('todo.assessmentEvalOverdue.0.assessmentType', 'Braden-Skala (Dekubitusrisiko)')
            ->where('todo.assessmentEvalOverdue.0.severity', 'red')
            ->where('todo.totalRed', 1));
});

it('Dashboard zeigt bald fällige Assessment-Wiedervorlagen als gelb', function (): void {
    [$location, $pdl] = dashLocationAndPdl();
    $resident = Resident::factory()->for($location)->create();
    dashOverdueAssessment($location, $pdl, $resident, today()->addDays(4));

    $this->actingAs($pdl)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('todo.assessmentEvalSoon', 1)
            ->where('todo.assessmentEvalSoon.0.severity', 'yellow')
            ->where('todo.totalYellow', 1));
});
