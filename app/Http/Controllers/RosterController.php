<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentArea;
use App\Models\Location;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\PdlRosterAccess;
use App\Services\Rosters\RosterGeneratorService;
use App\Services\Rosters\RosterService;
use App\Services\Rosters\RosterValidator;
use App\Services\Rosters\RosterValidationResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class RosterController extends Controller
{
    public function index(Request $request, PdlRosterAccess $pdlRosterAccess): Response
    {
        $locationId = $pdlRosterAccess->ensurePdlHasLocation($request);

        return Inertia::render('Rosters/Index', [
            'locations' => $this->locations($locationId),
            'rosters' => Roster::query()
                ->with(['location', 'createdBy'])
                ->withCount('shifts')
                ->where('location_id', $locationId)
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->orderBy('location_id')
                ->get()
                ->map(fn (Roster $roster): array => $this->mapRoster($roster, includeShifts: false))
                ->values(),
        ]);
    }

    public function show(Request $request, Roster $roster, PdlRosterAccess $pdlRosterAccess): Response
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $roster->load(['location', 'createdBy', 'shifts.user', 'shifts.shiftTemplate']);
        $roster->loadCount('shifts');

        return Inertia::render('Rosters/Show', [
            'roster' => $this->mapRoster($roster),
            'employees' => $this->employeesForRoster($roster),
            'shiftTemplates' => $this->shiftTemplatesForRoster($roster),
            'calendarDays' => $this->calendarDaysForRoster($roster),
            'rosterValidationResult' => $request->session()->get('rosterValidationResult'),
            'rosterGenerationResult' => $request->session()->get('rosterGenerationResult'),
        ]);
    }

    public function store(Request $request, RosterService $rosterService, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlHasLocation($request);

        $validated = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $location = Location::query()->findOrFail($validated['location_id']);
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $location->id);

        $rosterService->createOrGetDraft(
            $location,
            $request->user(),
            (int) $validated['year'],
            (int) $validated['month'],
        );

        return back()->with('status', 'roster-created');
    }

    public function publish(Request $request, Roster $roster, RosterService $rosterService, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $rosterService->publish($roster);

        return back()->with('status', 'roster-published');
    }

    public function lock(Request $request, Roster $roster, RosterService $rosterService, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $rosterService->lock($roster);

        return back()->with('status', 'roster-locked');
    }

    public function reopen(Request $request, Roster $roster, RosterService $rosterService, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $rosterService->reopen($roster);

        return back()->with('status', 'roster-reopened');
    }

    public function validateRoster(Request $request, Roster $roster, RosterValidator $rosterValidator, PdlRosterAccess $pdlRosterAccess): RedirectResponse
    {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $result = $rosterValidator->validate($roster);

        return back()
            ->with('status', 'roster-validated')
            ->with('rosterValidationResult', $this->validationFlashPayload($roster, $result));
    }

    public function generate(
        Request $request,
        Roster $roster,
        PdlRosterAccess $pdlRosterAccess,
        RosterGeneratorService $generator,
        RosterValidator $rosterValidator,
    ): RedirectResponse {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $result = $generator->generate($roster);
        $roster->refresh();
        $validationResult = $rosterValidator->validate($roster);

        return back()
            ->with('status', 'roster-generated')
            ->with('rosterGenerationResult', [
                'createdShifts' => $result->createdShifts,
                'deletedAutoShifts' => $result->deletedAutoShifts,
                'skipped' => $result->skipped,
            ])
            ->with('rosterValidationResult', $this->validationFlashPayload($roster, $validationResult));
    }

    public function deleteAutoShifts(
        Request $request,
        Roster $roster,
        PdlRosterAccess $pdlRosterAccess,
        RosterGeneratorService $generator,
    ): RedirectResponse {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $result = $generator->deleteAutoShifts($roster);

        return back()
            ->with('status', 'roster-auto-shifts-deleted')
            ->with('rosterGenerationResult', [
                'createdShifts' => $result->createdShifts,
                'deletedAutoShifts' => $result->deletedAutoShifts,
                'skipped' => $result->skipped,
            ]);
    }

    private function validationFlashPayload(Roster $roster, RosterValidationResult $result): array
    {
        return [
            'rosterId' => $roster->id,
            'status' => $result->isRed()
                ? 'red'
                : ($result->isYellow() ? 'yellow' : 'green'),
            'errors' => $result->errors,
            'warnings' => $result->warnings,
        ];
    }

    private function locations(string $locationId): Collection
    {
        return Location::query()
            ->whereKey($locationId)
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
            ->map(fn (Shift $shift): array => $this->mapShift($shift, includeDate: true))
            ->values();
    }

    private function mapShift(Shift $shift, bool $includeDate = false): array
    {
        $mapped = [
            'id' => $shift->id,
            'userId' => $shift->user_id,
            'shiftTemplateId' => $shift->shift_template_id,
            'startsAt' => $shift->starts_at->toDateTimeString(),
            'endsAt' => $shift->ends_at->toDateTimeString(),
            'employeeName' => $shift->user?->name,
            'shiftTemplateName' => $shift->shiftTemplate?->name,
            'shiftTemplateCode' => $shift->shiftTemplate?->code,
            'source' => $shift->source->value,
            'sourceLabel' => match ($shift->source->value) {
                'auto' => 'Auto',
                'manual' => 'Manuell',
                default => $shift->source->value,
            },
            'note' => $shift->note,
        ];

        if ($includeDate) {
            $mapped = ['date' => $shift->date->toDateString()] + $mapped;
        }

        return $mapped;
    }

    private function calendarDaysForRoster(Roster $roster): Collection
    {
        $weekdayLabels = [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ];
        $firstDay = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $days = collect();

        for ($date = $firstDay; $date->month === $roster->month; $date = $date->addDay()) {
            $dayShifts = $roster->shifts
                ->filter(fn (Shift $shift): bool => $shift->date->toDateString() === $date->toDateString())
                ->sortBy(fn (Shift $shift): string => $shift->starts_at->toDateTimeString())
                ->map(fn (Shift $shift): array => $this->mapShift($shift))
                ->values();

            $days->push([
                'date' => $date->toDateString(),
                'dayLabel' => $date->format('d.m.Y'),
                'weekdayLabel' => $weekdayLabels[$date->dayOfWeekIso],
                'shifts' => $dayShifts,
            ]);
        }

        return $days;
    }

    private function employeesForRoster(Roster $roster): Collection
    {
        return User::query()
            ->with('employeeProfile')
            ->where('location_id', $roster->location_id)
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
