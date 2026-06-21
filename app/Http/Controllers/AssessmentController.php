<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function index(Request $request, Resident $resident): Response
    {
        $this->authorizeAccess($request, $resident);

        $assessments = Assessment::query()
            ->where('resident_id', $resident->id)
            ->with('assessor')
            ->latest('assessed_on')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Assessment $a): array => $this->payload($a))
            ->values();

        return Inertia::render('Assessments/Index', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ],
            'assessments' => $assessments,
            'definitions' => collect(AssessmentType::cases())
                ->map(fn (AssessmentType $t): array => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'catalog' => $t->catalog(),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        $type = AssessmentType::tryFrom((string) $request->input('type'));
        abort_if($type === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY);

        $rules = [
            'type' => ['required', Rule::enum(AssessmentType::class)],
            'assessed_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'answers' => ['required', 'array'],
        ];

        foreach ($type->catalog() as $item) {
            $allowed = array_map(fn (array $o): int => $o['value'], $item['options']);
            $rules['answers.'.$item['key']] = ['required', 'integer', Rule::in($allowed)];
        }

        $validated = $request->validate($rules);

        /** @var array<string, int> $answers */
        $answers = array_map('intval', $validated['answers']);
        $score = $type->score($answers);

        Assessment::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'type' => $type,
            'assessed_on' => $validated['assessed_on'],
            'answers' => $answers,
            'total_score' => $score['total'],
            'risk_level' => $score['risk'],
            'note' => $validated['note'] ?? null,
            'next_due' => Carbon::parse($validated['assessed_on'])->addWeeks($type->reevaluationWeeks()),
            'assessed_by' => $request->user()->id,
        ]);

        return to_route('residents.assessments.index', $resident);
    }

    public function destroy(Request $request, Resident $resident, Assessment $assessment): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($assessment->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $assessment->delete();

        return to_route('residents.assessments.index', $resident);
    }

    private function authorizeAccess(Request $request, Resident $resident): void
    {
        $user = $request->user();

        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), HttpResponse::HTTP_FORBIDDEN);
        abort_unless($user->canAccessLocation($resident->location_id), HttpResponse::HTTP_FORBIDDEN);
    }

    /** @return array<string, mixed> */
    private function payload(Assessment $assessment): array
    {
        $type = $assessment->type;

        // Antworten in lesbare Label-Paare aufloesen (für die Detailanzeige).
        $optionLabels = [];
        foreach ($type->catalog() as $item) {
            foreach ($item['options'] as $option) {
                $optionLabels[$item['key']][$option['value']] = $option['label'];
                $optionLabels['__item__'][$item['key']] = $item['label'];
            }
        }

        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $resolved = [];
        foreach ($answers as $key => $value) {
            $resolved[] = [
                'label' => $optionLabels['__item__'][$key] ?? $key,
                'value' => $optionLabels[$key][$value] ?? (string) $value,
            ];
        }

        return [
            'id' => $assessment->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'assessedOn' => $assessment->assessed_on->format('d.m.Y'),
            'totalScore' => $assessment->total_score,
            'riskLevel' => $assessment->risk_level,
            'note' => $assessment->note,
            'nextDue' => $assessment->next_due?->format('d.m.Y'),
            'assessedByName' => $assessment->assessor?->name,
            'answers' => $resolved,
        ];
    }
}
