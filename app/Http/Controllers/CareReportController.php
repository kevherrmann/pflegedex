<?php

namespace App\Http\Controllers;

use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CareReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeCareReports($request);
        $locations = $this->careReportLocations($request);
        $locationIds = $locations->pluck('id');

        $residents = $locationIds->isNotEmpty()
            ? Resident::query()
                ->whereIn('location_id', $locationIds)
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
            : collect();

        $reports = $locationIds->isNotEmpty()
            ? CareReport::query()
                ->whereIn('location_id', $locationIds)
                ->with(['resident', 'location', 'author'])
                ->latest('occurred_at')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (CareReport $report): array => $this->reportPayload($report))
                ->values()
            : collect();

        return Inertia::render('CareReports/Index', [
            'reports' => $reports,
            'residents' => $residents->map(fn (Resident $resident): array => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ])->values(),
            'locations' => $locations->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
            ])->values(),
            'categories' => $this->categories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeCareReports($request);
        $locations = $this->careReportLocations($request);
        $locationIds = $locations->pluck('id')->all();

        $validated = $request->validate([
            'resident_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'category' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $resident = Resident::query()
            ->whereIn('location_id', $locationIds)
            ->find($validated['resident_id']);

        if (! $resident) {
            throw ValidationException::withMessages([
                'resident_id' => 'Du hast keinen Zugriff auf diesen Bewohner.',
            ]);
        }

        CareReport::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'author_id' => $request->user()->id,
            'occurred_at' => $validated['occurred_at'],
            'category' => $validated['category'],
            'body' => $validated['body'],
        ]);

        return to_route('care-reports.index');
    }

    private function authorizeCareReports(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['PDL', 'Pflegekraft']), 403);
    }

    /**
     * @return Collection<int, Location>
     */
    private function careReportLocations(Request $request): Collection
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

    /** @return list<string> */
    private function categories(): array
    {
        return ['Grundpflege', 'Beobachtung', 'Mobilität', 'Medikation', 'Übergabe', 'Sonstiges'];
    }

    /** @return array<string, mixed> */
    private function reportPayload(CareReport $report): array
    {
        return [
            'id' => $report->id,
            'residentName' => $report->resident?->full_name,
            'locationName' => $report->location?->name,
            'authorName' => $report->author?->name,
            'occurredAt' => $report->occurred_at->format('d.m.Y H:i'),
            'category' => $report->category,
            'body' => $report->body,
        ];
    }
}
