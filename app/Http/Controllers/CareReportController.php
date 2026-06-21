<?php

namespace App\Http\Controllers;

use App\Enums\CareReportCategory;
use App\Models\CareReport;
use App\Models\Location;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
        $selectedDate = $this->selectedDate($request);

        $residents = $locationIds->isNotEmpty()
            ? Resident::query()
                ->whereIn('location_id', $locationIds)
                ->active()
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
            : collect();

        $reportModels = $locationIds->isNotEmpty()
            ? CareReport::query()
                ->whereIn('location_id', $locationIds)
                ->whereDate('occurred_at', $selectedDate)
                ->with(['resident', 'location', 'author', 'signer'])
                ->withCount('versions')
                ->latest('occurred_at')
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();

        $selectedResident = $this->selectedResident($request, $residents);
        $categories = $this->categories();
        $selectedReports = $selectedResident
            ? $reportModels->where('resident_id', $selectedResident->id)
            : collect();

        $reportsByCategory = collect($categories)
            ->mapWithKeys(fn (string $category): array => [
                $category => $selectedReports
                    ->where('category', $category)
                    ->values()
                    ->map(fn (CareReport $report): array => $this->reportPayload($report))
                    ->values(),
            ]);

        return Inertia::render('CareReports/Index', [
            'reports' => $reportModels->map(fn (CareReport $report): array => $this->reportPayload($report))->values(),
            'reportsByCategory' => $reportsByCategory,
            'categoryTabs' => collect($categories)->map(fn (string $category): array => [
                'name' => $category,
                'reportCount' => $selectedReports->where('category', $category)->count(),
                'completed' => $selectedReports->where('category', $category)->isNotEmpty(),
            ])->values(),
            'selectedResident' => $selectedResident ? $this->residentPayload($selectedResident, $reportModels, $categories) : null,
            'selectedDate' => $selectedDate,
            'residents' => $residents->map(fn (Resident $resident): array => $this->residentPayload($resident, $reportModels, $categories))->values(),
            'locations' => $locations->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
            ])->values(),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeCareReports($request);
        $locations = $this->careReportLocations($request);
        $locationIds = $locations->pluck('id')->all();

        $validated = $request->validate([
            'resident_id' => ['required', 'string', 'uuid'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'category' => ['required', 'string', Rule::in(CareReportCategory::values())],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
            'sign' => ['sometimes', 'boolean'],
        ]);

        $resident = Resident::query()
            ->whereIn('location_id', $locationIds)
            ->find($validated['resident_id']);

        if (! $resident) {
            throw ValidationException::withMessages([
                'resident_id' => 'Du hast keinen Zugriff auf diesen Bewohner.',
            ]);
        }

        $report = CareReport::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'author_id' => $request->user()->id,
            'occurred_at' => $validated['occurred_at'],
            'category' => $validated['category'],
            'body' => $validated['body'],
        ]);

        $report->appendVersion('created', $request->user());

        // Optional direkt mit-signieren (ein Schritt statt extra Klick).
        if ($request->boolean('sign')) {
            $report->sign($request->user());
        }

        return to_route('care-reports.index', [
            'resident_id' => $resident->id,
            'date' => Carbon::parse($validated['occurred_at'])->toDateString(),
        ]);
    }

    public function update(Request $request, CareReport $careReport): RedirectResponse
    {
        $this->authorizeCareReports($request);

        if ($careReport->isSigned()) {
            abort(403);
        }

        $locations = $this->careReportLocations($request);
        $locationIds = $locations->pluck('id')->all();

        if (! in_array($careReport->location_id, $locationIds, true)) {
            abort(403);
        }

        $validated = $request->validate([
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'category' => ['required', 'string', Rule::in(CareReportCategory::values())],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        $careReport->update([
            'occurred_at' => $validated['occurred_at'],
            'category' => $validated['category'],
            'body' => $validated['body'],
        ]);

        $careReport->appendVersion('updated', $request->user());

        return to_route('care-reports.index', [
            'resident_id' => $careReport->resident_id,
            'date' => Carbon::parse($validated['occurred_at'])->toDateString(),
        ]);
    }

    public function sign(Request $request, CareReport $careReport): RedirectResponse
    {
        $this->authorizeCareReports($request);

        $locations = $this->careReportLocations($request);
        $locationIds = $locations->pluck('id')->all();

        if (! in_array($careReport->location_id, $locationIds, true)) {
            abort(403);
        }

        $careReport->sign($request->user());

        return to_route('care-reports.index', [
            'resident_id' => $careReport->resident_id,
            'date' => $careReport->occurred_at->toDateString(),
        ]);
    }

    private function authorizeCareReports(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['PDL', 'Pflegekraft']), 403);
    }

    private function selectedDate(Request $request): string
    {
        $date = $request->string('date')->toString();

        if ($date === '') {
            return today()->toDateString();
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
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

    /**
     * @param  Collection<int, Resident>  $residents
     */
    private function selectedResident(Request $request, Collection $residents): ?Resident
    {
        if ($residents->isEmpty()) {
            return null;
        }

        $requestedResidentId = $request->string('resident_id')->toString();

        if ($requestedResidentId !== '') {
            return $residents->firstWhere('id', $requestedResidentId) ?? $residents->first();
        }

        return $residents->first();
    }

    /**
     * @param  Collection<int, CareReport>  $reports
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    private function residentPayload(Resident $resident, Collection $reports, array $categories): array
    {
        $residentReports = $reports->where('resident_id', $resident->id);
        $completedCategoryCount = $residentReports
            ->pluck('category')
            ->intersect($categories)
            ->unique()
            ->count();

        return [
            'id' => $resident->id,
            'fullName' => $resident->full_name,
            'locationId' => $resident->location_id,
            'locationName' => $resident->location?->name,
            'reportCount' => $residentReports->count(),
            'completedCategoryCount' => $completedCategoryCount,
            'missingCategoryCount' => max(count($categories) - $completedCategoryCount, 0),
        ];
    }

    /** @return list<string> */
    private function categories(): array
    {
        return CareReportCategory::values();
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
            'signed' => $report->signed,
            'signedAt' => $report->signed_at?->format('d.m.Y H:i'),
            'signedByName' => $report->signer?->name,
            'versionCount' => (int) ($report->versions_count ?? 0),
        ];
    }
}
