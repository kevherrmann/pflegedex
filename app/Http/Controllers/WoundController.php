<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WoundStage;
use App\Enums\WoundStatus;
use App\Enums\WoundType;
use App\Models\Resident;
use App\Models\Wound;
use App\Models\WoundAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WoundController extends Controller
{
    public function index(Request $request, Resident $resident): Response
    {
        $this->authorizeAccess($request, $resident);

        $wounds = Wound::query()
            ->where('resident_id', $resident->id)
            ->with([
                'creator',
                'assessments' => fn ($q) => $q->with('assessor')->latest('assessed_on')->latest('id'),
            ])
            ->orderByRaw("CASE WHEN status = 'healed' THEN 1 ELSE 0 END")
            ->latest('opened_on')
            ->get()
            ->map(fn (Wound $w): array => $this->woundPayload($w))
            ->values();

        return Inertia::render('Wounds/Index', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ],
            'wounds' => $wounds,
            'types' => WoundType::options(),
            'statuses' => WoundStatus::options(),
            'stages' => WoundStage::options(),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        $validated = $request->validate([
            'body_site' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(WoundType::class)],
            'acquired_in_house' => ['sometimes', 'boolean'],
            'opened_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        Wound::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'body_site' => $validated['body_site'],
            'type' => $validated['type'],
            'acquired_in_house' => (bool) ($validated['acquired_in_house'] ?? false),
            'opened_on' => $validated['opened_on'],
            'status' => WoundStatus::Open->value,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return to_route('residents.wounds.index', $resident);
    }

    public function updateStatus(Request $request, Resident $resident, Wound $wound): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($wound->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(WoundStatus::class)],
        ]);

        $status = WoundStatus::from($validated['status']);

        $wound->update([
            'status' => $status,
            // Abgeheilt -> Abschlussdatum setzen; wieder offen/abheilend -> zurücksetzen.
            'closed_on' => $status === WoundStatus::Healed ? ($wound->closed_on ?? today()) : null,
        ]);

        return to_route('residents.wounds.index', $resident);
    }

    public function destroy(Request $request, Resident $resident, Wound $wound): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($wound->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        // Wunden mit Verlaufseinträgen bleiben revisionssicher erhalten (nur abheilen).
        if ($wound->assessments()->exists()) {
            return to_route('residents.wounds.index', $resident)
                ->with('warning', 'Wunden mit Verlaufseinträgen können nicht gelöscht, nur als abgeheilt markiert werden.');
        }

        $wound->delete();

        return to_route('residents.wounds.index', $resident);
    }

    public function addAssessment(Request $request, Resident $resident, Wound $wound): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($wound->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'assessed_on' => ['required', 'date', 'before_or_equal:today'],
            'stage' => ['nullable', Rule::enum(WoundStage::class)],
            'length_mm' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'width_mm' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'depth_mm' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'pain' => ['nullable', 'integer', 'min:0', 'max:10'],
            'wound_description' => ['nullable', 'string', 'max:2000'],
            'measures' => ['nullable', 'string', 'max:2000'],
        ]);

        WoundAssessment::query()->create([
            'wound_id' => $wound->id,
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'assessed_on' => $validated['assessed_on'],
            'stage' => $validated['stage'] ?? null,
            'length_mm' => $validated['length_mm'] ?? null,
            'width_mm' => $validated['width_mm'] ?? null,
            'depth_mm' => $validated['depth_mm'] ?? null,
            'pain' => $validated['pain'] ?? null,
            'wound_description' => $validated['wound_description'] ?? null,
            'measures' => $validated['measures'] ?? null,
            'assessed_by' => $request->user()->id,
        ]);

        return to_route('residents.wounds.index', $resident);
    }

    public function destroyAssessment(Request $request, Resident $resident, WoundAssessment $woundAssessment): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($woundAssessment->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $woundAssessment->delete();

        return to_route('residents.wounds.index', $resident);
    }

    private function authorizeAccess(Request $request, Resident $resident): void
    {
        $user = $request->user();

        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), HttpResponse::HTTP_FORBIDDEN);
        abort_unless($user->canAccessLocation($resident->location_id), HttpResponse::HTTP_FORBIDDEN);
    }

    /** @return array<string, mixed> */
    private function woundPayload(Wound $wound): array
    {
        return [
            'id' => $wound->id,
            'bodySite' => $wound->body_site,
            'type' => $wound->type->value,
            'typeLabel' => $wound->type->label(),
            'acquiredInHouse' => $wound->acquired_in_house,
            'openedOn' => $wound->opened_on->format('d.m.Y'),
            'closedOn' => $wound->closed_on?->format('d.m.Y'),
            'status' => $wound->status->value,
            'statusLabel' => $wound->status->label(),
            'note' => $wound->note,
            'createdByName' => $wound->creator?->name,
            'assessments' => $wound->assessments
                ->map(fn (WoundAssessment $a): array => [
                    'id' => $a->id,
                    'assessedOn' => $a->assessed_on->format('d.m.Y'),
                    'stage' => $a->stage?->value,
                    'stageLabel' => $a->stage?->label(),
                    'lengthMm' => $a->length_mm,
                    'widthMm' => $a->width_mm,
                    'depthMm' => $a->depth_mm,
                    'pain' => $a->pain,
                    'woundDescription' => $a->wound_description,
                    'measures' => $a->measures,
                    'assessedByName' => $a->assessor?->name,
                ])
                ->values(),
        ];
    }
}
