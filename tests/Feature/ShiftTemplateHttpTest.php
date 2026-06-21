<?php

use App\Models\Location;
use App\Models\ShiftCategoryStaffingRule;
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

function createShiftTemplateHttpUser(string $role, ?Location $location = null): User
{
    $factory = User::factory();

    if ($location !== null) {
        $factory = $factory->for($location);
    }

    $user = $factory->create();
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
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);

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
    $location = Location::factory()->create([
        'name' => 'Wohnbereich A',
    ]);
    $pdl = createShiftTemplateHttpUser('PDL', $location);
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
                ->where('shiftTemplates.0.category', 'early')
                ->where('categoryStaffing.0.category', 'early')
                ->where('categoryStaffing.0.requiredTotalStaff', 5)
                ->where('categoryStaffing.0.requiredSpecialists', 1)
        );
});

it('lets PDL users update shift templates', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
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

it('rejects a shift color already used by another template in the same location', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);

    ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => 'Frühdienst',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'color' => '#ABCDEF',
        'active' => true,
    ]);

    $second = ShiftTemplate::query()->create([
        'location_id' => $location->id,
        'name' => 'Spätdienst',
        'code' => 'late',
        'starts_at' => '14:00',
        'ends_at' => '22:00',
        'duration_minutes' => 480,
        'color' => '#111111',
        'active' => true,
    ]);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch("/shift-templates/{$second->id}", [
            'name' => 'Spätdienst',
            'starts_at' => '14:00',
            'ends_at' => '22:00',
            'duration_minutes' => 480,
            'color' => '#ABCDEF',
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHasErrors('color');

    expect($second->refresh()->color)->toBe('#111111');
});

it('allows the same shift color in a different location', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $locationB);

    ShiftTemplate::query()->create([
        'location_id' => $locationA->id,
        'name' => 'Frühdienst A',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'color' => '#ABCDEF',
        'active' => true,
    ]);

    $templateB = ShiftTemplate::query()->create([
        'location_id' => $locationB->id,
        'name' => 'Frühdienst B',
        'code' => 'early',
        'starts_at' => '06:00',
        'ends_at' => '14:00',
        'duration_minutes' => 480,
        'color' => '#222222',
        'active' => true,
    ]);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch("/shift-templates/{$templateB->id}", [
            'name' => 'Frühdienst B',
            'starts_at' => '06:00',
            'ends_at' => '14:00',
            'duration_minutes' => 480,
            'color' => '#ABCDEF',
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHas('status', 'shift-template-updated');

    expect($templateB->refresh()->color)->toBe('#ABCDEF');
});

it('lets PDL users update the category staffing', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    createShiftTemplateHttpShift($location);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch('/shift-templates/category-staffing', [
            'category' => 'early',
            'required_total_staff' => 7,
            'required_specialists' => 2,
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHas('status', 'category-staffing-updated');

    $rule = ShiftCategoryStaffingRule::query()
        ->where('location_id', $location->id)
        ->where('category', 'early')
        ->whereNull('weekday')
        ->firstOrFail();

    expect($rule->required_total_staff)->toBe(7)
        ->and($rule->required_specialists)->toBe(2);
});

it('lets PDL users create the category staffing when none exists', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch('/shift-templates/category-staffing', [
            'category' => 'early',
            'required_total_staff' => 5,
            'required_specialists' => 1,
        ])
        ->assertRedirect('/shift-templates')
        ->assertSessionHas('status', 'category-staffing-updated');

    $rule = ShiftCategoryStaffingRule::query()
        ->where('location_id', $location->id)
        ->where('category', 'early')
        ->firstOrFail();

    expect($rule->weekday)->toBeNull()
        ->and($rule->required_total_staff)->toBe(5)
        ->and($rule->required_specialists)->toBe(1);
});

it('rejects category staffing where required specialists exceed total staff', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->patch('/shift-templates/category-staffing', [
            'category' => 'early',
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

it('blocks non PDL users from updating category staffing', function (): void {
    $user = createShiftTemplateHttpUser('Pflegekraft');
    $location = Location::factory()->create();
    createShiftTemplateHttpShift($location);

    $this->actingAs($user)
        ->patch('/shift-templates/category-staffing', [
            'category' => 'early',
            'required_total_staff' => 7,
            'required_specialists' => 2,
        ])
        ->assertForbidden();
});

it('shows only shift templates from the PDL Wohnbereich', function (): void {
    $location = Location::factory()->create(['name' => 'Wohnbereich A']);
    $otherLocation = Location::factory()->create(['name' => 'Wohnbereich B']);
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    $shiftTemplate = createShiftTemplateHttpShift($location);
    createShiftTemplateHttpShift($otherLocation);

    $this->actingAs($pdl)
        ->get('/shift-templates')
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->component('ShiftTemplates/Index')
                ->has('locations', 1)
                ->where('locations.0.id', $location->id)
                ->has('shiftTemplates', 3)
                ->where('shiftTemplates.0.locationId', $location->id)
                ->where('shiftTemplates.1.locationId', $location->id)
                ->where('shiftTemplates.2.locationId', $location->id)
        );

    expect($shiftTemplate->location_id)->toBe($location->id);
});

it('blocks PDL users from updating shift templates from another Wohnbereich', function (): void {
    $location = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    $shiftTemplate = createShiftTemplateHttpShift($otherLocation);

    $this->actingAs($pdl)
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

it('lets PDL create an additional shift in a category with its own hours', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    app(DefaultShiftSetupService::class)->createForLocation($location);

    $this->actingAs($pdl)
        ->post('/shift-templates', [
            'category' => 'early',
            'name' => 'Früh 2',
            'starts_at' => '07:00',
            'ends_at' => '13:00',
            'color' => '#10B981',
        ])
        ->assertRedirect();

    $created = ShiftTemplate::query()
        ->where('location_id', $location->id)
        ->where('name', 'Früh 2')
        ->firstOrFail();

    // Besetzung wird pro Kategorie gepflegt – die neue Schicht teilt sich die Früh-Besetzung.
    expect($created->category)->toBe('early')
        ->and($created->duration_minutes)->toBe(360)
        ->and($created->code)->not->toBe('early');
});

it('computes overnight duration when creating a night shift', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    app(DefaultShiftSetupService::class)->createForLocation($location);

    $this->actingAs($pdl)
        ->post('/shift-templates', [
            'category' => 'night',
            'name' => 'Nacht 2',
            'starts_at' => '22:00',
            'ends_at' => '06:00',
            'required_total_staff' => 1,
            'required_specialists' => 1,
        ])
        ->assertRedirect();

    expect(ShiftTemplate::query()->where('name', 'Nacht 2')->value('duration_minutes'))->toBe(480);
});

it('forbids deleting the last active shift of a category', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    app(DefaultShiftSetupService::class)->createForLocation($location);

    $early = ShiftTemplate::query()
        ->where('location_id', $location->id)
        ->where('category', 'early')
        ->firstOrFail();

    $this->actingAs($pdl)
        ->from('/shift-templates')
        ->delete('/shift-templates/'.$early->id)
        ->assertRedirect('/shift-templates')
        ->assertSessionHasErrors(['shift_template']);

    expect(ShiftTemplate::query()->whereKey($early->id)->exists())->toBeTrue();
});

it('deletes an additional shift without planned shifts', function (): void {
    $location = Location::factory()->create();
    $pdl = createShiftTemplateHttpUser('PDL', $location);
    app(DefaultShiftSetupService::class)->createForLocation($location);

    $this->actingAs($pdl)->post('/shift-templates', [
        'category' => 'early',
        'name' => 'Früh 2',
        'starts_at' => '07:00',
        'ends_at' => '13:00',
        'required_total_staff' => 1,
        'required_specialists' => 0,
    ]);

    $extra = ShiftTemplate::query()->where('name', 'Früh 2')->firstOrFail();

    $this->actingAs($pdl)
        ->delete('/shift-templates/'.$extra->id)
        ->assertRedirect();

    expect(ShiftTemplate::query()->whereKey($extra->id)->exists())->toBeFalse();
});
