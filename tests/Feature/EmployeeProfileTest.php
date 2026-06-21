<?php

use App\Enums\EmploymentArea;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows every user to have one employee profile', function (): void {
    $user = User::factory()->create();

    $profile = EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => EmploymentArea::Nursing,
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
    ]);

    expect($profile->id)->not->toBeNull()
        ->and($user->refresh()->employeeProfile)->not->toBeNull()
        ->and($user->employeeProfile->id)->toBe($profile->id)
        ->and($user->employeeProfile->employment_area)->toBe(EmploymentArea::Nursing)
        ->and($user->employeeProfile->is_nursing_specialist)->toBeTrue()
        ->and($user->employeeProfile->weekly_hours)->toBe('30.00')
        ->and($user->employeeProfile->regular_work_days_per_week)->toBe(4)
        ->and($user->employeeProfile->annual_vacation_days)->toBe(28)
        ->and($user->employeeProfile->vacation_days_carried_over)->toBe(2)
        ->and($user->employeeProfile->overtime_minutes_balance)->toBe(120)
        ->and($user->employeeProfile->can_work_early)->toBeTrue()
        ->and($user->employeeProfile->can_work_late)->toBeTrue()
        ->and($user->employeeProfile->can_work_night)->toBeFalse();
});

it('maps all required employment areas', function (EmploymentArea $area): void {
    $user = User::factory()->create();

    EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => $area,
    ]);

    expect($user->refresh()->employeeProfile->employment_area)->toBe($area);
})->with([
    EmploymentArea::Nursing,
    EmploymentArea::Cleaning,
    EmploymentArea::Caretaker,
    EmploymentArea::Pdl,
]);

it('allows nursing staff to be marked as nursing specialists', function (): void {
    $user = User::factory()->create();

    $profile = EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => EmploymentArea::Nursing,
        'is_nursing_specialist' => true,
    ]);

    expect($profile->is_nursing_specialist)->toBeTrue()
        ->and($profile->isNursing())->toBeTrue()
        ->and($profile->isCaregiverEligibleForRoster())->toBeTrue();
});

it('allows nursing and cleaning staff to request absences', function (EmploymentArea $area): void {
    $user = User::factory()->create();

    EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => $area,
        'active' => true,
    ]);

    expect($user->refresh()->canRequestAbsence())->toBeTrue();
})->with([
    EmploymentArea::Nursing,
    EmploymentArea::Cleaning,
]);

it('excludes caretakers from absence requests', function (): void {
    $user = User::factory()->create();

    EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => EmploymentArea::Caretaker,
        'active' => true,
    ]);

    expect($user->refresh()->canRequestAbsence())->toBeFalse();
});

it('prevents more than one employee profile per user', function (): void {
    $user = User::factory()->create();

    EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => EmploymentArea::Nursing,
    ]);

    expect(fn () => EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => EmploymentArea::Cleaning,
    ]))->toThrow(QueryException::class);
});
