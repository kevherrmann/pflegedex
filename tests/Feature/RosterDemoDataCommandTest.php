<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\EmploymentArea;
use App\Enums\QualificationLevel;
use App\Enums\RosterStatus;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Roster;
use App\Models\ShiftCategoryStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('creates a realistic two-area care home and is idempotent', function (): void {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-01'])
        ->assertSuccessful();

    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-01'])
        ->assertSuccessful();

    // Zwei Wohnbereiche.
    $locationA = Location::query()->where('name', 'Wohnbereich A')->first();
    $locationB = Location::query()->where('name', 'Wohnbereich B')->first();
    expect($locationA)->not->toBeNull()
        ->and($locationB)->not->toBeNull()
        ->and(Location::query()->whereIn('name', ['Wohnbereich A', 'Wohnbereich B'])->count())->toBe(2);

    // Uebergreifende PDL mit Zugriff auf beide Bereiche.
    $pdl = User::query()->where('email', 'demo.pdl.dienstplan@pflegedex.local')->first();
    expect($pdl)->not->toBeNull()
        ->and($pdl->hasRole('PDL'))->toBeTrue()
        ->and($pdl->locations()->count())->toBe(2)
        ->and(Auth::attempt([
            'email' => 'demo.pdl.dienstplan@pflegedex.local',
            'password' => 'password',
        ]))->toBeTrue();

    // Rollen wurden defensiv angelegt, inkl. neuer WBL-Rolle.
    expect(Role::query()->whereIn('name', ['PDL', 'WBL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'])->count())->toBe(5);

    // Genau eine WBL pro Wohnbereich, examinierte Fachkraft.
    $wbls = User::query()->role('WBL')->with('employeeProfile')->get();
    expect($wbls)->toHaveCount(2)
        ->and($wbls->every(fn (User $u): bool => $u->employeeProfile?->qualification_level === QualificationLevel::Specialist))->toBeTrue()
        ->and($wbls->every(fn (User $u): bool => $u->employeeProfile?->is_nursing_specialist === true))->toBeTrue();

    // Qualifikationsmix im Pflegebereich (beide Bereiche zusammen).
    $nursingProfiles = EmployeeProfile::query()
        ->where('employment_area', EmploymentArea::Nursing->value)
        ->get();

    // Pro Bereich: 7 Fachkraefte (1 WBL, 3 Tag, 3 Nachtwachen-Team), 3
    // Assistenten, 2 Hilfskraefte. Der Nachtdienst verlangt eine Fachkraft,
    // daher ein dreikoepfiges Nachtwachen-Team statt nachtfaehiger Hilfskraefte.
    expect($nursingProfiles)->toHaveCount(24)
        ->and($nursingProfiles->where('qualification_level', QualificationLevel::Specialist)->count())->toBe(14)
        ->and($nursingProfiles->where('qualification_level', QualificationLevel::Assistant)->count())->toBe(6)
        ->and($nursingProfiles->where('qualification_level', QualificationLevel::Aide)->count())->toBe(4);

    // is_nursing_specialist wird konsistent aus der Qualifikationsstufe abgeleitet.
    expect($nursingProfiles->every(
        fn (EmployeeProfile $p): bool => $p->is_nursing_specialist === ($p->qualification_level === QualificationLevel::Specialist)
    ))->toBeTrue();

    // Hauswirtschaft und Technik.
    expect(User::query()->role('Putzkraft')->count())->toBe(2)
        ->and(User::query()->role('Hausmeister')->count())->toBe(1)
        ->and(EmployeeProfile::query()->where('employment_area', EmploymentArea::Cleaning->value)->count())->toBe(2);

    // 20 Bewohner pro Bereich mit Pflegegraden 2 bis 5.
    expect(Resident::query()->where('location_id', $locationA->id)->count())->toBe(20)
        ->and(Resident::query()->where('location_id', $locationB->id)->count())->toBe(20)
        ->and(Resident::query()->whereBetween('care_level', [2, 5])->count())->toBe(40);

    // Bewohnernamen sind ueber beide Wohnbereiche eindeutig (keine Dubletten).
    $residentNames = Resident::query()
        ->where('pseudonym', 'like', 'P-DEMO-%')
        ->get()
        ->map(fn (Resident $r): string => $r->first_name.' '.$r->last_name);

    expect($residentNames)->toHaveCount(40)
        ->and($residentNames->unique()->count())->toBe(40);

    // Schichtvorlagen und Besetzungsregeln pro Bereich.
    foreach ([$locationA, $locationB] as $location) {
        $templates = ShiftTemplate::query()
            ->where('location_id', $location->id)
            ->whereIn('code', ['early', 'late', 'night'])
            ->get();

        expect($templates)->toHaveCount(3)
            ->and(ShiftCategoryStaffingRule::query()->where('location_id', $location->id)->count())->toBe(3);

        $roster = Roster::query()
            ->where('location_id', $location->id)
            ->where('year', 2027)
            ->where('month', 1)
            ->first();

        expect($roster)->not->toBeNull()
            ->and($roster->status)->toBe(RosterStatus::Draft);
    }

    // Genehmigte Abwesenheiten in beiden Bereichen.
    expect(AbsenceRequest::query()->where('status', AbsenceRequestStatus::Approved)->count())->toBe(6);
});

it('rejects invalid demo months', function (): void {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-13'])
        ->assertFailed();
});
