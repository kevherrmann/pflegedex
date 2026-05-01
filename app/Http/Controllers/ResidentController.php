<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(Request $request): Response
    {
        $location = $request->user()?->location;

        $residents = $location
            ? Resident::query()
                ->forLocation($location)
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Resident $resident): array => [
                    'id' => $resident->id,
                    'fullName' => $resident->full_name,
                    'roomNumber' => $resident->room_number,
                    'careLevel' => $resident->care_level,
                ])
                ->values()
            : collect();

        return Inertia::render('Residents/Index', [
            'location' => $location ? [
                'id' => $location->id,
                'name' => $location->name,
            ] : null,
            'residents' => $residents,
        ]);
    }

    public function create(Request $request): Response
    {
        $location = $request->user()?->location;

        return Inertia::render('Residents/Create', [
            'location' => $location ? [
                'id' => $location->id,
                'name' => $location->name,
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $location = $request->user()?->location;

        if (! $location) {
            return to_route('residents.index')
                ->with('warning', 'Bitte ordne deinem Konto zuerst einen Wohnbereich zu.');
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'care_level' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
        ]);

        $location->residents()->create($validated + ['active' => true]);

        return to_route('residents.index');
    }
}
