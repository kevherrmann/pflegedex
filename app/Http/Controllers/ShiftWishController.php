<?php

namespace App\Http\Controllers;

use App\Enums\ShiftWishKind;
use App\Models\ShiftWish;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Wunschfrei-Tage: Mitarbeitende tragen selbst ein, an welchen Tagen sie frei
 * haben möchten. Das ist ein weiches Planungsziel – die automatische Planung
 * berücksichtigt es, wenn die Besetzung es zulässt, und es verbraucht keinen
 * Urlaub. Die PDL hat eine Team-Übersicht und kann Einträge entfernen.
 */
class ShiftWishController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isManager = $user?->hasRole('PDL') ?? false;
        $canCreateOwn = (bool) ($user?->employeeProfile?->active ?? false);

        abort_unless($isManager || $canCreateOwn, HttpResponse::HTTP_FORBIDDEN);

        $mapWish = fn (ShiftWish $wish): array => [
            'id' => $wish->id,
            'date' => $wish->date->toDateString(),
            'kind' => $wish->kind->value,
            'kindLabel' => $wish->kind->label(),
            'employeeName' => $wish->user?->name,
            'locationName' => $wish->location?->name,
            'shiftTemplateName' => $wish->shiftTemplate?->name,
            'note' => $wish->note,
            'createdAt' => $wish->created_at?->toDateString(),
        ];

        // Eigene Wünsche – Basis für den Self-Service-Bereich.
        $myWishes = ShiftWish::query()
            ->where('user_id', $user->id)
            ->with('shiftTemplate')
            ->orderBy('date')
            ->get()
            ->map($mapWish)
            ->values();

        // Team-Übersicht nur für die PDL, beschränkt auf die eigenen Wohnbereiche.
        $teamWishes = null;

        if ($isManager) {
            $locationIds = $user->accessibleLocations()->pluck('id')->all();

            $teamWishes = ShiftWish::query()
                ->whereIn('location_id', $locationIds)
                ->with(['user', 'location', 'shiftTemplate'])
                ->orderByDesc('date')
                ->get()
                ->map($mapWish)
                ->values();
        }

        return Inertia::render('ShiftWishes/Index', [
            'myWishes' => $myWishes,
            'teamWishes' => $teamWishes,
            'canCreateOwn' => $canCreateOwn,
            'isManager' => $isManager,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Wunschfrei legt jede(r) nur für sich selbst an – und nur, wer geplant wird.
        abort_unless(
            (bool) ($user?->employeeProfile?->active ?? false),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'date' => [
                'required',
                'date',
                'after_or_equal:today',
                // whereDate statt Rule::unique, damit der Vergleich unabhängig vom
                // Speicherformat der Datumsspalte (PostgreSQL date vs. SQLite Text) greift.
                function (string $attribute, mixed $value, callable $fail) use ($user): void {
                    $exists = ShiftWish::query()
                        ->where('user_id', $user->id)
                        ->whereDate('date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('Für diesen Tag hast du bereits einen Wunschfrei-Eintrag.');
                    }
                },
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        ShiftWish::query()->create([
            'user_id' => $user->id,
            'location_id' => $user->location_id,
            'date' => $validated['date'],
            'kind' => ShiftWishKind::WishFree,
            'shift_template_id' => null,
            'note' => $validated['note'] ?? null,
            'created_by' => $user->id,
        ]);

        return redirect()
            ->route('shift-wishes.index')
            ->with('status', 'shift-wish-created');
    }

    public function destroy(Request $request, ShiftWish $shiftWish): RedirectResponse
    {
        $user = $request->user();

        // Eigene Wünsche darf man selbst löschen; die PDL zusätzlich die ihres Wohnbereichs.
        $ownsWish = $shiftWish->user_id === $user?->id;
        $managesLocation = ($user?->hasRole('PDL') ?? false)
            && $user->canAccessLocation($shiftWish->location_id);

        abort_unless($ownsWish || $managesLocation, HttpResponse::HTTP_FORBIDDEN);

        $shiftWish->delete();

        return redirect()
            ->route('shift-wishes.index')
            ->with('status', 'shift-wish-deleted');
    }
}
