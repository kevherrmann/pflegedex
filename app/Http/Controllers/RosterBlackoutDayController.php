<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\RosterBlackoutDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RosterBlackoutDayController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        return Inertia::render('RosterBlackoutDays/Index', [
            'blackoutDays' => RosterBlackoutDay::query()
                ->with(['location', 'createdBy'])
                ->orderByDesc('date')
                ->get()
                ->map(fn (RosterBlackoutDay $blackoutDay): array => [
                    'id' => $blackoutDay->id,
                    'locationName' => $blackoutDay->location?->name,
                    'date' => $blackoutDay->date->toDateString(),
                    'reason' => $blackoutDay->reason,
                    'blocksVacation' => $blackoutDay->blocks_vacation,
                    'blocksOvertimeCompensation' => $blackoutDay->blocks_overtime_compensation,
                    'createdByName' => $blackoutDay->createdBy?->name,
                    'createdAt' => $blackoutDay->created_at?->toDateString(),
                ])
                ->values(),
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
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
            'location_id' => ['required', 'exists:locations,id'],
            'date' => [
                'required',
                'date',
                Rule::unique('roster_blackout_days', 'date')
                    ->where('location_id', $request->input('location_id')),
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
            'blocks_vacation' => ['sometimes', 'boolean'],
            'blocks_overtime_compensation' => ['sometimes', 'boolean'],
        ], [
            'date.unique' => 'Für diesen Wohnbereich gibt es an diesem Datum bereits eine Urlaubssperre.',
        ]);

        RosterBlackoutDay::query()->create([
            'location_id' => $validated['location_id'],
            'date' => $validated['date'],
            'reason' => $validated['reason'] ?? null,
            'blocks_vacation' => array_key_exists('blocks_vacation', $validated)
                ? $validated['blocks_vacation']
                : true,
            'blocks_overtime_compensation' => array_key_exists('blocks_overtime_compensation', $validated)
                ? $validated['blocks_overtime_compensation']
                : true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', 'roster-blackout-day-created');
    }
}
