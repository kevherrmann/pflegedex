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

it('shows care report completion for the selected day only', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $resident = Resident::factory()->for($location)->create(['first_name' => 'Erika', 'last_name' => 'Mustermann']);
    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->attach($location->id);

    CareReport::factory()->for($resident)->for($location, 'location')->for($nurse, 'author')->create([
        'category' => 'Grundpflege',
        'body' => 'Bericht für den ausgewählten Tag',
        'occurred_at' => '2026-05-01 10:15',
    ]);
    CareReport::factory()->for($resident)->for($location, 'location')->for($nurse, 'author')->create([
        'category' => 'Mobilität',
        'body' => 'Bericht vom Vortag',
        'occurred_at' => '2026-04-30 10:15',
    ]);

    $this->actingAs($nurse)
        ->get('/care-reports?date=2026-05-01&resident_id='.$resident->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CareReports/Index')
            ->where('selectedDate', '2026-05-01')
            ->where('selectedResident.completedCategoryCount', 1)
            ->where('selectedResident.missingCategoryCount', 5)
            ->where('categoryTabs.0.name', 'Grundpflege')
            ->where('categoryTabs.0.completed', true)
            ->where('categoryTabs.2.name', 'Mobilität')
            ->where('categoryTabs.2.completed', false)
            ->has('reportsByCategory.Grundpflege', 1)
            ->where('reportsByCategory.Grundpflege.0.body', 'Bericht für den ausgewählten Tag')
            ->has('reportsByCategory.Mobilität', 0)
        );
});

it('keeps the selected day and resident after storing a care report', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $resident = Resident::factory()->for($location)->create();
    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');
    $nurse->locations()->attach($location->id);

    $this->actingAs($nurse)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => '2026-05-01 14:30',
            'category' => 'Beobachtung',
            'body' => 'Bewohner wirkte heute aufmerksam.',
        ])
        ->assertRedirect('/care-reports?resident_id='.$resident->id.'&date=2026-05-01');
});
