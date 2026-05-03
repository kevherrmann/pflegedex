<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeResidentViewing($request);

        $locations = $this->residentViewLocations($request);
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
        $this->authorizeResidentManagement($request);

        $locations = $request->user()?->accessibleLocations() ?? collect();
        $location = $this->selectedLocation($request, $locations) ?? $locations->first();

        return Inertia::render('Residents/Create', [
            'location' => $location ? $this->locationPayload($location) : null,
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeResidentManagement($request);

        $user = $request->user();
        $locations = $user?->accessibleLocations() ?? collect();

        if ($locations->isEmpty()) {
            return to_route('residents.index')
                ->with('warning', 'Bitte ordne deinem Konto zuerst einen Wohnbereich zu.');
        }

        $validated = $request->validate([
            'location_id' => [$locations->count() > 1 ? 'required' : 'nullable', 'string', 'uuid'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'care_level' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
        ]);

        $locationId = $locations->count() === 1
            ? $locations->first()->id
            : (string) ($validated['location_id'] ?? $locations->first()->id);

        if ($locations->count() > 1 && ! $locations->contains('id', $locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Du hast keinen Zugriff auf diesen Wohnbereich.',
            ]);
        }

        unset($validated['location_id']);

        DB::transaction(function () use ($validated, $locationId): void {
            Resident::query()->create($validated + [
                'pseudonym' => Resident::generatePseudonym(),
                'location_id' => $locationId,
                'active' => true,
            ]);
        });

        return to_route('residents.index', ['location_id' => $locationId]);
    }

    public function edit(Request $request, Resident $resident): Response
    {
        $this->authorizeResidentManagement($request);
        $locations = $request->user()?->accessibleLocations() ?? collect();
        abort_unless($locations->contains('id', $resident->location_id), 403);

        return Inertia::render('Residents/Edit', [
            'resident' => [
                'id' => $resident->id,
                'locationId' => $resident->location_id,
                'firstName' => $resident->first_name,
                'lastName' => $resident->last_name,
                'fullName' => $resident->full_name,
                'birthDate' => $resident->birth_date?->toDateString(),
                'roomNumber' => $resident->room_number,
                'careLevel' => $resident->care_level,
            ],
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
        ]);
    }

    public function update(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeResidentManagement($request);
        $locations = $request->user()?->accessibleLocations() ?? collect();
        abort_unless($locations->contains('id', $resident->location_id), 403);

        $validated = $request->validate([
            'location_id' => [$locations->count() > 1 ? 'required' : 'nullable', 'string', 'uuid'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'care_level' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
        ]);

        $locationId = $locations->count() === 1
            ? $locations->first()->id
            : (string) ($validated['location_id'] ?? $resident->location_id);

        if (! $locations->contains('id', $locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Du hast keinen Zugriff auf diesen Wohnbereich.',
            ]);
        }

        unset($validated['location_id']);

        $resident->update($validated + ['location_id' => $locationId]);

        return to_route('residents.index', ['location_id' => $locationId]);
    }

    private function authorizeResidentManagement(Request $request): void
    {
        abort_unless($request->user()?->hasRole('PDL'), 403);
    }

    private function authorizeResidentViewing(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['PDL', 'Pflegekraft']), 403);
    }

    /**
     * @return Collection<int, Location>
     */
    private function residentViewLocations(Request $request): Collection
    {
        $user = $request->user();

        if (! $user) {
            return collect();
        }

        if ($user->hasRole('Pflegekraft')) {
            return $user->locations()->orderBy('name')->get();
        }

        return $user->accessibleLocations();
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function selectedLocation(Request $request, Collection $locations): ?Location
    {
        $locationId = $request->string('location_id')->toString();

        if ($locationId === '') {
            return null;
        }

        return $locations->firstWhere('id', $locationId);
    }

    /**
     * @return array{id: string, name: string}
     */
    private function locationPayload(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
        ];
    }
}
