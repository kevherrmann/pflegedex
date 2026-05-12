<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CarePlanTopic as CarePlanTopicEnum;
use App\Models\CarePlan;
use App\Models\CarePlanGeneration;
use App\Models\CarePlanTopicEntry;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CarePlanController extends Controller
{
    public function show(Request $request, Resident $resident): Response
    {
        $this->authorizeRead($request, $resident);

        $carePlan = $resident->carePlan()
            ->with(['topics', 'creator', 'updater'])
            ->withCount('versions')
            ->first();

        $sis = $resident->sis()->first();

        // Aktive oder zuletzt fertiggestellte Generation - Frontend kann pollen
        // bis status terminal ist und blendet danach den Hinweis aus.
        $latestGeneration = $carePlan !== null
            ? CarePlanGeneration::query()
                ->where('care_plan_id', $carePlan->id)
                ->orderByDesc('created_at')
                ->first()
            : null;

        return Inertia::render('CarePlan/Show', [
            'resident' => $this->residentPayload($resident),
            'carePlan' => $carePlan ? $this->carePlanPayload($carePlan) : null,
            'sisStatus' => [
                'exists' => $sis !== null,
                'completed' => $sis?->completed_at !== null,
                'completedAt' => $sis?->completed_at?->toDateString(),
            ],
            'canEdit' => $request->user()?->hasRole('PDL') ?? false,
            'topics' => $this->topicCatalog(),
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

        if ($resident->carePlan()->exists()) {
            return redirect()->route('residents.care-plan.show', $resident);
        }

        $this->ensureSisCompleted($resident);

        return Inertia::render('CarePlan/Create', [
            'resident' => $this->residentPayload($resident),
            'topics' => $this->topicCatalog(),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        if ($resident->carePlan()->exists()) {
            return redirect()->route('residents.care-plan.show', $resident);
        }

        $this->ensureSisCompleted($resident);

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($resident, $request, $validated): void {
            $user = $request->user();

            $carePlan = CarePlan::query()->create([
                'resident_id' => $resident->id,
                'location_id' => $resident->location_id,
                'grundbotschaft' => $validated['grundbotschaft'] ?? null,
                'started_at' => today(),
                'evaluated_at' => null,
                'next_evaluation_due' => today()->addWeeks(8),
                'created_by' => $user->id,
            ]);

            $this->syncTopics($carePlan, $validated['topics'] ?? []);

            $carePlan->refresh()->load('topics');
            $carePlan->appendVersion('created', $user);
        });

        return redirect()
            ->route('residents.care-plan.show', $resident)
            ->with('success', 'Maßnahmenplan angelegt. Nächste Evaluation in 8 Wochen.');
    }

    public function edit(Request $request, Resident $resident): Response
    {
        $this->authorizeWrite($request, $resident);

        $carePlan = $resident->carePlan()
            ->with('topics')
            ->firstOrFail();

        return Inertia::render('CarePlan/Edit', [
            'resident' => $this->residentPayload($resident),
            'carePlan' => $this->carePlanPayload($carePlan),
            'topics' => $this->topicCatalog(),
        ]);
    }

    public function update(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        $carePlan = $resident->carePlan()
            ->with('topics')
            ->firstOrFail();

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($carePlan, $request, $validated): void {
            $user = $request->user();

            $carePlan->appendVersion('updated', $user);

            $carePlan->forceFill([
                'grundbotschaft' => $validated['grundbotschaft'] ?? null,
                'updated_by' => $user->id,
            ])->save();

            $this->syncTopics($carePlan, $validated['topics'] ?? []);
        });

        return redirect()
            ->route('residents.care-plan.show', $resident)
            ->with('success', 'Maßnahmenplan aktualisiert.');
    }

    public function evaluate(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeWrite($request, $resident);

        $carePlan = $resident->carePlan()->firstOrFail();
        $carePlan->markEvaluated($request->user());

        return redirect()
            ->route('residents.care-plan.show', $resident)
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

    /**
     * MP-Anlage ist nur moeglich, wenn die SIS des Bewohners
     * fertiggestellt ist (completed_at !== null). Andernfalls 422
     * mit aussagekraeftiger Fehlermeldung.
     */
    private function ensureSisCompleted(Resident $resident): void
    {
        $sis = $resident->sis()->first();

        abort_if(
            $sis === null,
            422,
            'Maßnahmenplan kann nicht angelegt werden: Es existiert noch keine SIS für diesen Bewohner.'
        );

        abort_if(
            $sis->completed_at === null,
            422,
            'Maßnahmenplan kann nicht angelegt werden: Die SIS muss zuerst fertiggestellt werden.'
        );
    }

    // ---- Validation -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'grundbotschaft' => ['nullable', 'string', 'max:5000'],
            'topics' => ['array'],
            'topics.*.topic_number' => ['required', 'integer', Rule::in(CarePlanTopicEnum::numbers())],
            'topics.*.content' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    // ---- Persistence Helpers --------------------------------------------

    /**
     * On-demand-Sync: nur Topics mit nicht-leerem Content werden
     * persistiert. Leere Eintraege werden geloescht (= "Thema
     * nicht relevant"-Pattern).
     *
     * @param  list<array<string, mixed>>  $topics
     */
    private function syncTopics(CarePlan $carePlan, array $topics): void
    {
        $byNumber = collect($topics)->keyBy('topic_number');

        foreach (CarePlanTopicEnum::numbers() as $number) {
            $payload = $byNumber->get($number);
            $content = is_array($payload) ? trim((string) ($payload['content'] ?? '')) : '';

            if ($content === '') {
                CarePlanTopicEntry::query()
                    ->where('care_plan_id', $carePlan->id)
                    ->where('topic_number', $number)
                    ->delete();
                continue;
            }

            CarePlanTopicEntry::query()->updateOrCreate(
                ['care_plan_id' => $carePlan->id, 'topic_number' => $number],
                ['content' => $content],
            );
        }
    }

    // ---- Inertia-Payload-Helpers ----------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function residentPayload(Resident $resident): array
    {
        return [
            'id' => $resident->id,
            'fullName' => $resident->full_name,
            'formalName' => $resident->formal_name,
            'pseudonym' => $resident->pseudonym,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function carePlanPayload(CarePlan $carePlan): array
    {
        return [
            'id' => $carePlan->id,
            'grundbotschaft' => $carePlan->grundbotschaft,
            'startedAt' => $carePlan->started_at?->toDateString(),
            'evaluatedAt' => $carePlan->evaluated_at?->toDateString(),
            'nextEvaluationDue' => $carePlan->next_evaluation_due?->toDateString(),
            'isOverdue' => $carePlan->isOverdue(),
            'versionCount' => $carePlan->versions_count ?? 0,
            'topics' => $carePlan->topics->map(fn(CarePlanTopicEntry $t): array => [
                'topicNumber' => $t->topic_number,
                'content' => $t->content,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array{number: int, label: string}>
     */
    private function topicCatalog(): array
    {
        return array_map(
            fn(CarePlanTopicEnum $t): array => ['number' => $t->value, 'label' => $t->label()],
            CarePlanTopicEnum::cases(),
        );
    }
}
