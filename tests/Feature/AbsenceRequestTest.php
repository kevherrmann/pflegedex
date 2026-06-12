<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\BlackoutScope;
use App\Enums\EmploymentArea;
use App\Enums\QualificationLevel;
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

it('records a vacation request inside a blackout but flags it instead of blocking', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2026-12-24',
        createdBy: $employee,
        blocksVacation: true,
        blocksOvertimeCompensation: false,
    );

    $service = app(AbsenceRequestService::class);

    $request = $service->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    expect($request)->toBeInstanceOf(AbsenceRequest::class)
        ->and(AbsenceRequest::query()->count())->toBe(1)
        ->and($service->hitsBlackout($request))->toBeTrue();
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

it('records an overtime compensation request inside a blackout but flags it', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2027-01-02',
        createdBy: $employee,
        blocksVacation: false,
        blocksOvertimeCompensation: true,
    );

    $service = app(AbsenceRequestService::class);

    $request = $service->request($employee, $employee, [
        'type' => AbsenceRequestType::OvertimeCompensation,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-03',
    ]);

    expect($request)->toBeInstanceOf(AbsenceRequest::class)
        ->and(AbsenceRequest::query()->count())->toBe(1)
        ->and($service->hitsBlackout($request))->toBeTrue();
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

it('applies a qualification-scoped blackout only to matching qualifications', function (): void {
    $specialist = createEmployeeWithProfile(EmploymentArea::Nursing);
    $specialist->employeeProfile->update(['qualification_level' => QualificationLevel::Specialist]);

    $location = Location::query()->findOrFail($specialist->location_id);

    $aide = User::factory()->for($location)->create();
    EmployeeProfile::query()->create([
        'user_id' => $aide->id,
        'employment_area' => EmploymentArea::Nursing,
        'qualification_level' => QualificationLevel::Aide,
        'active' => true,
    ]);

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-12-24',
        'scope' => BlackoutScope::Qualification,
        'qualification_levels' => [QualificationLevel::Specialist->value],
        'created_by' => $specialist->id,
    ]);

    $service = app(AbsenceRequestService::class);

    // Fachkraft -> Antrag faellt in die Sperre (markiert).
    $specialistRequest = $service->request($specialist, $specialist, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    // Hilfskraft im selben Wohnbereich -> nicht betroffen.
    $aideRequest = $service->request($aide, $aide, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    expect($service->hitsBlackout($specialistRequest))->toBeTrue()
        ->and($service->hitsBlackout($aideRequest))->toBeFalse();
});

it('applies an employee-scoped blackout only to the named employees', function (): void {
    $blocked = createEmployeeWithProfile(EmploymentArea::Nursing);
    $location = Location::query()->findOrFail($blocked->location_id);

    $other = User::factory()->for($location)->create();
    EmployeeProfile::query()->create([
        'user_id' => $other->id,
        'employment_area' => EmploymentArea::Nursing,
        'active' => true,
    ]);

    $blackoutDay = RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2026-12-24',
        'scope' => BlackoutScope::Employees,
        'created_by' => $blocked->id,
    ]);
    $blackoutDay->employees()->sync([$blocked->id]);

    $service = app(AbsenceRequestService::class);

    // Benannter Mitarbeiter -> Antrag faellt in die Sperre (markiert).
    $blockedRequest = $service->request($blocked, $blocked, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    // Anderer Mitarbeiter im selben Wohnbereich -> nicht betroffen.
    $otherRequest = $service->request($other, $other, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    expect($service->hitsBlackout($blockedRequest))->toBeTrue()
        ->and($service->hitsBlackout($otherRequest))->toBeFalse();
});

it('requires a documented reason to approve a request that falls into a blackout', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);
    $pdl = User::factory()->for(Location::query()->findOrFail($employee->location_id))->create();

    createRosterBlackoutDayForAbsenceRequestTest(
        locationId: $employee->location_id,
        date: '2026-12-24',
        createdBy: $pdl,
        blocksVacation: true,
        blocksOvertimeCompensation: false,
    );

    $service = app(AbsenceRequestService::class);

    $request = $service->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    // Ohne Begruendung -> Ausnahme nicht moeglich.
    expect(fn () => $service->approve($request, $pdl))->toThrow(ValidationException::class);
    expect($request->fresh()->status)->toBe(AbsenceRequestStatus::Requested);

    // Mit Begruendung -> genehmigt und dokumentiert.
    $approved = $service->approve($request, $pdl, 'Dringender familiärer Grund, Besetzung gesichert.');

    expect($approved->status)->toBe(AbsenceRequestStatus::Approved)
        ->and($approved->override_reason)->toBe('Dringender familiärer Grund, Besetzung gesichert.');
});

it('does not require a reason to approve a request outside any blackout', function (): void {
    $employee = createEmployeeWithProfile(EmploymentArea::Nursing);
    $pdl = User::factory()->for(Location::query()->findOrFail($employee->location_id))->create();

    $service = app(AbsenceRequestService::class);

    $request = $service->request($employee, $employee, [
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-12-20',
        'ends_on' => '2026-12-27',
    ]);

    $approved = $service->approve($request, $pdl);

    expect($approved->status)->toBe(AbsenceRequestStatus::Approved)
        ->and($approved->override_reason)->toBeNull();
});
