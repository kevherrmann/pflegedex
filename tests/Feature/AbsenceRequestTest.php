<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\RosterBlackoutDay;
use App\Models\User;
use App\Services\Absences\AbsenceRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createEmployeeWithProfile(EmploymentArea $area): User
{
    $location = Location::factory()->create();

    $user = User::factory()
        ->for($location)
        ->create();

    EmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employment_area' => $area,
        'active' => true,
    ]);

    return $user;
}

function createRosterBlackoutDayForAbsenceRequestTest(
    string $locationId,
    string $date,
    User $createdBy,
    bool $blocksVacation = true,
    bool $blocksOvertimeCompensation = true,
): RosterBlackoutDay {
    return RosterBlackoutDay::query()->create([
        'location_id' => $locationId,
        'date' => $date,
        'reason' => 'Urlaubssperre',
        'blocks_vacation' => $blocksVacation,
        'blocks_overtime_compensation' => $blocksOvertimeCompensation,
        'created_by' => $createdBy->id,
    ]);
}

it('allows nursing staff to request vacation', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    $request = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-05',
        'days_count' => 5,
        'note' => 'Sommerurlaub',
    ]);

    expect($request)->toBeInstanceOf(AbsenceRequest::class)
        ->and($request->user_id)->toBe($employee->id)
        ->and($request->requested_by)->toBe($employee->id)
        ->and($request->type)->toBe(AbsenceRequestType::Vacation)
        ->and($request->status)->toBe(AbsenceRequestStatus::Requested)
        ->and($request->starts_on->toDateString())->toBe('2026-06-01')
        ->and($request->ends_on->toDateString())->toBe('2026-06-05')
        ->and($request->days_count)->toBe('5.00')
        ->and($request->note)->toBe('Sommerurlaub');
});

it('allows cleaning staff to request vacation', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Cleaning);

    $request = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-03',
    ]);

    expect($request->user_id)->toBe($employee->id)
        ->and($request->type)->toBe(AbsenceRequestType::Vacation)
        ->and($request->status)->toBe(AbsenceRequestStatus::Requested)
        ->and($request->days_count)->toBe('3.00');
});

it('blocks caretakers from requesting vacation through this module', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Caretaker);

    app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-05',
    ]);
})->throws(ValidationException::class);

it('blocks requests where end date is before start date', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-08-10',
        'ends_on' => '2026-08-05',
    ]);
})->throws(ValidationException::class);

it('blocks overlapping active absence requests', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-09-10',
        'ends_on' => '2026-09-15',
    ]);

    app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-09-14',
        'ends_on' => '2026-09-20',
    ]);
})->throws(ValidationException::class);

it('allows a new request when the overlapping old request was rejected', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    $oldRequest = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-10-15',
    ]);

    $oldRequest->update([
        'status' => AbsenceRequestStatus::Rejected,
    ]);

    $newRequest = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-10-12',
        'ends_on' => '2026-10-14',
    ]);

    expect($newRequest->status)->toBe(AbsenceRequestStatus::Requested)
        ->and($newRequest->starts_on->toDateString())->toBe('2026-10-12')
        ->and($newRequest->ends_on->toDateString())->toBe('2026-10-14');
});

it('stores the location of the employee by default', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    $request = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-01',
    ]);

    expect($request->location_id)->toBe($employee->location_id);
});

it('blocks vacation when a matching roster blackout day exists in the requested period', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2026-12-24',
        createdBy: $employee,
        blocksVacation: true,
        blocksOvertimeCompensation: false,
    );

    expect(fn () => app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]))->toThrow(ValidationException::class);

    expect(AbsenceRequest::query()->count())->toBe(0);
});

it('allows vacation when only non-vacation roster blackout days exist in the requested period', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2026-12-24',
        createdBy: $employee,
        blocksVacation: false,
        blocksOvertimeCompensation: true,
    );

    $request = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    expect($request)->toBeInstanceOf(AbsenceRequest::class)
        ->and($request->type)->toBe(AbsenceRequestType::Vacation);
});

it('blocks overtime compensation when a matching roster blackout day exists in the requested period', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2027-01-02',
        createdBy: $employee,
        blocksVacation: false,
        blocksOvertimeCompensation: true,
    );

    expect(fn () => app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::OvertimeCompensation,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-03',
    ]))->toThrow(ValidationException::class);

    expect(AbsenceRequest::query()->count())->toBe(0);
});

it('allows overtime compensation when only vacation roster blackout days exist in the requested period', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2027-01-02',
        createdBy: $employee,
        blocksVacation: true,
        blocksOvertimeCompensation: false,
    );

    $request = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::OvertimeCompensation,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-03',
    ]);

    expect($request)->toBeInstanceOf(AbsenceRequest::class)
        ->and($request->type)->toBe(AbsenceRequestType::OvertimeCompensation);
});

it('does not block absence requests with roster blackout days from other locations', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);
    $otherLocation = Location::factory()->create();

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $otherLocation->id,
        date: '2027-02-10',
        createdBy: $employee,
        blocksVacation: true,
        blocksOvertimeCompensation: true,
    );

    $request = app(AbsenceRequestService::class)->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2027-02-09',
        'ends_on' => '2027-02-11',
    ]);

    expect($request)->toBeInstanceOf(AbsenceRequest::class)
        ->and($request->location_id)->toBe($employee->location_id);
});
