<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentArea;
use App\Models\Roster;
use App\Models\User;
use App\Services\Rosters\PdlRosterAccess;
use App\Services\Rosters\ReplacementCandidateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vertretungssuche bei kurzfristigen Ausfällen (Krankmeldung): Listet die ab
 * heute unterbesetzten Schichten und schlägt je offene Stelle einsetzbare
 * Mitarbeitende mit freier Kapazität vor. Der Planer entscheidet manuell –
 * automatisch wird niemand verplant (Human-in-the-Loop).
 */
class RosterReplacementController extends Controller
{
    private const MONTHS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    public function index(
        Request $request,
        Roster $roster,
        PdlRosterAccess $pdlRosterAccess,
        ReplacementCandidateService $replacementCandidateService,
    ): Response {
        $pdlRosterAccess->ensurePdlCanAccessLocation($request, $roster->location_id);

        $roster->load('location');

        return Inertia::render('Rosters/Replacements', [
            'roster' => [
                'id' => $roster->id,
                'locationName' => $roster->location?->name,
                'year' => $roster->year,
                'month' => $roster->month,
                'monthLabel' => self::MONTHS[$roster->month].' '.$roster->year,
                'status' => $roster->status->value,
                'statusLabel' => $roster->status->label(),
                'isEditable' => $roster->isEditable(),
            ],
            'openSlots' => $replacementCandidateService->openSlots($roster),
            'employees' => $this->employeesForRoster($roster),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /**
     * @return array<int, array{id: string, name: string, isSpecialist: bool}>
     */
    private function employeesForRoster(Roster $roster): array
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
                'isSpecialist' => (bool) ($user->employeeProfile?->is_nursing_specialist ?? false),
            ])
            ->values()
            ->all();
    }
}
