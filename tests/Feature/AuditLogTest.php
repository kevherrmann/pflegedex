<?php

declare(strict_types=1);

use App\Models\Audit;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['Admin', 'PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Carbon::setTestNow('2026-05-05 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('laesst Admin den Audit-Log oeffnen', function (): void {
    $location = Location::factory()->create();
    $admin = User::factory()->for($location)->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Audit/Index'));
});

it('verbietet allen Nicht-Admin-Rollen den Zugriff', function (string $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('audit.index'))
        ->assertForbidden();
})->with(['PDL', 'Pflegekraft', 'Putzkraft', 'Hausmeister']);

it('zeigt Audit-Eintraege chronologisch absteigend', function (): void {
    $location = Location::factory()->create();
    $resident1 = Resident::factory()->for($location)->create(['first_name' => 'Erster']);
    $resident2 = Resident::factory()->for($location)->create(['first_name' => 'Zweiter']);

    // Update aelterer Eintrag, dann juengerer
    Carbon::setTestNow('2026-05-05 10:00:00');
    $resident1->update(['first_name' => 'Erster-Updated']);
    Carbon::setTestNow('2026-05-05 11:00:00');
    $resident2->update(['first_name' => 'Zweiter-Updated']);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('Admin');

    $this->actingAs($pdl)
        ->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('pagination.total', fn (int $n) => $n >= 4)
        );
});

it('filtert nach Modell-Typ', function (): void {
    $location = Location::factory()->create();
    $resident = Resident::factory()->for($location)->create();
    $resident->update(['first_name' => 'Geaendert']);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('Admin');

    $this->actingAs($pdl)
        ->get(route('audit.index', ['model' => 'resident']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('filters.model', 'resident')
            ->has('audits', fn (Assert $list) => $list->each(fn (Assert $a) => $a
                ->where('modelKey', 'resident')
                ->etc()
            ))
        );
});

it('filtert nach Benutzer', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('Admin');

    // PDL selbst aendert einen Bewohner -> Audit mit user_id=pdl
    $resident = Resident::factory()->for($location)->create();
    $this->actingAs($pdl);
    $resident->update(['first_name' => 'Geaendert']);

    $this->get(route('audit.index', ['user_id' => $pdl->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('filters.userId', $pdl->id)
            ->has('audits', fn (Assert $list) => $list->each(fn (Assert $a) => $a
                ->where('userName', $pdl->name)
                ->etc()
            ))
        );
});

it('filtert nach Datum', function (): void {
    $location = Location::factory()->create();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $resident = Resident::factory()->for($location)->create();

    Carbon::setTestNow('2026-05-04 10:00:00');
    $resident->update(['first_name' => 'Spaet']);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('Admin');

    $this->actingAs($pdl)
        ->get(route('audit.index', ['from' => '2026-05-03', 'to' => '2026-05-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('filters.from', '2026-05-03')
            ->where('filters.to', '2026-05-05')
            ->has('audits', fn (Assert $list) => $list->each(fn (Assert $a) => $a->etc()))
        );
});

it('paginiert mit 25 Eintraegen pro Seite', function (): void {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('Admin');

    // 30 Audit-Eintraege erzeugen via Resident-Updates
    $resident = Resident::factory()->for($location)->create();
    for ($i = 0; $i < 30; $i++) {
        $resident->update(['first_name' => 'Iter-'.$i]);
    }

    $this->actingAs($pdl)
        ->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('pagination.perPage', 25)
            ->has('audits', 25)
            ->where('pagination.lastPage', fn (int $n) => $n >= 2)
        );

    $this->actingAs($pdl)
        ->get(route('audit.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Audit/Index')
            ->where('pagination.currentPage', 2)
        );
});
