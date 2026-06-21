<?php

declare(strict_types=1);

use App\Models\CarePlan;
use App\Models\CarePlanTopicEntry;
use App\Models\CarePlanVersion;
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
    Carbon::setTestNow('2026-05-08 10:00:00');
});

function makePdlWithCarePlan(): array
{
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    $cp = CarePlan::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    return [$location, $resident, $cp, $pdl];
}

it('Update legt Topics on-demand an, leere Felder werden ignoriert', function (): void {
    [$location, $resident, $cp, $pdl] = makePdlWithCarePlan();

    $this->actingAs($pdl)
        ->patch(route('residents.care-plan.update', $resident), [
            'grundbotschaft' => 'Update',
            'topics' => [
                ['topic_number' => 1, 'content' => 'Mobilitaet inhalt'],
                ['topic_number' => 2, 'content' => '   '], // nur Whitespace -> wird ignoriert
                ['topic_number' => 5, 'content' => 'Medikation inhalt'],
            ],
        ])
        ->assertRedirect();

    $topics = $cp->fresh()->topics;
    expect($topics)->toHaveCount(2)
        ->and($topics->pluck('topic_number')->sort()->values()->all())->toBe([1, 5]);
});

it('Update entfernt vorhandene Topics wenn Inhalt geleert wird', function (): void {
    [$location, $resident, $cp, $pdl] = makePdlWithCarePlan();

    CarePlanTopicEntry::factory()->create([
        'care_plan_id' => $cp->id,
        'topic_number' => 3,
        'content' => 'alter Inhalt',
    ]);

    $this->actingAs($pdl)
        ->patch(route('residents.care-plan.update', $resident), [
            'grundbotschaft' => null,
            'topics' => [
                ['topic_number' => 3, 'content' => ''],
            ],
        ])
        ->assertRedirect();

    expect(CarePlanTopicEntry::query()->where('care_plan_id', $cp->id)->count())->toBe(0);
});

it('Update schreibt Versions-Snapshot vor der Aenderung', function (): void {
    [$location, $resident, $cp, $pdl] = makePdlWithCarePlan();

    CarePlanTopicEntry::factory()->create([
        'care_plan_id' => $cp->id,
        'topic_number' => 1,
        'content' => 'alter Inhalt',
    ]);

    $this->actingAs($pdl)
        ->patch(route('residents.care-plan.update', $resident), [
            'grundbotschaft' => 'neu',
            'topics' => [['topic_number' => 1, 'content' => 'neuer Inhalt']],
        ])
        ->assertRedirect();

    $version = CarePlanVersion::query()->where('care_plan_id', $cp->id)
        ->where('snapshot_reason', 'updated')
        ->latest('created_at')
        ->first();

    expect($version)->not->toBeNull();
    $snapshot = json_decode($version->content_snapshot, true);
    // Snapshot soll den Zustand VOR dem Update enthalten
    expect($snapshot['topics'][0]['content'])->toBe('alter Inhalt');
});

it('Evaluate setzt evaluated_at und next_evaluation_due', function (): void {
    [$location, $resident, $cp, $pdl] = makePdlWithCarePlan();

    $this->actingAs($pdl)
        ->post(route('residents.care-plan.evaluate', $resident))
        ->assertRedirect();

    $cp->refresh();
    expect($cp->evaluated_at?->toDateString())->toBe('2026-05-08')
        ->and($cp->next_evaluation_due?->toDateString())->toBe('2026-07-03');

    $version = CarePlanVersion::query()->where('care_plan_id', $cp->id)
        ->where('snapshot_reason', 'evaluated')
        ->first();
    expect($version)->not->toBeNull();
});

it('Show zeigt MP-Daten korrekt im Inertia-Payload', function (): void {
    [$location, $resident, $cp, $pdl] = makePdlWithCarePlan();
    CarePlanTopicEntry::factory()->create([
        'care_plan_id' => $cp->id,
        'topic_number' => 1,
        'content' => 'Mobilitaetstext',
    ]);

    $this->actingAs($pdl)
        ->get(route('residents.care-plan.show', $resident))
        ->assertInertia(fn ($page) => $page
            ->component('CarePlan/Show')
            ->where('carePlan.grundbotschaft', $cp->grundbotschaft)
            ->where('sisStatus.completed', true)
            ->where('canEdit', true)
            ->has('carePlan.topics', 1)
            ->where('carePlan.topics.0.topicNumber', 1)
            ->where('carePlan.topics.0.content', 'Mobilitaetstext'));
});

it('Show ohne MP zeigt Anlege-Hinweis', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    Sis::factory()->withTopicsAndRisks()->completed()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
    ]);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($pdl)
        ->get(route('residents.care-plan.show', $resident))
        ->assertInertia(fn ($page) => $page
            ->component('CarePlan/Show')
            ->where('carePlan', null)
            ->where('sisStatus.exists', true)
            ->where('sisStatus.completed', true));
});
