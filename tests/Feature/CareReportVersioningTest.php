<?php

declare(strict_types=1);

use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('creates an initial report version when a care report is stored', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($user)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'category' => 'Grundpflege',
            'body' => 'Bewohnerin war mobil und orientiert.',
        ])
        ->assertRedirect();

    $report = CareReport::query()->firstOrFail();

    expect($report->versions)->toHaveCount(1)
        ->and($report->versions->first()->content_snapshot)->toBe('Bewohnerin war mobil und orientiert.')
        ->and($report->versions->first()->snapshot_reason)->toBe('created');
});

it('appends a report version when an unsigned care report is changed', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $report = CareReport::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'author_id' => $user->id,
        'body' => 'Alter Text.',
        'signed' => false,
    ]);

    $report->appendVersion('created', $user);

    $this->actingAs($user)
        ->patch('/care-reports/' . $report->id, [
            'occurred_at' => $report->occurred_at->format('Y-m-d H:i:s'),
            'category' => $report->category,
            'body' => 'Neuer Text.',
        ])
        ->assertRedirect();

    $report->refresh();

    expect($report->body)->toBe('Neuer Text.')
        ->and($report->versions)->toHaveCount(2)
        ->and($report->versions()->latest('created_at')->first()->content_snapshot)->toBe('Neuer Text.')
        ->and($report->versions()->latest('created_at')->first()->snapshot_reason)->toBe('updated');
});

it('prevents changing a signed care report', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $report = CareReport::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'author_id' => $user->id,
        'body' => 'Finaler Text.',
        'signed' => true,
        'signed_at' => now(),
        'signed_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->patch('/care-reports/' . $report->id, [
            'occurred_at' => $report->occurred_at->format('Y-m-d H:i:s'),
            'category' => $report->category,
            'body' => 'Manipulierter Text.',
        ])
        ->assertForbidden();

    expect($report->refresh()->body)->toBe('Finaler Text.');
});

it('lets Pflegekraft users sign their own accessible care reports', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();

    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $report = CareReport::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'author_id' => $user->id,
        'body' => 'Zu signierender Text.',
        'signed' => false,
    ]);

    $this->actingAs($user)
        ->post('/care-reports/' . $report->id . '/sign')
        ->assertRedirect();

    $report->refresh();

    expect($report->signed)->toBeTrue()
        ->and($report->signed_by)->toBe($user->id)
        ->and($report->signed_at)->not->toBeNull()
        ->and($report->versions()->where('snapshot_reason', 'signed')->exists())->toBeTrue();
});

it('exposes signing state in the care report inertia payload', function (): void {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $resident = Resident::factory()->for($location)->create([
        'first_name' => 'Erika',
        'last_name' => 'Mustermann',
    ]);

    $user = User::factory()->for($location)->create(['name' => 'Nina Pflege']);
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $report = CareReport::factory()->create([
        'resident_id' => $resident->id,
        'location_id' => $location->id,
        'author_id' => $user->id,
        'category' => 'Grundpflege',
        'body' => 'Final dokumentierter Bericht.',
        'occurred_at' => '2026-05-03 10:15:00',
        'signed' => true,
        'signed_at' => '2026-05-03 10:20:00',
        'signed_by' => $user->id,
    ]);

    $report->appendVersion('created', $user);
    $report->appendVersion('signed', $user);

    $this->actingAs($user)
        ->get('/care-reports?resident_id=' . $resident->id . '&date=2026-05-03')
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->component('CareReports/Index')
                ->where('reportsByCategory.Grundpflege.0.id', $report->id)
                ->where('reportsByCategory.Grundpflege.0.signed', true)
                ->where('reportsByCategory.Grundpflege.0.signedAt', '03.05.2026 10:20')
                ->where('reportsByCategory.Grundpflege.0.signedByName', 'Nina Pflege')
                ->where('reportsByCategory.Grundpflege.0.versionCount', 2)
        );
});