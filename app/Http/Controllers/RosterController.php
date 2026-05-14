<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Roster;
use App\Services\Rosters\RosterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class RosterController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        return Inertia::render('Rosters/Index', [
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                ])
                ->values(),
            'rosters' => Roster::query()
                ->with(['location', 'createdBy'])
                ->withCount('shifts')
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->orderBy('location_id')
                ->get()
                ->map(fn (Roster $roster): array => [
                    'id' => $roster->id,
                    'locationId' => $roster->location_id,
                    'locationName' => $roster->location?->name,
                    'year' => $roster->year,
                    'month' => $roster->month,
                    'status' => $roster->status->value,
                    'statusLabel' => $roster->status->label(),
                    'isEditable' => $roster->isEditable(),
                    'isPublished' => $roster->isPublished(),
                    'generatedAt' => $roster->generated_at?->toDateTimeString(),
                    'publishedAt' => $roster->published_at?->toDateTimeString(),
                    'createdByName' => $roster->createdBy?->name,
                    'shiftsCount' => $roster->shifts_count,
                    'createdAt' => $roster->created_at?->toDateTimeString(),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, RosterService $rosterService): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $location = Location::query()->findOrFail($validated['location_id']);

        $rosterService->createOrGetDraft(
            $location,
            $request->user(),
            (int) $validated['year'],
            (int) $validated['month'],
        );

        return back()->with('status', 'roster-created');
    }

    public function publish(Request $request, Roster $roster, RosterService $rosterService): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $rosterService->publish($roster);

        return back()->with('status', 'roster-published');
    }

    public function lock(Request $request, Roster $roster, RosterService $rosterService): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $rosterService->lock($roster);

        return back()->with('status', 'roster-locked');
    }

    public function reopen(Request $request, Roster $roster, RosterService $rosterService): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $rosterService->reopen($roster);

        return back()->with('status', 'roster-reopened');
    }
}
