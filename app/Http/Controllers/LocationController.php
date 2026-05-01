<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePdl($request);

        $user = $request->user();

        return Inertia::render('Locations/Index', [
            'locations' => Location::query()
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'shortName' => $location->short_name,
                    'description' => $location->description,
                    'userHasAccess' => $user?->canAccessLocation($location) ?? false,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePdl($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('locations', 'name')],
            'short_name' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $location = Location::query()->create($validated + ['active' => true]);
        $user = $request->user();

        if ($user) {
            if (! $user->location_id) {
                $user->forceFill(['location_id' => $location->id])->save();
            }

            $user->locations()->syncWithoutDetaching([$location->id]);
        }

        return to_route('locations.index');
    }

    public function edit(Request $request, Location $location): Response
    {
        $this->authorizePdl($request);

        return Inertia::render('Locations/Edit', [
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'shortName' => $location->short_name,
                'description' => $location->description,
            ],
        ]);
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->authorizePdl($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('locations', 'name')->ignore($location)],
            'short_name' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $location->update($validated);

        return to_route('locations.index');
    }

    private function authorizePdl(Request $request): void
    {
        abort_unless($request->user()?->hasRole('PDL'), 403);
    }
}
