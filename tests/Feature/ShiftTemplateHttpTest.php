<?php

use App\Models\Location;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\DefaultShiftSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function createShiftTemplateHttpUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createShiftTemplateHttpShift(Location $location): ShiftTemplate
{
    app(DefaultShiftSetupService::class)->createForLocation($location);

    return ShiftTemplate::query()
        ->where('location_id', $location->id)
        ->where('code', 'early')
        ->firstOrFail();
}

it('shows the shift templates page to PDL users', function (): void {
    $pdl = createShiftTemplateHttpUser('PDL');

    $this->actingAs($pdl)
        ->get('/shift-templates')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('ShiftTemplates/Index')
        );
});

it('blocks non PDL users from viewing the shift templates page', function (): void {
    $user = createShiftTemplateHttpUser('Pflegekraft');

    $this->actingAs($user)
        ->get('/shift-templates')
        ->assertForbidden();
});

it('passes locations and shift templates to inertia', function (): void {
    $pdl = createShiftTemplateHttpUser('PDL');
    $location = Location::factory()->create([
        'name' => 'Wohnbereich A',
    ]);
    $shiftTemplate = createShiftTemplateHttpShift($location);

    $this->actingAs($pdl)
        ->get('/shift-templates')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('ShiftTemplates/Index')
                ->where('locations.0.id', $location->id)
                ->where('locations.0.name', 'Wohnbereich A')
                ->where('shiftTemplates.0.id', $shiftTemplate->id)
                ->where('shiftTemplates.0.locationId', $location->id)
                ->where('shiftTemplates.0.locationName', 'Wohnbereich A')
                ->where('shiftTemplates.0.name', 'Frühdienst')
                ->where('shiftTemplates.0.code', 'early')
                ->where('shiftTemplates.0.startsAt', '06:00')
                ->where('shiftTemplates.0.endsAt', '14:00')
                ->where('shiftTemplates.0.durationMinutes', 480)
                ->where('shiftTemplates.0.color', '#F59E0B')
                ->where('shiftTemplates.0.active', true)
                ->where('shiftTemplates.0.defaultStaffingRule.requiredTotalStaff', 5)
                ->where('shiftTemplates.0.defaultStaffingRule.requiredSpecialists', 1)
        );
});

it('lets PDL users update shift templates', function (): void {
    $pdl = createShiftTemplateHttpUser('PDL');
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateHttpShift($location);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch("/shift-templates/{$shiftTemplate->id}", [
            'name' => 'Frühdienst angepasst',
            'starts_at' => '05:30',
            'ends_at' => '13:30',
            'duration_minutes' => 480,
            'color' => '#111827',
            'active' => false,
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHas('status', 'shift-template-updated');

    $shiftTemplate->refresh();

    expect($shiftTemplate->name)->toBe('Frühdienst angepasst')
        ->and($shiftTemplate->code)->toBe('early')
        ->and($shiftTemplate->location_id)->toBe($location->id)
        ->and($shiftTemplate->starts_at)->toBe('05:30')
        ->and($shiftTemplate->ends_at)->toBe('13:30')
        ->and($shiftTemplate->duration_minutes)->toBe(480)
        ->and($shiftTemplate->color)->toBe('#111827')
        ->and($shiftTemplate->active)->toBeFalse();
});

it('lets PDL users update the default staffing rule', function (): void {
    $pdl = createShiftTemplateHttpUser('PDL');
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateHttpShift($location);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch("/shift-templates/{$shiftTemplate->id}/staffing-rule", [
            'required_total_staff' => 7,
            'required_specialists' => 2,
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHas('status', 'shift-staffing-rule-updated');

    $defaultRule = $shiftTemplate
        ->staffingRules()
        ->whereNull('weekday')
        ->firstOrFail();

    expect($defaultRule->required_total_staff)->toBe(7)
        ->and($defaultRule->required_specialists)->toBe(2);
});

it('lets PDL users create the default staffing rule when none exists', function (): void {
    $pdl = createShiftTemplateHttpUser('PDL');
    $location = Location::factory()->create();

    $shiftTemplate = ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'color' => '#F59E0B',
        'active' => true,
    ]);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch("/shift-templates/{$shiftTemplate->id}/staffing-rule", [
            'required_total_staff' => 5,
            'required_specialists' => 1,
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHas('status', 'shift-staffing-rule-updated');

    $defaultRule = ShiftStaffingRule::query()->firstOrFail();

    expect($defaultRule->location_id)->toBe($location->id)
        ->and($defaultRule->shift_template_id)->toBe($shiftTemplate->id)
        ->and($defaultRule->weekday)->toBeNull()
        ->and($defaultRule->required_total_staff)->toBe(5)
        ->and($defaultRule->required_specialists)->toBe(1);
});

it('rejects staffing rules where required specialists exceed total staff', function (): void {
    $pdl = createShiftTemplateHttpUser('PDL');
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateHttpShift($location);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch("/shift-templates/{$shiftTemplate->id}/staffing-rule", [
            'required_total_staff' => 1,
            'required_specialists' => 2,
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHasErrors('required_specialists');
});

it('blocks non PDL users from updating shift templates', function (): void {
    $user = createShiftTemplateHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateHttpShift($location);

    $this->actingAs($user)
        ->patch("/shift-templates/{$shiftTemplate->id}", [
            'name' => 'Nicht erlaubt',
            'starts_at' => '05:30',
            'ends_at' => '13:30',
            'duration_minutes' => 480,
            'color' => '#111827',
            'active' => true,
        ])
        ->assertForbidden();

    expect($shiftTemplate->refresh()->name)->toBe('Frühdienst');
});

it('blocks non PDL users from updating staffing rules', function (): void {
    $user = createShiftTemplateHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    $shiftTemplate = createShiftTemplateHttpShift($location);

    $this->actingAs($user)
        ->patch("/shift-templates/{$shiftTemplate->id}/staffing-rule", [
            'required_total_staff' => 7,
            'required_specialists' => 2,
        ])
        ->assertForbidden();

    $defaultRule = $shiftTemplate
        ->staffingRules()
        ->whereNull('weekday')
        ->firstOrFail();

    expect($defaultRule->required_total_staff)->toBe(5)
        ->and($defaultRule->required_specialists)->toBe(1);
});
