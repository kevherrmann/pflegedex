<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $locations = $user?->accessibleLocations() ?? collect();
        $location = $this->selectedLocation($request, $locations)
            ?? ($locations->count() === 1 ? $locations->first() : null);

        $residents = $locations->isNotEmpty()
            ? Resident::query()
                ->whereIn('location_id', $location ? [$location->id] : $locations->pluck('id'))
                ->active()
                ->with('location')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Resident $resident): array => [
                    'id' => $resident->id,
                    'fullName' => $resident->full_name,
                    'roomNumber' => $resident->room_number,
                    'careLevel' => $resident->care_level,
                    'locationName' => $resident->location?->name,
                ])
                ->values()
            : collect();

        return Inertia::render('Residents/Index', [
            'location' => $location ? $this->locationPayload($location) : null,
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
            'residents' => $residents,
        ]);
    }

    public function create(Request $request): Response
    {
        $locations = $request->user()?->accessibleLocations() ?? collect();
        $location = $this->selectedLocation($request, $locations) ?? $locations->first();

        return Inertia::render('Residents/Create', [
            'location' => $location ? $this->locationPayload($location) : null,
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $locations = $user?->accessibleLocations() ?? collect();

        if ($locations->isEmpty()) {
            return to_route('residents.index')
                ->with('warning', 'Bitte ordne deinem Konto zuerst einen Wohnbereich zu.');
        }

        $validated = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'care_level' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
        ]);

        $locationId = $locations->count() === 1
            ? $locations->first()->id
            : (int) ($validated['location_id'] ?? $locations->first()->id);

        if ($locations->count() > 1 && ! $locations->contains('id', $locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Du hast keinen Zugriff auf diesen Wohnbereich.',
            ]);
        }

        unset($validated['location_id']);

        Resident::query()->create($validated + [
            'location_id' => $locationId,
            'active' => true,
        ]);

        return to_route('residents.index', ['location_id' => $locationId]);
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function selectedLocation(Request $request, Collection $locations): ?Location
    {
        $locationId = $request->integer('location_id');

        if (! $locationId) {
            return null;
        }

        return $locations->firstWhere('id', $locationId);
    }

    /**
     * @return array{id: int, name: string}
     */
    private function locationPayload(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
        ];
    }
}
