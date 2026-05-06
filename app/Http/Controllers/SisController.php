<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SisRiskKind;
use App\Enums\SisTopic;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisRisk;
use App\Models\SisTopicEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SisController extends Controller
{
    public function show(Request $request, Resident $resident): Response
    {
        $this->authorizeRead($request, $resident);

        $sis = $resident->sis()
            ->with(['topicEntries', 'risks', 'creator', 'updater'])
            ->withCount('versions')
            ->first();

        // Aktive oder zuletzt fertiggestellte Generation - Frontend kann pollen
        // bis status terminal ist und blendet danach den Hinweis aus.
        $latestGeneration = $sis !== null
            ? \App\Models\SisGeneration::query()
                ->where('sis_id', $sis->id)
                ->orderByDesc('created_at')
                ->first()
            : null;

        return Inertia::render('Sis/Show', [
            'resident' => $this->residentPayload($resident),
            'sis' => $sis ? $this->sisPayload($sis) : null,
            'canEdit' => $request->user()?->hasRole('PDL') ?? false,
            'topics' => $this->topicCatalog(),
            'risks' => $this->riskCatalog(),
            'latestGeneration' => $latestGeneration ? [
                'id' => $latestGeneration->id,
                'status' => $latestGeneration->status,
                'progress' => (int) $latestGeneration->progress,
                'totalSteps' => (int) $latestGeneration->total_steps,
                'errorMessage' => $latestGeneration->error_message,
            ] : null,
        ]);
    }

    public function create(Request $request, Resident $resident): Response|RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        if ($resident->sis()->exists()) {
            return redirect()->route('residents.sis.edit', $resident);
        }

        return Inertia::render('Sis/Create', [
            'resident' => $this->residentPayload($resident),
            'topics' => $this->topicCatalog(),
            'risks' => $this->riskCatalog(),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        if ($resident->sis()->exists()) {
            return redirect()->route('residents.sis.show', $resident);
        }

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($resident, $request, $validated): void {
            $user = $request->user();

            $sis = Sis::query()->create([
                'resident_id' => $resident->id,
                'location_id' => $resident->location_id,
                'opening_question' => $validated['opening_question'] ?? null,
                'started_at' => today(),
                'completed_at' => null,
                'evaluated_at' => null,
                'next_evaluation_due' => null,
                'created_by' => $user->id,
            ]);

            $this->syncTopics($sis, $validated['topics'] ?? []);
            $this->syncRisks($sis, $validated['risks'] ?? []);

            $sis->refresh()->load(['topicEntries', 'risks']);
            $sis->appendVersion('created', $user);
        });

        return redirect()
            ->route('residents.sis.show', $resident)
            ->with('success', 'SIS angelegt.');
    }

    public function edit(Request $request, Resident $resident): Response
    {
        $this->authorizeWrite($request, $resident);

        $sis = $resident->sis()
            ->with(['topicEntries', 'risks'])
            ->firstOrFail();

        return Inertia::render('Sis/Edit', [
            'resident' => $this->residentPayload($resident),
            'sis' => $this->sisPayload($sis),
            'topics' => $this->topicCatalog(),
            'risks' => $this->riskCatalog(),
        ]);
    }

    public function update(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        $sis = $resident->sis()
            ->with(['topicEntries', 'risks'])
            ->firstOrFail();

        $validated = $this->validatePayload($request, completedAtAllowed: true);

        DB::transaction(function () use ($sis, $request, $validated): void {
            $user = $request->user();

            $sis->appendVersion('updated', $user);

            $sis->forceFill([
                'opening_question' => $validated['opening_question'] ?? null,
                'completed_at' => $validated['completed_at'] ?? $sis->completed_at,
                'updated_by' => $user->id,
            ])->save();

            // Wenn completed_at gerade gesetzt wird und noch keine Evaluation
            // geplant war: 8 Wochen ab Fertigstellung als ersten Termin setzen.
            if ($sis->completed_at !== null && $sis->next_evaluation_due === null) {
                $sis->forceFill([
                    'next_evaluation_due' => $sis->completed_at->copy()->addWeeks(8),
                ])->save();
            }

            $this->syncTopics($sis, $validated['topics'] ?? []);
            $this->syncRisks($sis, $validated['risks'] ?? []);
        });

        return redirect()
            ->route('residents.sis.show', $resident)
            ->with('success', 'SIS aktualisiert.');
    }

    public function evaluate(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        $sis = $resident->sis()->firstOrFail();
        $sis->markEvaluated($request->user());

        return redirect()
            ->route('residents.sis.show', $resident)
            ->with('success', 'Evaluation gespeichert. Nächster Termin in 8 Wochen.');
    }

    // ---- Authorization --------------------------------------------------

    private function authorizeRead(Request $request, Resident $resident): void
    {
        $user = $request->user();
        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), 403);
        abort_unless($user->canAccessLocation($resident->location_id), 403);
    }

    private function authorizeWrite(Request $request, Resident $resident): void
    {
        $user = $request->user();
        abort_unless($user?->hasRole('PDL'), 403);
        abort_unless($user->canAccessLocation($resident->location_id), 403);
    }

    // ---- Validation -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $completedAtAllowed = false): array
    {
        $rules = [
            'opening_question' => ['nullable', 'string', 'max:5000'],
            'topics' => ['array'],
            'topics.*.topic_number' => ['required', 'integer', Rule::in(SisTopic::numbers())],
            'topics.*.content' => ['nullable', 'string', 'max:10000'],
            'risks' => ['array'],
            'risks.*.risk_kind' => ['required', 'string', Rule::in(SisRiskKind::values())],
            'risks.*.is_at_risk' => ['required', 'boolean'],
            'risks.*.needs_further_assessment' => ['required', 'boolean'],
            'risks.*.notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($completedAtAllowed) {
            $rules['completed_at'] = ['nullable', 'date', 'before_or_equal:today'];
        }

        return $request->validate($rules);
    }

    // ---- Persistence Helpers --------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $topics
     */
    private function syncTopics(Sis $sis, array $topics): void
    {
        $byNumber = collect($topics)->keyBy('topic_number');

        foreach (SisTopic::numbers() as $number) {
            $payload = $byNumber->get($number, []);
            SisTopicEntry::query()->updateOrCreate(
                ['sis_id' => $sis->id, 'topic_number' => $number],
                ['content' => $payload['content'] ?? null],
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $risks
     */
    private function syncRisks(Sis $sis, array $risks): void
    {
        $byKind = collect($risks)->keyBy('risk_kind');

        foreach (SisRiskKind::values() as $kind) {
            $payload = $byKind->get($kind, []);
            SisRisk::query()->updateOrCreate(
                ['sis_id' => $sis->id, 'risk_kind' => $kind],
                [
                    'is_at_risk' => (bool) ($payload['is_at_risk'] ?? false),
                    'needs_further_assessment' => (bool) ($payload['needs_further_assessment'] ?? false),
                    'notes' => $payload['notes'] ?? null,
                ],
            );
        }
    }

    // ---- Inertia Payloads -----------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function residentPayload(Resident $resident): array
    {
        return [
            'id' => $resident->id,
            'pseudonym' => $resident->pseudonym,
            'fullName' => $resident->full_name,
            'locationId' => $resident->location_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sisPayload(Sis $sis): array
    {
        return [
            'id' => $sis->id,
            'openingQuestion' => $sis->opening_question,
            'startedAt' => $sis->started_at?->toDateString(),
            'completedAt' => $sis->completed_at?->toDateString(),
            'evaluatedAt' => $sis->evaluated_at?->toDateString(),
            'nextEvaluationDue' => $sis->next_evaluation_due?->toDateString(),
            'isOverdue' => $sis->isOverdue(),
            'versionCount' => (int) ($sis->versions_count ?? $sis->versions()->count()),
            'topics' => $sis->topicEntries
                ->map(fn(SisTopicEntry $t): array => [
                    'topicNumber' => $t->topic_number,
                    'content' => $t->content,
                ])
                ->values()
                ->all(),
            'risks' => $sis->risks
                ->map(fn(SisRisk $r): array => [
                    'riskKind' => $r->risk_kind,
                    'isAtRisk' => $r->is_at_risk,
                    'needsFurtherAssessment' => $r->needs_further_assessment,
                    'notes' => $r->notes,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topicCatalog(): array
    {
        return array_map(
            fn(SisTopic $t): array => [
                'number' => $t->value,
                'label' => $t->label(),
            ],
            SisTopic::cases(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function riskCatalog(): array
    {
        return array_map(
            fn(SisRiskKind $r): array => [
                'kind' => $r->value,
                'label' => $r->label(),
            ],
            SisRiskKind::cases(),
        );
    }
}
