<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MedicationAdministrationStatus;
use App\Enums\MedicationForm;
use App\Enums\MedicationSlot;
use App\Models\Medication;
use App\Models\MedicationAdministration;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MedicationController extends Controller
{
    public function index(Request $request, Resident $resident): Response
    {
        $this->authorizeAccess($request, $resident);

        $date = $this->selectedDate($request);

        $medications = Medication::query()
            ->where('resident_id', $resident->id)
            ->where('active', true)
            ->with(['administrations' => function ($query) use ($date): void {
                $query->whereDate('administered_on', $date)
                    ->with(['administeredBy', 'witnessedBy'])
                    ->latest('administered_at');
            }])
            ->orderByDesc('is_btm')
            ->orderBy('name')
            ->get()
            ->map(fn (Medication $m): array => $this->medicationPayload($m))
            ->values();

        return Inertia::render('Medications/Index', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ],
            'medications' => $medications,
            'selectedDate' => $date,
            'forms' => collect(MedicationForm::cases())
                ->map(fn (MedicationForm $f): array => ['value' => $f->value, 'label' => $f->label()])
                ->values(),
            'slots' => collect(MedicationSlot::cases())
                ->map(fn (MedicationSlot $s): array => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'statuses' => collect(MedicationAdministrationStatus::cases())
                ->map(fn (MedicationAdministrationStatus $s): array => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'staff' => $this->accessibleStaff($request, $resident),
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'form' => ['required', Rule::enum(MedicationForm::class)],
            'strength' => ['nullable', 'string', 'max:60'],
            'dose_morning' => ['nullable', 'string', 'max:30'],
            'dose_noon' => ['nullable', 'string', 'max:30'],
            'dose_evening' => ['nullable', 'string', 'max:30'],
            'dose_night' => ['nullable', 'string', 'max:30'],
            'prn' => ['sometimes', 'boolean'],
            'prn_instruction' => ['nullable', 'string', 'max:2000'],
            'is_btm' => ['sometimes', 'boolean'],
            'prescriber' => ['nullable', 'string', 'max:150'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        Medication::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'name' => $validated['name'],
            'form' => $validated['form'],
            'strength' => $validated['strength'] ?? null,
            'dose_morning' => $validated['dose_morning'] ?? null,
            'dose_noon' => $validated['dose_noon'] ?? null,
            'dose_evening' => $validated['dose_evening'] ?? null,
            'dose_night' => $validated['dose_night'] ?? null,
            'prn' => (bool) ($validated['prn'] ?? false),
            'prn_instruction' => $validated['prn_instruction'] ?? null,
            'is_btm' => (bool) ($validated['is_btm'] ?? false),
            'prescriber' => $validated['prescriber'] ?? null,
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
            'note' => $validated['note'] ?? null,
            'active' => true,
            'created_by' => $request->user()->id,
        ]);

        return to_route('residents.medications.index', $resident);
    }

    public function destroy(Request $request, Resident $resident, Medication $medication): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($medication->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        // Absetzen = deaktivieren: der Verabreichungsnachweis bleibt erhalten.
        $medication->update(['active' => false]);

        return to_route('residents.medications.index', $resident);
    }

    public function administer(Request $request, Resident $resident, Medication $medication): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($medication->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'administered_on' => ['required', 'date', 'before_or_equal:today'],
            'slot' => ['required', Rule::enum(MedicationSlot::class)],
            'status' => ['required', Rule::enum(MedicationAdministrationStatus::class)],
            'witness_by' => ['nullable', 'uuid', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = MedicationAdministrationStatus::from($validated['status']);
        $witnessId = $validated['witness_by'] ?? null;

        // BTM + tatsächliche Gabe -> Vier-Augen-Prinzip: Zweitkraft verpflichtend.
        if ($medication->is_btm && $status === MedicationAdministrationStatus::Administered) {
            if ($witnessId === null) {
                throw ValidationException::withMessages([
                    'witness_by' => 'Bei BTM-Gabe ist eine Zweitkraft (Zeuge) verpflichtend.',
                ]);
            }

            if ($witnessId === $request->user()->id) {
                throw ValidationException::withMessages([
                    'witness_by' => 'Die Zweitkraft muss eine andere Person als die abgebende sein.',
                ]);
            }
        }

        // Wenn eine Zweitkraft angegeben ist, muss sie Pflegepersonal im Wohnbereich sein.
        if ($witnessId !== null) {
            $witness = User::query()->find($witnessId);
            abort_if($witness === null, HttpResponse::HTTP_UNPROCESSABLE_ENTITY);

            if (! $witness->hasAnyRole(['PDL', 'Pflegekraft']) || ! $witness->canAccessLocation($resident->location_id)) {
                throw ValidationException::withMessages([
                    'witness_by' => 'Die Zweitkraft muss Pflegepersonal des Wohnbereichs sein.',
                ]);
            }
        }

        MedicationAdministration::query()->create([
            'medication_id' => $medication->id,
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'administered_on' => $validated['administered_on'],
            'slot' => $validated['slot'],
            'status' => $status,
            'administered_by' => $request->user()->id,
            'administered_at' => now(),
            'witness_by' => $witnessId,
            'note' => $validated['note'] ?? null,
        ]);

        return to_route('residents.medications.index', [
            'resident' => $resident->id,
            'date' => Carbon::parse($validated['administered_on'])->toDateString(),
        ]);
    }

    public function destroyAdministration(Request $request, Resident $resident, MedicationAdministration $administration): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);
        abort_unless($administration->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $date = $administration->administered_on->toDateString();
        $administration->delete();

        return to_route('residents.medications.index', [
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

    /**
     * @return list<array{id: string, name: string}>
     */
    private function accessibleStaff(Request $request, Resident $resident): array
    {
        return User::query()
            ->where('location_id', $resident->location_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['PDL', 'Pflegekraft']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function medicationPayload(Medication $medication): array
    {
        return [
            'id' => $medication->id,
            'name' => $medication->name,
            'form' => $medication->form->value,
            'formLabel' => $medication->form->label(),
            'strength' => $medication->strength,
            'scheme' => [
                'morning' => $medication->dose_morning,
                'noon' => $medication->dose_noon,
                'evening' => $medication->dose_evening,
                'night' => $medication->dose_night,
            ],
            'prn' => $medication->prn,
            'prnInstruction' => $medication->prn_instruction,
            'isBtm' => $medication->is_btm,
            'prescriber' => $medication->prescriber,
            'startsOn' => $medication->starts_on?->format('d.m.Y'),
            'endsOn' => $medication->ends_on?->format('d.m.Y'),
            'note' => $medication->note,
            'administrations' => $medication->administrations
                ->map(fn (MedicationAdministration $a): array => [
                    'id' => $a->id,
                    'slot' => $a->slot->value,
                    'slotLabel' => $a->slot->label(),
                    'status' => $a->status->value,
                    'statusLabel' => $a->status->label(),
                    'administeredByName' => $a->administeredBy?->name,
                    'witnessName' => $a->witnessedBy?->name,
                    'administeredAt' => $a->administered_at->format('H:i'),
                    'note' => $a->note,
                ])
                ->values(),
        ];
    }
}
