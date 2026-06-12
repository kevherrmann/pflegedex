<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\User;
use App\Services\Absences\VacationBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createVacationBalanceUser(): User
{
    $location = Location::factory()->create();

    $user = User::factory()
        ->for($location)
        ->create();

    EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => EmploymentArea::Nursing,
        'annual_vacation_days' => 30,
        'vacation_days_carried_over' => 2,
        'active' => true,
    ]);

    return $user;
}

it('calculates the vacation balance from employee profile data', function (): void {
    $user = createVacationBalanceUser();

    $balance = app(VacationBalanceService::class)->forUser($user);

    expect($balance)->toBe([
        'annualVacationDays' => '30',
        'vacationDaysCarriedOver' => '2',
        'totalVacationDays' => '32',
        'approvedVacationDays' => '0',
        'requestedVacationDays' => '0',
        'remainingVacationDays' => '32',
        'availableVacationDays' => '32',
    ]);
});

it('subtracts approved vacation from remaining vacation days', function (): void {
    $user = createVacationBalanceUser();

    AbsenceRequest::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $user->id,
    ]);

    $balance = app(VacationBalanceService::class)->forUser($user);

    expect($balance['remainingVacationDays'])->toBe('27')
        ->and($balance['availableVacationDays'])->toBe('27')
        ->and($balance['approvedVacationDays'])->toBe('5');
});

it('subtracts requested vacation only from available vacation days', function (): void {
    $user = createVacationBalanceUser();

    AbsenceRequest::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-03',
        'days_count' => 3,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $user->id,
    ]);

    $balance = app(VacationBalanceService::class)->forUser($user);

    expect($balance['remainingVacationDays'])->toBe('32')
        ->and($balance['availableVacationDays'])->toBe('29')
        ->and($balance['requestedVacationDays'])->toBe('3');
});

it('ignores rejected vacation requests', function (): void {
    $user = createVacationBalanceUser();

    AbsenceRequest::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Rejected,
        'requested_by' => $user->id,
    ]);

    $balance = app(VacationBalanceService::class)->forUser($user);

    expect($balance['remainingVacationDays'])->toBe('32')
        ->and($balance['availableVacationDays'])->toBe('32')
        ->and($balance['approvedVacationDays'])->toBe('0')
        ->and($balance['requestedVacationDays'])->toBe('0');
});

it('formats whole days without decimals and half days with a comma', function (): void {
    $user = createVacationBalanceUser();

    AbsenceRequest::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-03',
        'days_count' => 2.5,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $user->id,
    ]);

    $balance = app(VacationBalanceService::class)->forUser($user);

    expect($balance['annualVacationDays'])->toBe('30')
        ->and($balance['approvedVacationDays'])->toBe('2,5')
        ->and($balance['remainingVacationDays'])->toBe('29,5')
        ->and($balance['availableVacationDays'])->toBe('29,5');
});

it('ignores overtime compensation requests for vacation balance', function (): void {
    $user = createVacationBalanceUser();

    AbsenceRequest::query()->create([
        'user_id' => $user->id,
        'location_id' => $user->location_id,
        'type' => AbsenceRequestType::OvertimeCompensation,
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-01',
        'days_count' => 1,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $user->id,
    ]);

    $balance = app(VacationBalanceService::class)->forUser($user);

    expect($balance['remainingVacationDays'])->toBe('32')
        ->and($balance['availableVacationDays'])->toBe('32')
        ->and($balance['approvedVacationDays'])->toBe('0')
        ->and($balance['requestedVacationDays'])->toBe('0');
});
