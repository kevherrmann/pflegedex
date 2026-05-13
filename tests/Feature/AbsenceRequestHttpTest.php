<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function createAbsenceHttpEmployeeWithProfile(EmploymentArea $area): User
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

it('requires authentication to create an absence request', function (): void {
    $this->post('/absence-requests', [
        'type' => AbsenceRequestType::Vacation->value,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-05',
    ])->assertRedirect('/login');
});

it('lets nursing staff create a vacation request through http', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-05',
            'days_count' => 5,
            'note' => 'Sommerurlaub',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'absence-request-created');

    $request = AbsenceRequest::query()->firstOrFail();

    expect($request->user_id)->toBe($employee->id)
        ->and($request->requested_by)->toBe($employee->id)
        ->and($request->location_id)->toBe($employee->location_id)
        ->and($request->type)->toBe(AbsenceRequestType::Vacation)
        ->and($request->status)->toBe(AbsenceRequestStatus::Requested)
        ->and($request->starts_on->toDateString())->toBe('2026-06-01')
        ->and($request->ends_on->toDateString())->toBe('2026-06-05')
        ->and($request->days_count)->toBe('5.00')
        ->and($request->note)->toBe('Sommerurlaub');
});

it('lets cleaning staff create a vacation request through http', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Cleaning);

    $this->actingAs($employee)
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-03',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'absence-request-created');

    $request = AbsenceRequest::query()->firstOrFail();

    expect($request->user_id)->toBe($employee->id)
        ->and($request->type)->toBe(AbsenceRequestType::Vacation)
        ->and($request->days_count)->toBe('3.00');
});

it('lets nursing staff request overtime compensation through http', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::OvertimeCompensation->value,
            'starts_on' => '2026-07-10',
            'ends_on' => '2026-07-10',
            'days_count' => 1,
            'note' => 'Überstundenfrei',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'absence-request-created');

    $request = AbsenceRequest::query()->firstOrFail();

    expect($request->type)->toBe(AbsenceRequestType::OvertimeCompensation)
        ->and($request->days_count)->toBe('1.00')
        ->and($request->note)->toBe('Überstundenfrei');
});

it('blocks caretakers from creating absence requests through http', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Caretaker);

    $this->actingAs($employee)
        ->from('/dashboard')
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-05',
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('user_id');

    expect(AbsenceRequest::query()->count())->toBe(0);
});

it('validates required absence request fields', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->from('/dashboard')
        ->post('/absence-requests', [])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors([
            'type',
            'starts_on',
            'ends_on',
        ]);

    expect(AbsenceRequest::query()->count())->toBe(0);
});

it('validates the absence request type', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->from('/dashboard')
        ->post('/absence-requests', [
            'type' => 'sick',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-02',
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('type');

    expect(AbsenceRequest::query()->count())->toBe(0);
});

it('blocks http requests where end date is before start date', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->from('/dashboard')
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2026-10-10',
            'ends_on' => '2026-10-05',
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('ends_on');

    expect(AbsenceRequest::query()->count())->toBe(0);
});

it('blocks overlapping active absence requests through http', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2026-11-10',
            'ends_on' => '2026-11-15',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'absence-request-created');

    $this->actingAs($employee)
        ->from('/dashboard')
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2026-11-14',
            'ends_on' => '2026-11-20',
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('starts_on');

    expect(AbsenceRequest::query()->count())->toBe(1);
});
it('shows the absence request page to nursing staff', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
        'note' => 'Sommerurlaub',
    ]);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertOk()
        ->assertInertia(
            fn(Assert $page) => $page
                ->component('AbsenceRequests/Index')
                ->where('canRequestAbsence', true)
                ->where('absenceTypes.0.value', AbsenceRequestType::Vacation->value)
                ->where('absenceTypes.0.label', 'Urlaub')
                ->where('absenceTypes.1.value', AbsenceRequestType::OvertimeCompensation->value)
                ->where('absenceTypes.1.label', 'Überstundenfrei')
                ->where('absenceRequests.0.type', AbsenceRequestType::Vacation->value)
                ->where('absenceRequests.0.typeLabel', 'Urlaub')
                ->where('absenceRequests.0.startsOn', '2026-06-01')
                ->where('absenceRequests.0.endsOn', '2026-06-05')
                ->where('absenceRequests.0.daysCount', '5.00')
                ->where('absenceRequests.0.status', AbsenceRequestStatus::Requested->value)
                ->where('absenceRequests.0.statusLabel', 'Beantragt')
                ->where('absenceRequests.0.note', 'Sommerurlaub')
        );
});

it('shows the absence request page to cleaning staff', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Cleaning);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertOk()
        ->assertInertia(
            fn(Assert $page) => $page
                ->component('AbsenceRequests/Index')
                ->where('canRequestAbsence', true)
                ->has('absenceRequests', 0)
        );
});

it('blocks caretakers from viewing the absence request page', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Caretaker);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertForbidden();
});