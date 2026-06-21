<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CareTaskCategory;
use App\Enums\CareTaskCompletionStatus;
use App\Models\CareTask;
use App\Models\CareTaskCompletion;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CareTaskController extends Controller
{
    public function index(Request $request, Resident $resident): Response
    {
        $this->authorizeAccess($request, $resident);

        $date = $this->selectedDate($request);

        $tasks = CareTask::query()
            ->where('resident_id', $resident->id)
            ->where('active', true)
            ->with(['completions' => function ($query) use ($date): void {
                $query->whereDate('performed_on', $date)->with('performer')->latest('performed_at');
            }])
            ->orderBy('category')
            ->orderBy('title')
            ->get()
            ->map(fn (CareTask $task): array => $this->taskPayload($task))
            ->values();

        return Inertia::render('CareTasks/Index', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ],
            'tasks' => $tasks,
            'selectedDate' => $date,
            'categories' => collect(CareTaskCategory::cases())
                ->map(fn (CareTaskCategory $c): array => ['value' => $c->value, 'label' => $c->label()])
                ->values(),
            'statuses' => collect(CareTaskCompletionStatus::cases())
                ->map(fn (CareTaskCompletionStatus $s): array => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::enum(CareTaskCategory::class)],
            'schedule' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        CareTask::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'title' => $validated['title'],
            'category' => $validated['category'],
            'schedule' => $validated['schedule'] ?? null,
            'description' => $validated['description'] ?? null,
            'active' => true,
            'created_by' => $request->user()->id,
        ]);

        return to_route('residents.care-tasks.index', $resident);
    }

    public function destroy(Request $request, Resident $resident, CareTask $careTask): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($careTask->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        // Deaktivieren statt loeschen: erbrachte Leistungen (Nachweis) bleiben erhalten.
        $careTask->update(['active' => false]);

        return to_route('residents.care-tasks.index', $resident);
    }

    public function complete(Request $request, Resident $resident, CareTask $careTask): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($careTask->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'performed_on' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::enum(CareTaskCompletionStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        CareTaskCompletion::query()->create([
            'care_task_id' => $careTask->id,
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'performed_on' => $validated['performed_on'],
            'status' => $validated['status'],
            'note' => $validated['note'] ?? null,
            'performed_by' => $request->user()->id,
            'performed_at' => now(),
        ]);

        return to_route('residents.care-tasks.index', [
            'resident' => $resident->id,
            'date' => Carbon::parse($validated['performed_on'])->toDateString(),
        ]);
    }

    public function destroyCompletion(Request $request, Resident $resident, CareTaskCompletion $completion): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($completion->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $date = $completion->performed_on->toDateString();
        $completion->delete();

        return to_route('residents.care-tasks.index', [
            'resident' => $resident->id,
            'date' => $date,
        ]);
    }

    private function authorizeAccess(Request $request, Resident $resident): void
    {
        $user = $request->user();

        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), HttpResponse::HTTP_FORBIDDEN);
        abort_unless($user->canAccessLocation($resident->location_id), HttpResponse::HTTP_FORBIDDEN);
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

    /** @return array<string, mixed> */
    private function taskPayload(CareTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'category' => $task->category->value,
            'categoryLabel' => $task->category->label(),
            'schedule' => $task->schedule,
            'description' => $task->description,
            'completions' => $task->completions
                ->map(fn (CareTaskCompletion $c): array => [
                    'id' => $c->id,
                    'status' => $c->status->value,
                    'statusLabel' => $c->status->label(),
                    'note' => $c->note,
                    'performedByName' => $c->performer?->name,
                    'performedAt' => $c->performed_at->format('H:i'),
                ])
                ->values(),
        ];
    }
}
