<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Services\Rosters\PdlRosterAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftTemplateController extends Controller
{
    public function index(Request $request, PdlRosterAccess $pdlRosterAccess): Response
    {
        $locationId = $pdlRosterAccess->ensurePdlHasLocation($request);

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
            'shiftTemplates' => ShiftTemplate::query()
                ->with(['location', 'staffingRules'])
                ->where('location_id', $locationId)
                ->orderBy('location_id')
                ->orderBy('starts_at')
                ->get()
                ->map(function (ShiftTemplate $shiftTemplate): array {
                    $defaultStaffingRule = $shiftTemplate->staffingRules
                        ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === null);

                    return [
                        'id' => $shiftTemplate->id,
                        'locationId' => $shiftTemplate->location_id,
                        'locationName' => $shiftTemplate->location?->name,
                        'name' => $shiftTemplate->name,
                        'code' => $shiftTemplate->code,
                        'startsAt' => $shiftTemplate->starts_at,
                        'endsAt' => $shiftTemplate->ends_at,
                        'durationMinutes' => $shiftTemplate->duration_minutes,
                        'color' => $shiftTemplate->color,
                        'active' => $shiftTemplate->active,
                        'defaultStaffingRule' => $defaultStaffingRule === null
                            ? null
                            : [
                                'id' => $defaultStaffingRule->id,
                                'requiredTotalStaff' => $defaultStaffingRule->required_total_staff,
                                'requiredSpecialists' => $defaultStaffingRule->required_specialists,
                            ],
                    ];
                })
                ->values(),
        ]);
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

    public function updateStaffingRule(Request $request, ShiftTemplate $shiftTemplate, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $shiftTemplate->location_id);

        $validated = $request->validate([
            'required_total_staff' => ['required', 'integer', 'min:1', 'max:50'],
            'required_specialists' => ['required', 'integer', 'min:0', 'max:50', 'lte:required_total_staff'],
        ]);

        $defaultStaffingRule = $shiftTemplate
            ->staffingRules()
            ->whereNull('weekday')
            ->first();

        $attributes = [
            'location_id' => $shiftTemplate->location_id,
            'shift_template_id' => $shiftTemplate->id,
            'weekday' => null,
            'required_total_staff' => $validated['required_total_staff'],
            'required_specialists' => $validated['required_specialists'],
        ];

        if ($defaultStaffingRule === null) {
            ShiftStaffingRule::query()->create($attributes);
        } else {
            $defaultStaffingRule->update($attributes);
        }

        return back()->with('status', 'shift-staffing-rule-updated');
    }
}
