<?php

namespace App\Http\Controllers;

use App\Enums\ShiftCategory;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftCategoryStaffingRule;
use App\Models\ShiftTemplate;
use App\Services\Rosters\PdlRosterAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShiftTemplateController extends Controller
{
    public function index(Request $request, PdlRosterAccess $pdlRosterAccess): Response
    {
        $locationId = $pdlRosterAccess->ensurePdlHasLocation($request);

        $categoryRules = ShiftCategoryStaffingRule::query()
            ->where('location_id', $locationId)
            ->whereNull('weekday')
            ->get()
            ->keyBy('category');

        // Besetzung gilt pro Kategorie (Früh/Spät/Nacht) – eine Zeile je Kategorie.
        $categoryStaffing = collect(ShiftCategory::cases())
            ->map(function (ShiftCategory $category) use ($categoryRules): array {
                $rule = $categoryRules->get($category->value);

                return [
                    'category' => $category->value,
                    'label' => $category->label(),
                    'requiredTotalStaff' => $rule?->required_total_staff,
                    'targetTotalStaff' => $rule?->target_total_staff,
                    'requiredSpecialists' => $rule?->required_specialists,
                ];
            })
            ->values();

        return Inertia::render('ShiftTemplates/Index', [
            'locations' => Location::query()
                ->whereKey($locationId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                ])
                ->values(),
            'categoryStaffing' => $categoryStaffing,
            'shiftTemplates' => ShiftTemplate::query()
                ->with('location')
                ->where('location_id', $locationId)
                ->orderBy('location_id')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (ShiftTemplate $shiftTemplate): array => [
                    'id' => $shiftTemplate->id,
                    'locationId' => $shiftTemplate->location_id,
                    'locationName' => $shiftTemplate->location?->name,
                    'name' => $shiftTemplate->name,
                    'code' => $shiftTemplate->code,
                    'category' => $shiftTemplate->category,
                    'startsAt' => $shiftTemplate->starts_at,
                    'endsAt' => $shiftTemplate->ends_at,
                    'durationMinutes' => $shiftTemplate->duration_minutes,
                    'color' => $shiftTemplate->color,
                    'active' => $shiftTemplate->active,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $locationId = $pdlRosterAccess->ensurePdlHasLocation($request);

        $validated = $request->validate([
            'category' => ['required', Rule::enum(ShiftCategory::class)],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'color' => [
                'nullable',
                'string',
                'max:20',
                // Schichtfarben muessen innerhalb eines Wohnbereichs eindeutig sein.
                function (string $attribute, mixed $value, \Closure $fail) use ($locationId): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $taken = ShiftTemplate::query()
                        ->where('location_id', $locationId)
                        ->whereRaw('LOWER(color) = ?', [strtolower($value)])
                        ->exists();

                    if ($taken) {
                        $fail('Diese Farbe ist in diesem Wohnbereich bereits vergeben.');
                    }
                },
            ],
        ]);

        ShiftTemplate::query()->create([
            'location_id' => $locationId,
            'name' => $validated['name'],
            'code' => $this->uniqueCodeFor($locationId, $validated['category']),
            'category' => $validated['category'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'duration_minutes' => $this->durationMinutes($validated['starts_at'], $validated['ends_at']),
            'color' => $validated['color'] ?? null,
            'active' => true,
        ]);

        return back()->with('status', 'shift-template-created');
    }

    public function destroy(Request $request, ShiftTemplate $shiftTemplate, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $shiftTemplate->location_id);

        // Pflicht: je Kategorie (Früh/Spät/Nacht) muss mindestens eine aktive Schicht bleiben.
        $otherActiveInCategory = ShiftTemplate::query()
            ->where('location_id', $shiftTemplate->location_id)
            ->where('category', $shiftTemplate->category)
            ->where('active', true)
            ->whereKeyNot($shiftTemplate->id)
            ->exists();

        if ($shiftTemplate->active && ! $otherActiveInCategory) {
            return back()->withErrors([
                'shift_template' => 'Die letzte aktive Schicht einer Kategorie (Früh/Spät/Nacht) kann nicht gelöscht werden.',
            ]);
        }

        // Schutz der Historie: Schichten mit bereits geplanten Diensten nicht löschen
        // (würde per Cascade die Dienste mitlöschen) — stattdessen deaktivieren.
        if (Shift::query()->where('shift_template_id', $shiftTemplate->id)->exists()) {
            return back()->withErrors([
                'shift_template' => 'Für diese Schicht gibt es bereits geplante Dienste. Deaktiviere sie stattdessen, um die Dienste zu erhalten.',
            ]);
        }

        $shiftTemplate->staffingRules()->delete();
        $shiftTemplate->delete();

        return back()->with('status', 'shift-template-deleted');
    }

    public function update(Request $request, ShiftTemplate $shiftTemplate, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $shiftTemplate->location_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'color' => [
                'nullable',
                'string',
                'max:20',
                // Schichtfarben muessen innerhalb eines Wohnbereichs eindeutig sein.
                function (string $attribute, mixed $value, \Closure $fail) use ($shiftTemplate): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $taken = ShiftTemplate::query()
                        ->where('location_id', $shiftTemplate->location_id)
                        ->whereKeyNot($shiftTemplate->id)
                        ->whereRaw('LOWER(color) = ?', [strtolower($value)])
                        ->exists();

                    if ($taken) {
                        $fail('Diese Farbe ist in diesem Wohnbereich bereits vergeben.');
                    }
                },
            ],
            'active' => ['sometimes', 'boolean'],
        ]);

        $shiftTemplate->update([
            'name' => $validated['name'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'duration_minutes' => $validated['duration_minutes'],
            'color' => $validated['color'] ?? null,
            'active' => $request->has('active')
                ? $request->boolean('active')
                : $shiftTemplate->active,
        ]);

        return back()->with('status', 'shift-template-updated');
    }

    public function updateCategoryStaffing(Request $request, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $locationId = $pdlRosterAccess->ensurePdlHasLocation($request);

        $validated = $request->validate([
            'category' => ['required', Rule::enum(ShiftCategory::class)],
            'required_total_staff' => ['required', 'integer', 'min:1', 'max:50'],
            // Idealbesetzung: optional, mindestens Mindestbesetzung. Leer = nur Mindestbesetzung.
            'target_total_staff' => ['nullable', 'integer', 'min:1', 'max:50', 'gte:required_total_staff'],
            'required_specialists' => ['required', 'integer', 'min:0', 'max:50', 'lte:required_total_staff'],
        ]);

        ShiftCategoryStaffingRule::query()->updateOrCreate(
            [
                'location_id' => $locationId,
                'category' => $validated['category'],
                'weekday' => null,
            ],
            [
                'required_total_staff' => $validated['required_total_staff'],
                'target_total_staff' => $validated['target_total_staff'] ?? null,
                'required_specialists' => $validated['required_specialists'],
            ],
        );

        return back()->with('status', 'category-staffing-updated');
    }

    private function durationMinutes(string $startsAt, string $endsAt): int
    {
        $start = CarbonImmutable::createFromFormat('H:i', $startsAt);
        $end = CarbonImmutable::createFromFormat('H:i', $endsAt);
        $minutes = (int) $start->diffInMinutes($end, false);

        // Über Mitternacht (z. B. Nachtdienst 22:00–06:00).
        return $minutes <= 0 ? $minutes + 1440 : $minutes;
    }

    private function uniqueCodeFor(string $locationId, string $category): string
    {
        $code = $category;
        $suffix = 1;

        while (ShiftTemplate::query()
            ->where('location_id', $locationId)
            ->where('code', $code)
            ->exists()
        ) {
            $suffix++;
            $code = $category.$suffix;
        }

        return $code;
    }
}
