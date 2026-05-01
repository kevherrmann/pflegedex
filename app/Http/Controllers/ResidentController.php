<?php

namespace App\Http\Controllers;

use App\Models\Resident;
use Illuminate\Http\Request;
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
}
