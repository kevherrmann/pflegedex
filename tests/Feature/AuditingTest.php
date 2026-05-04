<?php

declare(strict_types=1);

use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-04 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('schreibt einen audit-Eintrag wenn ein Bewohner geaendert wird', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create([
        'first_name' => 'Alt',
        'last_name' => 'Bewohner',
    ]);

    $beforeCount = Audit::query()->where('auditable_id', $resident->id)->count();

    $resident->update(['first_name' => 'Neu']);

    $audits = Audit::query()
        ->where('auditable_type', Resident::class)
        ->where('auditable_id', $resident->id)
        ->get();

    expect($audits->count())->toBe($beforeCount + 1);

    $latest = $audits->last();
    expect($latest->event)->toBe('updated')
        ->and($latest->old_values['first_name'])->toBe('Alt')
        ->and($latest->new_values['first_name'])->toBe('Neu');
});

it('schreibt audit-Eintraege beim Anlegen eines CareReports ueber den Controller', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($user)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => '2026-05-04 10:00:00',
            'category' => 'Grundpflege',
            'body' => 'Demo-Bericht fuer Audit-Test.',
        ])
        ->assertRedirect();

    $report = CareReport::query()->firstOrFail();

    $audit = Audit::query()
        ->where('auditable_type', CareReport::class)
        ->where('auditable_id', $report->id)
        ->where('event', 'created')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->user_type)->toBe(User::class)
        ->and($audit->new_values['body'])->toBe('Demo-Bericht fuer Audit-Test.');
});

it('schliesst sensible Felder vom User-Audit aus', function (): void {
    $user = User::factory()->create([
        'name' => 'Original Name',
    ]);

    $user->update([
        'name' => 'Neuer Name',
        'password' => bcrypt('neues-passwort'),
    ]);

    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->toHaveKey('name')
        ->and($audit->new_values)->not->toHaveKey('password')
        ->and($audit->old_values)->not->toHaveKey('password');
});

it('schreibt KEINE audit-Eintraege beim Seeder-Lauf', function (): void {
    Audit::query()->delete();

    $this->seed();

    expect(Audit::query()->count())->toBe(0);
});
