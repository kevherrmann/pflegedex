<?php

use App\Enums\EmploymentArea;
use App\Enums\RosterStatus;
use App\Enums\ShiftSource;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\RosterGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function rosterPreviewSetup(): array
{
    $location = Location::factory()->create();

    $pdl = User::factory()->for($location)->create();
    $pdl->assignRole('PDL');

    $employee = User::factory()->for($location)->create(['name' => 'Anna Pflege']);
    EmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employment_area' => EmploymentArea::Nursing,
        'is_nursing_specialist' => false,
        'weekly_hours' => 39.00,
        'regular_work_days_per_week' => 5,
        'annual_vacation_days' => 30,
        'vacation_days_carried_over' => 0,
        'overtime_minutes_balance' => 0,
        'can_work_early' => true,
        'can_work_late' => true,
        'can_work_night' => false,
        'active' => true,
    ]);

    $roster = Roster::query()->create([
        'location_id' => $location->id,
        'year' => 2027,
        'month' => 1,
        'status' => RosterStatus::Draft,
        'created_by' => $pdl->id,
    ]);

    $template = ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'color' => '#F59E0B',
        'active' => true,
    ]);

    ShiftStaffingRule::query()->create([
        'location_id' => $location->id,
        'shift_template_id' => $template->id,
        'weekday' => null,
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    return [$location, $pdl, $employee, $roster, $template];
}

it('previews a generation without persisting any shifts', function (): void {
    [, , , $roster] = rosterPreviewSetup();

    [$result, $previewShifts] = app(RosterGeneratorService::class)->preview($roster);

    expect(Shift::query()->count())->toBe(0)
        ->and($result->createdShifts)->toBeGreaterThan(0)
        ->and($result->plannedAssignments)->toHaveCount($result->createdShifts)
        ->and($result->employeeStats)->not->toBeEmpty()
        ->and($previewShifts)->toHaveCount($result->createdShifts);
});

it('keeps manual shifts fixed and counts existing auto shifts as replaced', function (): void {
    [, $pdl, $employee, $roster, $template] = rosterPreviewSetup();

    $date = CarbonImmutable::parse('2027-01-04');

    Shift::query()->create([
        'roster_id' => $roster->id,
        'location_id' => $roster->location_id,
        'user_id' => $employee->id,
        'shift_template_id' => $template->id,
        'date' => $date->toDateString(),
        'starts_at' => $date->setTime(6, 0),
        'ends_at' => $date->setTime(14, 0),
        'source' => ShiftSource::Auto,
    ]);

    [$result] = app(RosterGeneratorService::class)->preview($roster);

    // Der bestehende Auto-Dienst bleibt in der Datenbank, zählt aber als "würde ersetzt".
    expect(Shift::query()->count())->toBe(1)
        ->and($result->deletedAutoShifts)->toBe(1);
});

it('returns the preview with projected validation via http', function (): void {
    [, $pdl, , $roster] = rosterPreviewSetup();

    $response = $this->actingAs($pdl)
        ->from(route('rosters.show', $roster))
        ->post(route('rosters.generate-preview', $roster));

    $response->assertRedirect(route('rosters.show', $roster))
        ->assertSessionHas('status', 'roster-preview-generated');

    $preview = session('rosterPreviewResult');

    expect($preview['rosterId'])->toBe($roster->id)
        ->and($preview['createdShifts'])->toBeGreaterThan(0)
        ->and($preview['plannedAssignments'])->not->toBeEmpty()
        ->and($preview['employeeStats'])->not->toBeEmpty()
        ->and($preview['projectedValidation']['status'])->toBeIn(['green', 'yellow', 'red'])
        ->and(Shift::query()->count())->toBe(0);
});

it('rejects previews for published rosters', function (): void {
    [, $pdl, , $roster] = rosterPreviewSetup();

    $roster->forceFill(['status' => RosterStatus::Published, 'published_at' => now()])->save();

    $this->actingAs($pdl)
        ->from(route('rosters.show', $roster))
        ->post(route('rosters.generate-preview', $roster))
        ->assertRedirect(route('rosters.show', $roster))
        ->assertSessionHasErrors(['status']);
});

it('forbids previews for non-pdl users', function (): void {
    [$location, , , $roster] = rosterPreviewSetup();

    $nurse = User::factory()->for($location)->create();
    $nurse->assignRole('Pflegekraft');

    $this->actingAs($nurse)
        ->post(route('rosters.generate-preview', $roster))
        ->assertForbidden();
});
