<?php

declare(strict_types=1);

use App\Enums\CareReportCategory;
use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

it('exposes the canonical category list as enum cases', function (): void {
    expect(CareReportCategory::values())->toBe([
        'Grundpflege',
        'Beobachtung',
        'Mobilität',
        'Medikation',
        'Übergabe',
        'Sonstiges',
    ]);
});

it('akzeptiert alle Enum-Werte beim Speichern eines Berichts', function (string $category): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($user)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => '2026-05-04 10:00:00',
            'category' => $category,
            'body' => 'Test-Eintrag fuer Kategorie '.$category.'.',
        ])
        ->assertRedirect();

    expect(CareReport::query()->where('category', $category)->exists())->toBeTrue();
})->with(CareReportCategory::values());

it('lehnt unbekannte Kategorien beim Speichern ab', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $user = User::factory()->for($location)->create();
    $user->assignRole('Pflegekraft');
    $user->locations()->syncWithoutDetaching([$location->id]);

    $this->actingAs($user)
        ->post('/care-reports', [
            'resident_id' => $resident->id,
            'occurred_at' => '2026-05-04 10:00:00',
            'category' => 'Foobar',
            'body' => 'Test-Eintrag.',
        ])
        ->assertSessionHasErrors('category');

    expect(CareReport::query()->count())->toBe(0);
});
