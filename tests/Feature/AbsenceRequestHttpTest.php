<?php

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\RosterBlackoutDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('Admin');
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
    Role::findOrCreate('Putzkraft');
    Role::findOrCreate('Hausmeister');
});
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

it('records a request that falls into a blackout instead of blocking it through http', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    RosterBlackoutDay::query()->create([
        'location_id' => $employee->location_id,
        'date' => '2027-03-15',
        'reason' => 'Urlaubssperre',
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => false,
        'created_by' => $employee->id,
    ]);

    // Die weiche Sperre laesst den Antrag zu (PDL prueft individuell).
    $this->actingAs($employee)
        ->from('/dashboard')
        ->post('/absence-requests', [
            'type' => AbsenceRequestType::Vacation->value,
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-20',
        ])
        ->assertSessionHas('status', 'absence-request-created');

    expect(AbsenceRequest::query()->count())->toBe(1);
});

it('requires an override reason to approve a blacked-out request and stores it', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);
    $employee->forceFill(['location_id' => $location->id])->save();
    $employee->locations()->sync([$location->id]);

    RosterBlackoutDay::query()->create([
        'location_id' => $location->id,
        'date' => '2027-12-24',
        'reason' => 'Weihnachten',
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => false,
        'created_by' => $pdl->id,
    ]);

    $absenceRequest = AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2027-12-22',
        'ends_on' => '2027-12-27',
        'days_count' => 6,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
    ]);

    // Ohne Begruendung -> Fehler, Antrag bleibt offen.
    $this->actingAs($pdl)
        ->from('/absence-requests/manage')
        ->patch("/absence-requests/{$absenceRequest->id}/approve")
        ->assertRedirect('/absence-requests/manage')
        ->assertSessionHasErrors('override_reason');

    expect($absenceRequest->fresh()->status)->toBe(AbsenceRequestStatus::Requested);

    // Mit Begruendung -> genehmigt und dokumentiert.
    $this->actingAs($pdl)
        ->from('/absence-requests/manage')
        ->patch("/absence-requests/{$absenceRequest->id}/approve", [
            'override_reason' => 'Besetzung gesichert, dringender familiärer Grund.',
        ])
        ->assertRedirect('/absence-requests/manage')
        ->assertSessionHas('status', 'absence-request-approved');

    $absenceRequest->refresh();

    expect($absenceRequest->status)->toBe(AbsenceRequestStatus::Approved)
        ->and($absenceRequest->override_reason)->toBe('Besetzung gesichert, dringender familiärer Grund.');
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

    $employee->employeeProfile()->update([
        'annual_vacation_days' => 30,
        'vacation_days_carried_over' => 2,
    ]);

    AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-05-01',
        'ends_on' => '2026-05-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $employee->id,
    ]);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
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
                ->where('vacationBalance.annualVacationDays', '30')
                ->where('vacationBalance.vacationDaysCarriedOver', '2')
                ->where('vacationBalance.totalVacationDays', '32')
                ->where('vacationBalance.approvedVacationDays', '5')
                ->where('vacationBalance.requestedVacationDays', '5')
                ->where('vacationBalance.remainingVacationDays', '27')
                ->where('vacationBalance.availableVacationDays', '22')
        );
});

it('shows the absence request page to cleaning staff', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Cleaning);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
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

it('shares absence request permissions for nursing staff', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('auth.permissions.canViewAbsenceRequests', true)
                ->where('auth.permissions.canManageAbsenceRequests', false)
        );
});

it('shares absence request permissions for cleaning staff', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Cleaning);

    $this->actingAs($employee)
        ->get('/absence-requests')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('auth.permissions.canViewAbsenceRequests', true)
                ->where('auth.permissions.canManageAbsenceRequests', false)
        );
});
it('shares absence management permission for PDL users', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $pdl->assignRole('PDL');

    $this->actingAs($pdl)
        ->get('/staff')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('auth.permissions.canViewAbsenceRequests', false)
                ->where('auth.permissions.canManageAbsenceRequests', true)
        );
});

it('lets PDL users view the absence request management page', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);
    $employee->forceFill([
        'location_id' => $location->id,
    ])->save();

    $employee->locations()->sync([$location->id]);

    AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
        'note' => 'Sommerurlaub',
    ]);

    $this->actingAs($pdl)
        ->get('/absence-requests/manage')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('AbsenceRequests/Manage')
                // Die Timeline springt auf den Monat mit der Abwesenheit.
                ->where('month', '2026-06')
                ->where('monthLabel', 'Juni 2026')
                ->where('openRequestsCount', 1)
                ->has('days', 30)
                ->where('groups.0.locationName', $location->name)
                ->where('groups.0.employees.0.name', $employee->name)
                ->where('groups.0.employees.0.employmentAreaLabel', 'Pflege')
                ->where('groups.0.employees.0.absences.0.type', AbsenceRequestType::Vacation->value)
                ->where('groups.0.employees.0.absences.0.typeLabel', 'Urlaub')
                ->where('groups.0.employees.0.absences.0.startsOn', '2026-06-01')
                ->where('groups.0.employees.0.absences.0.endsOn', '2026-06-05')
                ->where('groups.0.employees.0.absences.0.startDay', 1)
                ->where('groups.0.employees.0.absences.0.endDay', 5)
                ->where('groups.0.employees.0.absences.0.daysCount', '5.00')
                ->where('groups.0.employees.0.absences.0.status', AbsenceRequestStatus::Requested->value)
                ->where('groups.0.employees.0.absences.0.statusLabel', 'Beantragt')
                ->where('groups.0.employees.0.absences.0.note', 'Sommerurlaub')
                ->where('groups.0.employees.0.absences.0.hitsBlackout', false)
        );
});

it('clips absences to the selected month and supports month navigation', function (): void {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);
    $employee->forceFill(['location_id' => $location->id])->save();
    $employee->locations()->sync([$location->id]);

    AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2027-01-28',
        'ends_on' => '2027-02-04',
        'days_count' => 8,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $pdl->id,
        'decided_by' => $pdl->id,
        'decided_at' => now(),
    ]);

    // Januar: Balken endet am Monatsende und laeuft rechts weiter.
    $this->actingAs($pdl)
        ->get('/absence-requests/manage?month=2027-01')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('month', '2027-01')
                ->has('days', 31)
                ->where('groups.0.employees.0.absences.0.startDay', 28)
                ->where('groups.0.employees.0.absences.0.endDay', 31)
                ->where('groups.0.employees.0.absences.0.continuesBefore', false)
                ->where('groups.0.employees.0.absences.0.continuesAfter', true)
        );

    // Februar: dieselbe Abwesenheit, jetzt links offen.
    $this->actingAs($pdl)
        ->get('/absence-requests/manage?month=2027-02')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->where('month', '2027-02')
                ->where('groups.0.employees.0.absences.0.startDay', 1)
                ->where('groups.0.employees.0.absences.0.endDay', 4)
                ->where('groups.0.employees.0.absences.0.continuesBefore', true)
                ->where('groups.0.employees.0.absences.0.continuesAfter', false)
        );
});

it('lets PDL users approve open absence requests', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $absenceRequest = AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-03',
        'days_count' => 3,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
    ]);

    $this->actingAs($pdl)
        ->from('/absence-requests/manage')
        ->patch("/absence-requests/{$absenceRequest->id}/approve")
        ->assertRedirect('/absence-requests/manage')
        ->assertSessionHas('status', 'absence-request-approved');

    $absenceRequest->refresh();

    expect($absenceRequest->status)->toBe(AbsenceRequestStatus::Approved)
        ->and($absenceRequest->decided_by)->toBe($pdl->id)
        ->and($absenceRequest->decided_at)->not->toBeNull()
        ->and($absenceRequest->rejection_reason)->toBeNull();
});

it('lets PDL users reject open absence requests with a reason', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $absenceRequest = AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
    ]);

    $this->actingAs($pdl)
        ->from('/absence-requests/manage')
        ->patch("/absence-requests/{$absenceRequest->id}/reject", [
            'rejection_reason' => 'Mindestbesetzung wäre gefährdet.',
        ])
        ->assertRedirect('/absence-requests/manage')
        ->assertSessionHas('status', 'absence-request-rejected');

    $absenceRequest->refresh();

    expect($absenceRequest->status)->toBe(AbsenceRequestStatus::Rejected)
        ->and($absenceRequest->decided_by)->toBe($pdl->id)
        ->and($absenceRequest->decided_at)->not->toBeNull()
        ->and($absenceRequest->rejection_reason)->toBe('Mindestbesetzung wäre gefährdet.');
});

it('requires a rejection reason when PDL users reject absence requests', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $absenceRequest = AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-09-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
    ]);

    $this->actingAs($pdl)
        ->from('/absence-requests/manage')
        ->patch("/absence-requests/{$absenceRequest->id}/reject", [
            'rejection_reason' => '',
        ])
        ->assertRedirect('/absence-requests/manage')
        ->assertSessionHasErrors('rejection_reason');

    expect($absenceRequest->refresh()->status)->toBe(AbsenceRequestStatus::Requested);
});

it('blocks non PDL users from managing absence requests', function (): void {
    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $absenceRequest = AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-10-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Requested,
        'requested_by' => $employee->id,
    ]);

    $this->actingAs($employee)
        ->get('/absence-requests/manage')
        ->assertForbidden();

    $this->actingAs($employee)
        ->patch("/absence-requests/{$absenceRequest->id}/approve")
        ->assertForbidden();

    expect($absenceRequest->refresh()->status)->toBe(AbsenceRequestStatus::Requested);
});

it('does not allow approving already decided absence requests', function (): void {
    $location = Location::factory()->create();

    $pdl = User::factory()
        ->for($location)
        ->create();

    $pdl->assignRole('PDL');

    $employee = createAbsenceHttpEmployeeWithProfile(EmploymentArea::Nursing);

    $absenceRequest = AbsenceRequest::query()->create([
        'user_id' => $employee->id,
        'location_id' => $employee->location_id,
        'type' => AbsenceRequestType::Vacation,
        'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-05',
        'days_count' => 5,
        'status' => AbsenceRequestStatus::Approved,
        'requested_by' => $employee->id,
        'decided_by' => $pdl->id,
        'decided_at' => now(),
    ]);

    $this->actingAs($pdl)
        ->from('/absence-requests/manage')
        ->patch("/absence-requests/{$absenceRequest->id}/approve")
        ->assertRedirect('/absence-requests/manage')
        ->assertSessionHasErrors('status');
});
