<?php

use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('stores a care report for an assigned resident as Pflegekraft', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $resident = Resident::factory()->for($location)->create(['first_name' => 'Erika', 'last_name' => 'Mustermann']);
    $nurse = User::factory()->for($location)->create(['name' => 'Carl Pflegekraft']);
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->attach($location->id);

    $this->actingAs($nurse)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => '2026-05-01 10:15',
            'category' => 'Grundpflege',
            'body' => 'Bewohnerin wurde bei der Morgenpflege unterstützt.',
        ])
        ->assertRedirect('/care-reports');

    $report = CareReport::query()->first();

    expect($report)->not->toBeNull()
        ->and($report->resident_id)->toBe($resident->id)
        ->and($report->location_id)->toBe($location->id)
        ->and($report->author_id)->toBe($nurse->id)
        ->and($report->category)->toBe('Grundpflege')
        ->and($report->body)->toBe('Bewohnerin wurde bei der Morgenpflege unterstützt.');
});

it('lists care reports only from accessible residents', function () {
    $own = Location::factory()->create(['name' => 'Wohnbereich A']);
    $foreign = Location::factory()->create(['name' => 'Wohnbereich B']);
    $ownResident = Resident::factory()->for($own)->create(['first_name' => 'Erika', 'last_name' => 'Mustermann']);
    $foreignResident = Resident::factory()->for($foreign)->create(['first_name' => 'Karl', 'last_name' => 'Beispiel']);
    $nurse = User::factory()->for($own)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->attach($own->id);

    CareReport::factory()->for($ownResident)->for($own, 'location')->for($nurse, 'author')->create([
        'body' => 'Sichtbarer Bericht',
        'occurred_at' => '2026-05-01 10:15',
    ]);
    CareReport::factory()->for($foreignResident)->for($foreign, 'location')->for(User::factory(), 'author')->create([
        'body' => 'Versteckter Bericht',
        'occurred_at' => '2026-05-01 11:15',
    ]);

    $this->actingAs($nurse)
        ->get('/care-reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CareReports/Index')
            ->has('reports', 1)
            ->where('reports.0.body', 'Sichtbarer Bericht')
            ->where('reports.0.residentName', 'Erika Mustermann')
            ->has('residents', 1)
            ->where('residents.0.fullName', 'Erika Mustermann')
        );
});

it('prevents Pflegekraft from reporting on unassigned residents', function () {
    $own = Location::factory()->create();
    $foreign = Location::factory()->create();
    $foreignResident = Resident::factory()->for($foreign)->create();
    $nurse = User::factory()->for($own)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->attach($own->id);

    $this->actingAs($nurse)
        ->post('/care-reports', [
            'resident_id' => $foreignResident->id,
            'occurred_at' => '2026-05-01 10:15',
            'category' => 'Beobachtung',
            'body' => 'Darf nicht gespeichert werden.',
        ])
        ->assertSessionHasErrors('resident_id');

    expect(CareReport::query()->count())->toBe(0);
});

it('allows PDL users to see and create reports for their accessible Wohnbereiche', function () {
    $first = Location::factory()->create(['name' => 'Wohnbereich A']);
    $second = Location::factory()->create(['name' => 'Wohnbereich B']);
    $resident = Resident::factory()->for($second)->create(['first_name' => 'Karl', 'last_name' => 'Beispiel']);
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);

    $this->actingAs($pdl)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => '2026-05-01 09:00',
            'category' => 'Übergabe',
            'body' => 'PDL ergänzt Hinweis für die Übergabe.',
        ])
        ->assertRedirect('/care-reports');

    $this->actingAs($pdl)
        ->get('/care-reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CareReports/Index')
            ->has('reports', 1)
            ->where('reports.0.residentName', 'Karl Beispiel')
            ->has('residents', 1)
        );
});

it('does not allow Admin Putzkraft or Hausmeister to access care reports', function (string $role) {
    $location = Location::factory()->create();
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);
    $user->locations()->attach($location->id);

    $this->actingAs($user)->get('/care-reports')->assertForbidden();

    $this->actingAs($user)
        ->post('/care-reports', [
            'resident_id' => Resident::factory()->for($location)->create()->id,
            'occurred_at' => '2026-05-01 10:15',
            'category' => 'Beobachtung',
            'body' => 'Nicht erlaubt.',
        ])
        ->assertForbidden();
})->with(['Admin', 'Putzkraft', 'Hausmeister']);
