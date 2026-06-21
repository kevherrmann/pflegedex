<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\ShoppingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('PDL');
    Role::findOrCreate('Pflegekraft');
    Role::findOrCreate('Putzkraft');
});

function shoppingUser(string $role): User
{
    $user = User::factory()->for(Location::factory())->create();
    $user->assignRole($role);

    return $user;
}

it('lets nursing staff add an item with their name attached', function (): void {
    $nurse = shoppingUser('Pflegekraft');

    $this->actingAs($nurse)
        ->post(route('shopping-list.store'), ['name' => 'Einmalhandschuhe', 'quantity' => 3])
        ->assertRedirect(route('shopping-list.index'));

    $item = ShoppingItem::query()->first();

    expect($item)->not->toBeNull()
        ->and($item->name)->toBe('Einmalhandschuhe')
        ->and($item->quantity)->toBe(3)
        ->and($item->created_by)->toBe($nurse->id);
});

it('lists items with the creator name', function (): void {
    $nurse = shoppingUser('Pflegekraft');
    ShoppingItem::query()->create([
        'name' => 'Brot',
        'quantity' => 2,
        'created_by' => $nurse->id,
    ]);

    $this->actingAs($nurse)
        ->get(route('shopping-list.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ShoppingList/Index')
            ->has('items', 1)
            ->where('items.0.name', 'Brot')
            ->where('items.0.quantity', 2)
            ->where('items.0.creatorName', $nurse->name));
});

it('lets staff delete an item once it is purchased', function (): void {
    $pdl = shoppingUser('PDL');
    $item = ShoppingItem::query()->create([
        'name' => 'Milch',
        'quantity' => 1,
        'created_by' => $pdl->id,
    ]);

    $this->actingAs($pdl)
        ->delete(route('shopping-list.destroy', $item))
        ->assertRedirect(route('shopping-list.index'));

    expect(ShoppingItem::query()->count())->toBe(0);
});

it('forbids non-nursing roles from the shopping list', function (): void {
    $cleaner = shoppingUser('Putzkraft');

    $this->actingAs($cleaner)->get(route('shopping-list.index'))->assertForbidden();
    $this->actingAs($cleaner)
        ->post(route('shopping-list.store'), ['name' => 'x', 'quantity' => 1])
        ->assertForbidden();
});

it('validates the item input', function (): void {
    $nurse = shoppingUser('Pflegekraft');

    $this->actingAs($nurse)
        ->post(route('shopping-list.store'), ['name' => '', 'quantity' => 0])
        ->assertSessionHasErrors(['name', 'quantity']);
});
