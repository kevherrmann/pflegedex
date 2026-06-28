<?php

namespace App\Http\Controllers;

use App\Models\ShoppingItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ShoppingListController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeAccess($request);

        $items = ShoppingItem::query()
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ShoppingItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'creatorName' => $item->creator?->name,
                'createdAt' => $item->created_at?->format('d.m.Y H:i'),
            ])
            ->values()
            ->all();

        return Inertia::render('ShoppingList/Index', [
            'items' => $items,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);

        ShoppingItem::query()->create([
            'name' => $validated['name'],
            'quantity' => $validated['quantity'],
            'created_by' => $request->user()->id,
        ]);

        return to_route('shopping-list.index');
    }

    public function destroy(Request $request, ShoppingItem $shoppingItem): RedirectResponse
    {
        $this->authorizeAccess($request);

        $shoppingItem->delete();

        return to_route('shopping-list.index');
    }

    private function authorizeAccess(Request $request): void
    {
        // PDL, Fachkräfte und Hilfskräfte (= Rolle Pflegekraft) dürfen die Liste sehen und bearbeiten.
        abort_unless(
            $request->user()?->hasAnyRole(['PDL', 'Pflegekraft']),
            HttpResponse::HTTP_FORBIDDEN,
        );
    }
}
