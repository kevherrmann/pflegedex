<?php

use App\Enums\EmploymentArea;
use App\Enums\ShiftWishKind;
use App\Models\EmployeeProfile;
use App\Models\Location;
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

it('lets an employee see their own wishes and the create form', function (): void {
    $location = Location::factory()->create();
    $employee = shiftWishHttpEmployee($location, ['name' => 'Anna Pflege']);

    ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $employee->id,
    ]);

    $this->actingAs($employee)
        ->get(route('shift-wishes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ShiftWishes/Index')
            ->has('myWishes', 1)
            ->where('myWishes.0.kind', 'wish_free')
            ->where('canCreateOwn', true)
            ->where('isManager', false)
            ->where('teamWishes', null));
});

it('stores an own wish-free day for the logged-in employee', function (): void {
    $location = Location::factory()->create();
    $employee = shiftWishHttpEmployee($location);

    $this->actingAs($employee)
        ->post(route('shift-wishes.store'), [
            'date' => today()->addWeeks(2)->toDateString(),
            'note' => 'Familienfeier',
        ])
        ->assertRedirect(route('shift-wishes.index'));

    $wish = ShiftWish::query()->sole();

    expect($wish->user_id)->toBe($employee->id)
        ->and($wish->location_id)->toBe($location->id)
        ->and($wish->kind)->toBe(ShiftWishKind::WishFree)
        ->and($wish->shift_template_id)->toBeNull()
        ->and($wish->created_by)->toBe($employee->id)
        ->and($wish->note)->toBe('Familienfeier');
});

it('rejects a wish-free day in the past', function (): void {
    $location = Location::factory()->create();
    $employee = shiftWishHttpEmployee($location);

    $this->actingAs($employee)
        ->from(route('shift-wishes.index'))
        ->post(route('shift-wishes.store'), [
            'date' => today()->subDay()->toDateString(),
        ])
        ->assertRedirect(route('shift-wishes.index'))
        ->assertSessionHasErrors(['date']);

    expect(ShiftWish::query()->count())->toBe(0);
});

it('rejects a second wish for the same day', function (): void {
    $location = Location::factory()->create();
    $employee = shiftWishHttpEmployee($location);
    $date = today()->addWeeks(2)->toDateString();

    ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => $date,
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $employee->id,
    ]);

    $this->actingAs($employee)
        ->from(route('shift-wishes.index'))
        ->post(route('shift-wishes.store'), ['date' => $date])
        ->assertRedirect(route('shift-wishes.index'))
        ->assertSessionHasErrors(['date']);

    expect(ShiftWish::query()->count())->toBe(1);
});

it('lets an employee delete their own wish', function (): void {
    $location = Location::factory()->create();
    $employee = shiftWishHttpEmployee($location);

    $wish = ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $employee->id,
    ]);

    $this->actingAs($employee)
        ->delete(route('shift-wishes.destroy', $wish))
        ->assertRedirect(route('shift-wishes.index'));

    expect(ShiftWish::query()->count())->toBe(0);
});

it('forbids deleting a wish that belongs to someone else', function (): void {
    $location = Location::factory()->create();
    $owner = shiftWishHttpEmployee($location);
    $otherEmployee = shiftWishHttpEmployee($location);

    $wish = ShiftWish::query()->create([
        'user_id' => $owner->id,
        'location_id' => $location->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($otherEmployee)
        ->delete(route('shift-wishes.destroy', $wish))
        ->assertForbidden();

    expect(ShiftWish::query()->count())->toBe(1);
});

it('forbids the page for users without an employee profile or PDL role', function (): void {
    $location = Location::factory()->create();
    $nurse = shiftWishHttpUser('Pflegekraft', $location);

    $this->actingAs($nurse)->get(route('shift-wishes.index'))->assertForbidden();

    $this->actingAs($nurse)
        ->post(route('shift-wishes.store'), [
            'date' => today()->addWeeks(2)->toDateString(),
        ])
        ->assertForbidden();
});

it('shows the team overview with all location wishes to pdl', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location, ['name' => 'Anna Pflege']);

    ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $employee->id,
    ]);

    $this->actingAs($pdl)
        ->get(route('shift-wishes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ShiftWishes/Index')
            ->where('isManager', true)
            ->where('canCreateOwn', false)
            ->has('myWishes', 0)
            ->has('teamWishes', 1)
            ->where('teamWishes.0.employeeName', 'Anna Pflege'));
});

it('lets pdl delete a team wish in their location', function (): void {
    $location = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $location);
    $employee = shiftWishHttpEmployee($location);

    $wish = ShiftWish::query()->create([
        'user_id' => $employee->id,
        'location_id' => $location->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $employee->id,
    ]);

    $this->actingAs($pdl)
        ->delete(route('shift-wishes.destroy', $wish))
        ->assertRedirect(route('shift-wishes.index'));

    expect(ShiftWish::query()->count())->toBe(0);
});

it('forbids pdl deleting a wish from another location', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $ownLocation);
    $otherEmployee = shiftWishHttpEmployee($otherLocation);

    $wish = ShiftWish::query()->create([
        'user_id' => $otherEmployee->id,
        'location_id' => $otherLocation->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $otherEmployee->id,
    ]);

    $this->actingAs($pdl)
        ->delete(route('shift-wishes.destroy', $wish))
        ->assertForbidden();

    expect(ShiftWish::query()->count())->toBe(1);
});

it('hides team wishes from other locations for pdl', function (): void {
    $ownLocation = Location::factory()->create();
    $otherLocation = Location::factory()->create();
    $pdl = shiftWishHttpUser('PDL', $ownLocation);
    $otherEmployee = shiftWishHttpEmployee($otherLocation, ['name' => 'Fremd Mitarbeiter']);

    ShiftWish::query()->create([
        'user_id' => $otherEmployee->id,
        'location_id' => $otherLocation->id,
        'date' => today()->addWeeks(2)->toDateString(),
        'kind' => ShiftWishKind::WishFree,
        'created_by' => $otherEmployee->id,
    ]);

    $this->actingAs($pdl)
        ->get(route('shift-wishes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ShiftWishes/Index')
            ->has('teamWishes', 0));
});
