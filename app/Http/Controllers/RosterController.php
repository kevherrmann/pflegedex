<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentArea;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\RosterService;
use App\Services\Rosters\RosterValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
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
            'locations' => $this->locations(),
            'rosters' => Roster::query()
                ->with(['location', 'createdBy'])
                ->withCount('shifts')
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->orderBy('location_id')
                ->get()
                ->map(fn (Roster $roster): array => $this->mapRoster($roster, includeShifts: false))
                ->values(),
        ]);
    }

    public function show(Request $request, Roster $roster): Response
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $roster->load(['location', 'createdBy', 'shifts.user', 'shifts.shiftTemplate']);
        $roster->loadCount('shifts');

        return Inertia::render('Rosters/Show', [
            'roster' => $this->mapRoster($roster),
            'employees' => $this->employeesForRoster($roster),
            'shiftTemplates' => $this->shiftTemplatesForRoster($roster),
            'rosterValidationResult' => $request->session()->get('rosterValidationResult'),
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

    public function validateRoster(Request $request, Roster $roster, RosterValidator $rosterValidator): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $result = $rosterValidator->validate($roster);

        return back()
            ->with('status', 'roster-validated')
            ->with('rosterValidationResult', [
                'rosterId' => $roster->id,
                'status' => $result->isRed()
                    ? 'red'
                    : ($result->isYellow() ? 'yellow' : 'green'),
                'errors' => $result->errors,
                'warnings' => $result->warnings,
            ]);
    }

    private function locations(): Collection
    {
        return Location::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
            ])
            ->values();
    }

    private function mapRoster(Roster $roster, bool $includeShifts = true): array
    {
        return [
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
            'shiftsCount' => $roster->shifts_count ?? $roster->shifts()->count(),
            'createdAt' => $roster->created_at?->toDateTimeString(),
            'shifts' => $includeShifts ? $this->mapShifts($roster) : [],
        ];
    }

    private function mapShifts(Roster $roster): Collection
    {
        return $roster->shifts
            ->sortBy(fn (Shift $shift): string => $shift->date->toDateString() . ' ' . $shift->starts_at->toDateTimeString())
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'date' => $shift->date->toDateString(),
                'startsAt' => $shift->starts_at->toDateTimeString(),
                'endsAt' => $shift->ends_at->toDateTimeString(),
                'employeeName' => $shift->user?->name,
                'shiftTemplateName' => $shift->shiftTemplate?->name,
                'shiftTemplateCode' => $shift->shiftTemplate?->code,
                'note' => $shift->note,
            ])
            ->values();
    }

    private function employeesForRoster(Roster $roster): Collection
    {
        return User::query()
            ->with('employeeProfile')
            ->whereHas('employeeProfile', fn ($query) => $query
                ->where('active', true)
                ->where('employment_area', EmploymentArea::Nursing->value))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locationId' => $user->location_id,
                'isNursingSpecialist' => $user->employeeProfile?->is_nursing_specialist ?? false,
                'canWorkEarly' => $user->employeeProfile?->can_work_early ?? false,
                'canWorkLate' => $user->employeeProfile?->can_work_late ?? false,
                'canWorkNight' => $user->employeeProfile?->can_work_night ?? false,
            ])
            ->values();
    }

    private function shiftTemplatesForRoster(Roster $roster): Collection
    {
        return ShiftTemplate::query()
            ->where('location_id', $roster->location_id)
            ->where('active', true)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ShiftTemplate $shiftTemplate): array => [
                'id' => $shiftTemplate->id,
                'locationId' => $shiftTemplate->location_id,
                'name' => $shiftTemplate->name,
                'code' => $shiftTemplate->code,
                'startsAt' => $shiftTemplate->starts_at,
                'endsAt' => $shiftTemplate->ends_at,
            ])
            ->values();
    }
}
