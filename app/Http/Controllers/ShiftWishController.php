<?php

namespace App\Http\Controllers;

use App\Enums\ShiftWishKind;
use App\Models\Location;
use App\Models\ShiftTemplate;
use App\Models\ShiftWish;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Wunschdienste und Wunschfrei-Tage, von der PDL gepflegt. Wünsche sind
 * weiche Planungsziele: Der Generator erfüllt sie, wenn die Besetzung es
 * zulässt, und meldet unerfüllbare Wünsche im Generierungsergebnis.
 */
class ShiftWishController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless(
            $user?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        // Standort-Scope: nur Wuensche/Stammdaten der eigenen Wohnbereiche.
        $locationIds = $user->accessibleLocations()->pluck('id')->all();

        return Inertia::render('ShiftWishes/Index', [
            'shiftWishes' => ShiftWish::query()
                ->whereIn('location_id', $locationIds)
                ->with(['user', 'location', 'shiftTemplate', 'createdBy'])
                ->orderByDesc('date')
                ->get()
                ->map(fn (ShiftWish $wish): array => [
                    'id' => $wish->id,
                    'employeeName' => $wish->user?->name,
                    'locationName' => $wish->location?->name,
                    'date' => $wish->date->toDateString(),
                    'kind' => $wish->kind->value,
                    'kindLabel' => $wish->kind->label(),
                    'shiftTemplateName' => $wish->shiftTemplate?->name,
                    'note' => $wish->note,
                    'createdByName' => $wish->createdBy?->name,
                    'createdAt' => $wish->created_at?->toDateString(),
                ])
                ->values(),
            'locations' => Location::query()
                ->whereIn('id', $locationIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                ])
                ->values(),
            'staff' => User::query()
                ->whereIn('location_id', $locationIds)
                ->whereHas('employeeProfile', fn ($query) => $query->where('active', true))
                ->with('employeeProfile')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'locationId' => $user->location_id,
                    'qualificationLabel' => $user->employeeProfile?->qualification_level?->label(),
                ])
                ->values(),
            'shiftTemplates' => ShiftTemplate::query()
                ->whereIn('location_id', $locationIds)
                ->where('active', true)
                ->orderBy('starts_at')
                ->get()
                ->map(fn (ShiftTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'locationId' => $template->location_id,
                ])
                ->values(),
            'kinds' => collect(ShiftWishKind::cases())
                ->map(fn (ShiftWishKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'date' => [
                'required',
                'date',
                // whereDate statt Rule::unique, damit der Vergleich unabhaengig vom
                // Speicherformat der Datumsspalte (PostgreSQL date vs. SQLite Text) greift.
                function (string $attribute, mixed $value, callable $fail) use ($request): void {
                    $exists = ShiftWish::query()
                        ->where('user_id', $request->input('user_id'))
                        ->whereDate('date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('Für diesen Mitarbeiter gibt es an diesem Datum bereits einen Wunsch.');
                    }
                },
            ],
            'kind' => ['required', Rule::enum(ShiftWishKind::class)],
            'shift_template_id' => ['nullable', 'uuid', 'exists:shift_templates,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = User::query()->findOrFail($validated['user_id']);

        // Standort-Scope: PDL darf nur fuer Mitarbeitende eigener Wohnbereiche Wuensche anlegen.
        abort_unless(
            $request->user()->canAccessLocation($employee->location_id),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $kind = ShiftWishKind::from($validated['kind']);

        ShiftWish::query()->create([
            'user_id' => $employee->id,
            'location_id' => $employee->location_id,
            'date' => $validated['date'],
            'kind' => $kind,
            'shift_template_id' => $kind === ShiftWishKind::WishShift
                ? ($validated['shift_template_id'] ?? null)
                : null,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('shift-wishes.index')
            ->with('status', 'shift-wish-created');
    }

    public function destroy(Request $request, ShiftWish $shiftWish): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        // Standort-Scope: nur Wuensche aus eigenen Wohnbereichen loeschen.
        abort_unless(
            $request->user()->canAccessLocation($shiftWish->location_id),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $shiftWish->delete();

        return redirect()
            ->route('shift-wishes.index')
            ->with('status', 'shift-wish-deleted');
    }
}
