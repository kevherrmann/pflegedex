<?php

use App\Enums\EmploymentArea;
use App\Enums\ShiftWishKind;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\ShiftTemplate;
use App\Models\ShiftWish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
});

function shiftWishHttpUser(string $role, Location $location): User
{
    $user = User::factory()->for($location)->create();
    $user->assignRole($role);

    return $user;
}

function shiftWishHttpEmployee(Location $location, array $userAttributes = []): User
{
    $employee = User::factory()->for($location)->create($userAttributes);

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

    return $employee->refresh();
}

it('shows the shift wish overview to pdl users', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location, ['name' => 'Anna Pflege']);

    ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => '2027-01-05',
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->get(route('shift-wishes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ShiftWishes/Index')
            ->has('shiftWishes', 1)
            ->where('shiftWishes.0.employeeName', 'Anna Pflege')
            ->where('shiftWishes.0.kind', 'wish_free')
            ->has('staff')
            ->has('kinds', 2));
});

it('stores a wish-free day', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location);

    $this->actingAs($pdl)
        ->post(route('shift-wishes.store'), [
            'user_id' => $employee->id,
            'date' => '2027-01-05',
            'kind' => ShiftWishKind::WishFree->value,
            'note' => 'Familienfeier',
        ])
        ->assertRedirect(route('shift-wishes.index'));

    $wish = ShiftWish::query()->sole();

    expect($wish->user_id)->toBe($employee->id)
        ->and($wish->location_id)->toBe($location->id)
        ->and($wish->kind)->toBe(ShiftWishKind::WishFree)
        ->and($wish->shift_template_id)->toBeNull()
        ->and($wish->note)->toBe('Familienfeier');
});

it('stores a shift wish with the desired template', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location);

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

    $this->actingAs($pdl)
        ->post(route('shift-wishes.store'), [
            'user_id' => $employee->id,
            'date' => '2027-01-05',
            'kind' => ShiftWishKind::WishShift->value,
            'shift_template_id' => $template->id,
        ])
        ->assertRedirect(route('shift-wishes.index'));

    expect(ShiftWish::query()->sole()->shift_template_id)->toBe($template->id);
});

it('rejects a second wish for the same employee and date', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location);

    ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => '2027-01-05',
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->from(route('shift-wishes.index'))
        ->post(route('shift-wishes.store'), [
            'user_id' => $employee->id,
            'date' => '2027-01-05',
            'kind' => ShiftWishKind::WishShift->value,
        ])
        ->assertRedirect(route('shift-wishes.index'))
        ->assertSessionHasErrors(['date']);

    expect(ShiftWish::query()->count())->toBe(1);
});

it('deletes a shift wish', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location);

    $wish = ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => '2027-01-05',
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->delete(route('shift-wishes.destroy', $wish))
        ->assertRedirect(route('shift-wishes.index'));

    expect(ShiftWish::query()->count())->toBe(0);
});

it('forbids shift wish management for non-pdl users', function (): void {
    $location = Location::factory()->create();
    $nurse = shiftWishHttpUser('Pflegekraft', $location);
    $employee = shiftWishHttpEmployee($location);

    $this->actingAs($nurse)->get(route('shift-wishes.index'))->assertForbidden();

    $this->actingAs($nurse)
        ->post(route('shift-wishes.store'), [
            'user_id' => $employee->id,
            'date' => '2027-01-05',
            'kind' => ShiftWishKind::WishFree->value,
        ])
        ->assertForbidden();
});
