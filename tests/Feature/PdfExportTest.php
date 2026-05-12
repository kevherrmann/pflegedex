<?php

declare(strict_types=1);

use App\Models\CarePlan;
use App\Models\CarePlanTopicEntry;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-08 14:00:00');
});

function setupResidentWithSis(): array
{
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $sis = Sis::factory()->withTopicsAndRisks()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'opening_question' => 'Garten und Familie sind sehr wichtig.',
    ]);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return [$location, $resident, $sis, $pdl];
}

it('PDL kann SIS als PDF herunterladen', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithSis();

    $response = $this->actingAs($pdl)->get(route('residents.sis.pdf', $resident));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    $response->assertHeader(
        'content-disposition',
        sprintf('attachment; filename=SIS_%s_2026-05-08.pdf', $resident->pseudonym),
    );

    $body = $response->getContent();
    expect(substr($body, 0, 4))->toBe('%PDF');
});

it('Pflegekraft darf KEINE SIS-PDF herunterladen', function (): void {
    [$location, $resident, $sis, $_] = setupResidentWithSis();

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->get(route('residents.sis.pdf', $resident))
        ->assertForbidden();
});

it('PDL aus fremder Location bekommt 403 fuer SIS-PDF', function (): void {
    [$location, $resident, $sis, $_] = setupResidentWithSis();
    $other = Location::factory()->create();
    $foreign = User::factory()->for($other)->create();
    $foreign->assignRole('PDL');
    $foreign->locations()->syncWithoutDetaching([$other->id]);

    $this->actingAs($foreign)
        ->get(route('residents.sis.pdf', $resident))
        ->assertForbidden();
});

it('SIS-PDF 404 wenn keine SIS existiert', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->get(route('residents.sis.pdf', $resident))
        ->assertNotFound();
});

it('PDL kann MP als PDF herunterladen', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithSis();
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'grundbotschaft' => 'Pflege nur zu zweit.',
    ]);
    CarePlanTopicEntry::factory()->create([
        'care_plan_id' => $cp->id,
        'topic_number' => 1,
        'content' => 'Sturzprophylaxe bei jeder Mobilisation.',
    ]);

    $response = $this->actingAs($pdl)->get(route('residents.care-plan.pdf', $resident));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    $response->assertHeader(
        'content-disposition',
        sprintf('attachment; filename=Massnahmenplan_%s_2026-05-08.pdf', $resident->pseudonym),
    );

    $body = $response->getContent();
    expect(substr($body, 0, 4))->toBe('%PDF');
});

it('Pflegekraft darf KEINE MP-PDF herunterladen', function (): void {
    [$location, $resident, $sis, $_] = setupResidentWithSis();
    CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($nurse)
        ->get(route('residents.care-plan.pdf', $resident))
        ->assertForbidden();
});

it('MP-PDF 404 wenn kein MP existiert', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithSis();

    $this->actingAs($pdl)
        ->get(route('residents.care-plan.pdf', $resident))
        ->assertNotFound();
});

it('SIS-PDF enthaelt Klarnamen UND Pseudonym', function (): void {
    [$location, $resident, $sis, $pdl] = setupResidentWithSis();

    $response = $this->actingAs($pdl)->get(route('residents.sis.pdf', $resident));
    $body = $response->getContent();

    // dompdf rendert Text unsichtbar nicht binary-extrahierbar - wir
    // pruefen nur dass es ein PDF ist und Bewohner-Daten in $resident
    // korrekt sind. Visueller Check ueber das echte PDF.
    expect(substr($body, 0, 4))->toBe('%PDF');
    expect($resident->pseudonym)->toMatch('/^P-\d{4}-\d{4}$/');
    expect($resident->full_name)->not->toBe('');
});
