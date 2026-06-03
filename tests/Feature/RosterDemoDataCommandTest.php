<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\RosterStatus;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('creates realistic roster demo data and is idempotent', function (): void {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-01'])
        ->assertSuccessful();

    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-01'])
        ->assertSuccessful();

    $location = Location::query()->where('name', 'Wohnbereich A')->first();
    expect($location)->not->toBeNull();

    $pdl = User::query()->where('email', 'demo.pdl.dienstplan@pflegedex.local')->first();
    expect($pdl)->not->toBeNull()
        ->and($pdl->hasRole('PDL'))->toBeTrue()
        ->and($pdl->location_id)->toBe($location->id)
        ->and($pdl->locations()->whereKey($location->id)->exists())->toBeTrue()
        ->and(Auth::attempt([
            'email' => 'demo.pdl.dienstplan@pflegedex.local',
            'password' => 'password',
        ]))->toBeTrue();

    $employees = User::query()
        ->where('email', 'like', 'demo.pflege.%@pflegedex.local')
        ->orderBy('email')
        ->get();

    expect($employees)->toHaveCount(12)
        ->and($employees->every(fn (User $employee): bool => $employee->hasRole('Pflegekraft')))->toBeTrue();

    expect(EmployeeProfile::query()->whereIn('user_id', $employees->pluck('id'))->count())->toBe(12);

    $templates = ShiftTemplate::query()
        ->where('location_id', $location->id)
        ->whereIn('code', ['F', 'S', 'N'])
        ->get();

    expect($templates)->toHaveCount(3)
        ->and($templates->pluck('code')->sort()->values()->all())->toBe(['F', 'N', 'S']);

    expect(ShiftStaffingRule::query()->whereIn('shift_template_id', $templates->pluck('id'))->count())->toBe(3)
        ->and(AbsenceRequest::query()
            ->whereIn('user_id', $employees->pluck('id'))
            ->where('status', AbsenceRequestStatus::Approved)
            ->count())->toBe(3)
        ->and(AbsenceRequest::query()
            ->where('user_id', $employees->firstWhere('email', 'demo.pflege.01@pflegedex.local')->id)
            ->whereDate('starts_on', '2027-01-08')
            ->whereDate('ends_on', '2027-01-12')
            ->exists())->toBeTrue()
        ->and(AbsenceRequest::query()
            ->where('user_id', $employees->firstWhere('email', 'demo.pflege.05@pflegedex.local')->id)
            ->whereDate('starts_on', '2027-01-20')
            ->whereDate('ends_on', '2027-01-22')
            ->exists())->toBeTrue();

    $roster = Roster::query()
        ->where('location_id', $location->id)
        ->where('year', 2027)
        ->where('month', 1)
        ->first();

    expect($roster)->not->toBeNull()
        ->and($roster->status)->toBe(RosterStatus::Draft)
        ->and(User::query()->where('email', 'demo.pdl.dienstplan@pflegedex.local')->count())->toBe(1)
        ->and(User::query()->where('email', 'like', 'demo.pflege.%@pflegedex.local')->count())->toBe(12)
        ->and(ShiftTemplate::query()->where('location_id', $location->id)->whereIn('code', ['F', 'S', 'N'])->count())->toBe(3)
        ->and(ShiftStaffingRule::query()->whereIn('shift_template_id', $templates->pluck('id'))->count())->toBe(3)
        ->and(Roster::query()->where('location_id', $location->id)->where('year', 2027)->where('month', 1)->count())->toBe(1);
});

it('rejects invalid demo months', function (): void {
    $this->artisan('pflegedex:seed-roster-demo', ['--month' => '2027-13'])
        ->assertFailed();
});
