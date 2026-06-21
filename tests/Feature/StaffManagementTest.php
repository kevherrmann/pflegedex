<?php

use App\Enums\EmploymentArea;
use App\Enums\QualificationLevel;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['Admin', 'PDL', 'WBL', 'Pflegekraft', 'Putzkraft', 'Hausmeister'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('allows only PDL users to open staff management', function () {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $staff = User::factory()->for($location)->create(['name' => 'Pflege Eins']);
    $staff->assignRole('Pflegekraft');

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($pdl)
        ->get('/staff')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Staff/Index')
                ->has('staffUsers', 1)
                ->has('locations', 1)
                ->where('staffUsers.0.name', 'Pflege Eins')
        );

    $this->actingAs($admin)->get('/staff')->assertForbidden();
});

it('lets PDL users create operational staff for accessible Wohnbereiche only', function () {
    $first = Location::factory()->create(['name' => 'Wohnbereich A']);
    $second = Location::factory()->create(['name' => 'Wohnbereich B']);
    $foreign = Location::factory()->create(['name' => 'Wohnbereich Fremd']);
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Neue Pflegekraft',
            'email' => 'pflege@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'Pflegekraft',
            'location_ids' => [$first->id, $second->id],
            'is_nursing_specialist' => true,
            'weekly_hours' => 30,
            'regular_work_days_per_week' => 4,
            'annual_vacation_days' => 28,
            'vacation_days_carried_over' => 2,
            'overtime_minutes_balance' => 120,
            'can_work_early' => true,
            'can_work_late' => true,
            'can_work_night' => false,
            'active' => true,
        ])
        ->assertRedirect('/staff');

    $staff = User::query()->where('email', 'pflege@pflegedex.local')->first();
    $staff->load('employeeProfile');

    expect($staff)->not->toBeNull()
        ->and($staff->hasRole('Pflegekraft'))->toBeTrue()
        ->and($staff->location_id)->toBe($first->id)
        ->and($staff->locations()->pluck('locations.id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and(Hash::check('Sicheres-Passwort1', $staff->password))->toBeTrue()
        ->and($staff->employeeProfile)->not->toBeNull()
        ->and($staff->employeeProfile->employment_area)->toBe(EmploymentArea::Nursing)
        ->and($staff->employeeProfile->is_nursing_specialist)->toBeTrue()
        ->and($staff->employeeProfile->weekly_hours)->toBe('30.00')
        ->and($staff->employeeProfile->regular_work_days_per_week)->toBe(4)
        ->and($staff->employeeProfile->annual_vacation_days)->toBe(28)
        ->and($staff->employeeProfile->vacation_days_carried_over)->toBe(2)
        ->and($staff->employeeProfile->overtime_minutes_balance)->toBe(120)
        ->and($staff->employeeProfile->can_work_early)->toBeTrue()
        ->and($staff->employeeProfile->can_work_late)->toBeTrue()
        ->and($staff->employeeProfile->can_work_night)->toBeFalse();

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Fremde Pflegekraft',
            'email' => 'fremd@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'Pflegekraft',
            'location_ids' => [$foreign->id],
        ])
        ->assertSessionHasErrors('location_ids');

    expect(User::query()->where('email', 'fremd@pflegedex.local')->exists())->toBeFalse();
});

it('prevents PDL users from creating admin or PDL accounts through staff management', function () {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    foreach (['Admin', 'PDL'] as $role) {
        $this->actingAs($pdl)
            ->post('/staff', [
                'name' => 'Verboten '.$role,
                'email' => strtolower($role).'@pflegedex.local',
                'password' => 'Sicheres-Passwort1',
                'role' => $role,
                'location_ids' => [$location->id],
            ])
            ->assertSessionHasErrors('role');
    }
});

it('lets PDL users edit staff in their Wohnbereiche', function () {
    $first = Location::factory()->create(['name' => 'Wohnbereich A']);
    $second = Location::factory()->create(['name' => 'Wohnbereich B']);
    $pdl = User::factory()->for($first)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$first->id, $second->id]);

    $staff = User::factory()->for($first)->create(['name' => 'Alter Name', 'email' => 'alt@pflegedex.local']);
    $staff->assignRole('Pflegekraft');
    $staff->locations()->attach([$first->id]);

    $this->actingAs($pdl)
        ->get('/staff/'.$staff->id.'/edit')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Staff/Edit')
                ->where('staffUser.name', 'Alter Name')
                ->has('locations', 2)
        );

    $this->actingAs($pdl)
        ->patch('/staff/'.$staff->id, [
            'name' => 'Neuer Name',
            'email' => 'neu@pflegedex.local',
            'password' => 'Neues-Passwort1',
            'role' => 'Hausmeister',
            'location_ids' => [$second->id],
            'is_nursing_specialist' => false,
            'weekly_hours' => 20,
            'regular_work_days_per_week' => 3,
            'annual_vacation_days' => 24,
            'vacation_days_carried_over' => 1,
            'overtime_minutes_balance' => -60,
            'can_work_early' => true,
            'can_work_late' => false,
            'can_work_night' => false,
            'active' => true,
        ])
        ->assertRedirect('/staff');

    $staff->refresh();
    $staff->load('employeeProfile');
    expect($staff->name)->toBe('Neuer Name')
        ->and($staff->email)->toBe('neu@pflegedex.local')
        ->and($staff->hasRole('Hausmeister'))->toBeTrue()
        ->and($staff->location_id)->toBe($second->id)
        ->and($staff->locations()->pluck('locations.id')->all())->toEqualCanonicalizing([$second->id])
        ->and(Hash::check('Neues-Passwort1', $staff->password))->toBeTrue()->and($staff->employeeProfile)->not->toBeNull()
        ->and($staff->employeeProfile->employment_area)->toBe(EmploymentArea::Caretaker)
        ->and($staff->employeeProfile->is_nursing_specialist)->toBeFalse()
        ->and($staff->employeeProfile->weekly_hours)->toBe('20.00')
        ->and($staff->employeeProfile->regular_work_days_per_week)->toBe(3)
        ->and($staff->employeeProfile->annual_vacation_days)->toBe(24)
        ->and($staff->employeeProfile->vacation_days_carried_over)->toBe(1)
        ->and($staff->employeeProfile->overtime_minutes_balance)->toBe(-60)
        ->and($staff->employeeProfile->can_work_early)->toBeTrue()
        ->and($staff->employeeProfile->can_work_late)->toBeFalse()
        ->and($staff->employeeProfile->can_work_night)->toBeFalse();
});

it('prevents PDL users from editing staff outside their Wohnbereiche', function () {
    $own = Location::factory()->create();
    $foreign = Location::factory()->create();
    $pdl = User::factory()->for($own)->create();
    $pdl->assignRole('PDL');

    $staff = User::factory()->for($foreign)->create();
    $staff->assignRole('Pflegekraft');
    $staff->locations()->attach([$foreign->id]);

    $this->actingAs($pdl)->get('/staff/'.$staff->id.'/edit')->assertForbidden();

    $this->actingAs($pdl)
        ->patch('/staff/'.$staff->id, [
            'name' => 'Verboten',
            'email' => 'verboten@pflegedex.local',
            'role' => 'Pflegekraft',
            'location_ids' => [$own->id],
        ])
        ->assertForbidden();
});

it('maps cleaning staff to cleaning employee profiles', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Putz Eins',
            'email' => 'putz@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'Putzkraft',
            'location_ids' => [$location->id],
            'weekly_hours' => 25,
            'annual_vacation_days' => 26,
            'can_work_early' => true,
            'can_work_late' => false,
            'can_work_night' => false,
        ])
        ->assertRedirect('/staff');

    $staff = User::query()
        ->where('email', 'putz@pflegedex.local')
        ->with('employeeProfile')
        ->firstOrFail();

    expect($staff->employeeProfile)->not->toBeNull()
        ->and($staff->employeeProfile->employment_area)->toBe(EmploymentArea::Cleaning)
        ->and($staff->employeeProfile->is_nursing_specialist)->toBeFalse()
        ->and($staff->employeeProfile->weekly_hours)->toBe('25.00')
        ->and($staff->employeeProfile->annual_vacation_days)->toBe(26)
        ->and($staff->employeeProfile->can_work_early)->toBeTrue()
        ->and($staff->employeeProfile->can_work_late)->toBeFalse()
        ->and($staff->employeeProfile->can_work_night)->toBeFalse();
});

it('maps caretakers to caretaker employee profiles and excludes absence requests', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Hausmeister Eins',
            'email' => 'hausmeister@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'Hausmeister',
            'location_ids' => [$location->id],
            'weekly_hours' => 39,
            'annual_vacation_days' => 30,
            'can_work_early' => true,
            'can_work_late' => false,
            'can_work_night' => false,
        ])
        ->assertRedirect('/staff');

    $staff = User::query()
        ->where('email', 'hausmeister@pflegedex.local')
        ->with('employeeProfile')
        ->firstOrFail();

    expect($staff->employeeProfile)->not->toBeNull()
        ->and($staff->employeeProfile->employment_area)->toBe(EmploymentArea::Caretaker)
        ->and($staff->employeeProfile->canRequestAbsence())->toBeFalse()
        ->and($staff->canRequestAbsence())->toBeFalse();
});

it('passes employee profile data to the staff edit page', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $staff = User::factory()->for($location)->create([
        'name' => 'Anna Pflege',
        'email' => 'anna.pflege@pflegedex.local',
    ]);

    $staff->assignRole('Pflegekraft');
    $staff->locations()->sync([$location->id]);

    $staff->employeeProfile()->create([
        'employment_area' => EmploymentArea::Nursing,
        'is_nursing_specialist' => true,
        'weekly_hours' => 32,
        'regular_work_days_per_week' => 4,
        'annual_vacation_days' => 28,
        'vacation_days_carried_over' => 2,
        'overtime_minutes_balance' => 90,
        'can_work_early' => true,
        'can_work_late' => true,
        'can_work_night' => false,
        'active' => true,
    ]);

    $this->actingAs($pdl)
        ->get('/staff/'.$staff->id.'/edit')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('Staff/Edit')
                ->where('staffUser.employeeProfile.employmentArea', EmploymentArea::Nursing->value)
                ->where('staffUser.employeeProfile.employmentAreaLabel', 'Pflege')
                ->where('staffUser.employeeProfile.isNursingSpecialist', true)
                ->where('staffUser.employeeProfile.weeklyHours', '32.00')
                ->where('staffUser.employeeProfile.regularWorkDaysPerWeek', 4)
                ->where('staffUser.employeeProfile.annualVacationDays', 28)
                ->where('staffUser.employeeProfile.vacationDaysCarriedOver', 2)
                ->where('staffUser.employeeProfile.overtimeMinutesBalance', 90)
                ->where('staffUser.employeeProfile.canWorkEarly', true)
                ->where('staffUser.employeeProfile.canWorkLate', true)
                ->where('staffUser.employeeProfile.canWorkNight', false)
                ->where('staffUser.employeeProfile.active', true)
        );
});

it('creates a WBL as nursing specialist with a specialist qualification level', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Wohnbereichsleitung Eins',
            'email' => 'wbl@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'WBL',
            'location_ids' => [$location->id],
            'qualification_level' => 'specialist',
            'weekly_hours' => 19.5,
            'can_work_early' => true,
            'can_work_late' => false,
            'can_work_night' => false,
        ])
        ->assertRedirect('/staff');

    $staff = User::query()
        ->where('email', 'wbl@pflegedex.local')
        ->with('employeeProfile')
        ->firstOrFail();

    expect($staff->hasRole('WBL'))->toBeTrue()
        ->and($staff->employeeProfile->employment_area)->toBe(EmploymentArea::Nursing)
        ->and($staff->employeeProfile->qualification_level)->toBe(QualificationLevel::Specialist)
        ->and($staff->employeeProfile->is_nursing_specialist)->toBeTrue();
});

it('forces a WBL to be a specialist even if another qualification is submitted', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Wohnbereichsleitung Zwei',
            'email' => 'wbl2@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'WBL',
            'location_ids' => [$location->id],
            // Bewusst widerspruechlich: eine WBL ist immer Fachkraft.
            'qualification_level' => 'aide',
            'is_nursing_specialist' => false,
            'weekly_hours' => 20,
        ])
        ->assertRedirect('/staff');

    $staff = User::query()
        ->where('email', 'wbl2@pflegedex.local')
        ->with('employeeProfile')
        ->firstOrFail();

    expect($staff->employeeProfile->qualification_level)->toBe(QualificationLevel::Specialist)
        ->and($staff->employeeProfile->is_nursing_specialist)->toBeTrue();
});

it('derives is_nursing_specialist from the qualification level for assistants and aides', function (string $level): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->post('/staff', [
            'name' => 'Pflege '.$level,
            'email' => $level.'@pflegedex.local',
            'password' => 'Sicheres-Passwort1',
            'role' => 'Pflegekraft',
            'location_ids' => [$location->id],
            'qualification_level' => $level,
            // Bewusst widerspruechlich gesetzt: die Qualifikationsstufe gewinnt.
            'is_nursing_specialist' => true,
            'weekly_hours' => 30,
        ])
        ->assertRedirect('/staff');

    $staff = User::query()
        ->where('email', $level.'@pflegedex.local')
        ->with('employeeProfile')
        ->firstOrFail();

    expect($staff->employeeProfile->qualification_level->value)->toBe($level)
        ->and($staff->employeeProfile->is_nursing_specialist)->toBeFalse();
})->with(['assistant', 'aide']);

it('persists special scheduling rules for nursing staff', function () {
    $location = Location::factory()->create();
    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');
    $pdl->locations()->attach([$location->id]);

    $staff = User::factory()->for($location)->create(['email' => 'fk@pflegedex.local']);
    $staff->assignRole('Pflegekraft');
    $staff->locations()->attach([$location->id]);

    $this->actingAs($pdl)
        ->patch('/staff/'.$staff->id, [
            'name' => 'Fachkraft Test',
            'email' => 'fk@pflegedex.local',
            'role' => 'Pflegekraft',
            'location_ids' => [$location->id],
            'qualification_level' => 'aide',
            'weekly_hours' => 39,
            'avoids_weekends' => true,
            'week_rotation' => 'even',
            'fixed_free_weekdays' => [1, 3],
            'max_consecutive_days_override' => 4,
            'scheduling_note' => 'Bevorzugt Frühdienste.',
            'can_work_early' => true,
            'can_work_late' => true,
            'can_work_night' => false,
            'active' => true,
        ])
        ->assertRedirect('/staff');

    $staff->refresh()->load('employeeProfile');

    expect($staff->employeeProfile->avoids_weekends)->toBeTrue()
        ->and($staff->employeeProfile->week_rotation)->toBe('even')
        ->and($staff->employeeProfile->fixed_free_weekdays)->toEqualCanonicalizing([1, 3])
        ->and($staff->employeeProfile->max_consecutive_days_override)->toBe(4)
        ->and($staff->employeeProfile->scheduling_note)->toBe('Bevorzugt Frühdienste.');
});
