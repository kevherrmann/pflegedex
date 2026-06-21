<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QualityIndicator;
use App\Models\QualityAssessment;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QualityAssessmentController extends Controller
{
    /** Erhebungsbogen eines Bewohners für ein Halbjahr. */
    public function resident(Request $request, Resident $resident): Response
    {
        $this->authorizeAccess($request, $resident);

        $period = $this->resolvePeriod($request);

        $assessment = QualityAssessment::query()
            ->where('resident_id', $resident->id)
            ->where('period', $period)
            ->first();

        return Inertia::render('Quality/Resident', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ],
            'period' => $period,
            'periods' => $this->recentPeriods(),
            'indicators' => $this->indicatorDefinitions(),
            'answers' => is_array($assessment?->answers) ? $assessment->answers : [],
            'note' => $assessment?->note,
            'assessedOn' => $assessment?->assessed_on?->toDateString(),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        $rules = [
            'period' => ['required', 'regex:/^\d{4}-H[12]$/'],
            'assessed_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'answers' => ['required', 'array'],
        ];

        foreach (QualityIndicator::cases() as $indicator) {
            $allowed = array_map(fn (array $o): string => $o['value'], $indicator->options());
            $rules['answers.'.$indicator->value] = ['nullable', Rule::in($allowed)];
        }

        $validated = $request->validate($rules);

        QualityAssessment::query()->updateOrCreate(
            ['resident_id' => $resident->id, 'period' => $validated['period']],
            [
                'location_id' => $resident->location_id,
                'assessed_on' => $validated['assessed_on'],
                'answers' => array_filter(
                    $validated['answers'],
                    fn ($v): bool => $v !== null && $v !== '',
                ),
                'note' => $validated['note'] ?? null,
                'assessed_by' => $request->user()->id,
            ],
        );

        return to_route('residents.quality.index', ['resident' => $resident->id, 'period' => $validated['period']]);
    }

    /** Aggregierte Halbjahres-Auswertung über die zugänglichen Wohnbereiche. */
    public function evaluation(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->hasRole('PDL'), HttpResponse::HTTP_FORBIDDEN);

        $locations = $user->accessibleLocations();
        $locationIds = $locations->pluck('id')->all();
        $period = $this->resolvePeriod($request);

        $assessments = QualityAssessment::query()
            ->whereIn('location_id', $locationIds)
            ->where('period', $period)
            ->get();

        $results = collect(QualityIndicator::cases())->map(function (QualityIndicator $indicator) use ($assessments): array {
            $good = 0;
            $bad = 0;
            $excluded = 0;

            foreach ($assessments as $assessment) {
                $value = (is_array($assessment->answers) ? $assessment->answers : [])[$indicator->value] ?? null;
                match ($indicator->quality($value)) {
                    'good' => $good++,
                    'bad' => $bad++,
                    'excluded' => $excluded++,
                    default => null,
                };
            }

            $assessed = $good + $bad;

            return [
                'value' => $indicator->value,
                'label' => $indicator->label(),
                'area' => $indicator->area(),
                'good' => $good,
                'bad' => $bad,
                'excluded' => $excluded,
                'assessed' => $assessed,
                'percentGood' => $assessed > 0 ? (int) round($good / $assessed * 100) : null,
            ];
        })->values();

        return Inertia::render('Quality/Evaluation', [
            'period' => $period,
            'periods' => $this->recentPeriods(),
            'residentsAssessed' => $assessments->count(),
            'locationNames' => $locations->pluck('name')->values(),
            'results' => $results,
        ]);
    }

    private function authorizeAccess(Request $request, Resident $resident): void
    {
        $user = $request->user();

        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), HttpResponse::HTTP_FORBIDDEN);
        abort_unless($user->canAccessLocation($resident->location_id), HttpResponse::HTTP_FORBIDDEN);
    }

    private function resolvePeriod(Request $request): string
    {
        $requested = $request->string('period')->toString();

        if (preg_match('/^\d{4}-H[12]$/', $requested) === 1) {
            return $requested;
        }

        return $this->periodFor(Carbon::today());
    }

    private function periodFor(Carbon $date): string
    {
        return $date->year.'-H'.($date->month <= 6 ? '1' : '2');
    }

    /** @return list<string> Aktuelles + drei vorherige Halbjahre. */
    private function recentPeriods(): array
    {
        $periods = [];
        $cursor = Carbon::today();

        for ($i = 0; $i < 4; $i++) {
            $periods[] = $this->periodFor($cursor);
            $cursor = $cursor->copy()->subMonths(6);
        }

        return $periods;
    }

    /**
     * @return list<array{value: string, label: string, area: int, options: list<array{value: string, label: string, quality: string}>}>
     */
    private function indicatorDefinitions(): array
    {
        return collect(QualityIndicator::cases())
            ->map(fn (QualityIndicator $i): array => [
                'value' => $i->value,
                'label' => $i->label(),
                'area' => $i->area(),
                'options' => $i->options(),
            ])
            ->values()
            ->all();
    }
}
