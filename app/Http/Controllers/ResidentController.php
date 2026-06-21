<?php

namespace App\Http\Controllers;

use App\Enums\ResidentStatus;
use App\Enums\Salutation;
use App\Models\Location;
use App\Models\Resident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ResidentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeResidentViewing($request);

        $locations = $this->residentViewLocations($request);
        $location = $this->selectedLocation($request, $locations)
            ?? ($locations->count() === 1 ? $locations->first() : null);

        $residents = $locations->isNotEmpty()
            ? Resident::query()
                ->whereIn('location_id', $location ? [$location->id] : $locations->pluck('id'))
                ->active()
                ->with('location')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Resident $resident): array => [
                    'id' => $resident->id,
                    'salutation' => $resident->salutation->value,
                    'fullName' => $resident->full_name,
                    'formalName' => $resident->formal_name,
                    'roomNumber' => $resident->room_number,
                    'careLevel' => $resident->care_level,
                    'locationName' => $resident->location?->name,
                ])
                ->values()
            : collect();

        return Inertia::render('Residents/Index', [
            'location' => $location ? $this->locationPayload($location) : null,
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
            'residents' => $residents,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeResidentManagement($request);

        $locations = $request->user()?->accessibleLocations() ?? collect();
        $location = $this->selectedLocation($request, $locations) ?? $locations->first();

        return Inertia::render('Residents/Create', [
            'location' => $location ? $this->locationPayload($location) : null,
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
            'salutations' => Salutation::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeResidentManagement($request);

        $user = $request->user();
        $locations = $user?->accessibleLocations() ?? collect();

        if ($locations->isEmpty()) {
            return to_route('residents.index')
                ->with('warning', 'Bitte ordne deinem Konto zuerst einen Wohnbereich zu.');
        }

        $validated = $request->validate([
            'location_id' => [$locations->count() > 1 ? 'required' : 'nullable', 'string', 'uuid'],
            ...$this->residentFieldRules(),
        ]);

        $locationId = (string) ($validated['location_id'] ?? $locations->first()->id);

        if (! $locations->contains('id', $locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Du hast keinen Zugriff auf diesen Wohnbereich.',
            ]);
        }

        unset($validated['location_id']);

        // Neuanlage = Aufnahme: Status anwesend, Aufnahmedatum default heute.
        $validated['status'] = ResidentStatus::Present->value;
        $validated['admitted_on'] = $validated['admitted_on'] ?? today()->toDateString();

        DB::transaction(function () use ($validated, $locationId): void {
            Resident::query()->create($validated + [
                'pseudonym' => Resident::generatePseudonym(),
                'location_id' => $locationId,
                'active' => true,
            ]);
        });

        return to_route('residents.index', ['location_id' => $locationId]);
    }

    public function edit(Request $request, Resident $resident): Response
    {
        $this->authorizeResidentManagement($request);
        $locations = $request->user()?->accessibleLocations() ?? collect();
        abort_unless($locations->contains('id', $resident->location_id), 403);

        return Inertia::render('Residents/Edit', [
            'resident' => [
                'id' => $resident->id,
                'salutation' => $resident->salutation->value,
                'locationId' => $resident->location_id,
                'firstName' => $resident->first_name,
                'lastName' => $resident->last_name,
                'fullName' => $resident->full_name,
                'birthDate' => $resident->birth_date?->toDateString(),
                'roomNumber' => $resident->room_number,
                'careLevel' => $resident->care_level,
                'status' => ($resident->status ?? ResidentStatus::Present)->value,
                'admittedOn' => $resident->admitted_on?->toDateString(),
                'dischargedOn' => $resident->discharged_on?->toDateString(),
                'healthInsurance' => $resident->health_insurance,
                'insuranceNumber' => $resident->insurance_number,
                'familyDoctor' => $resident->family_doctor,
                'familyDoctorPhone' => $resident->family_doctor_phone,
                'guardianName' => $resident->guardian_name,
                'guardianPhone' => $resident->guardian_phone,
                'hasLivingWill' => $resident->has_living_will,
                'hasHealthcareProxy' => $resident->has_healthcare_proxy,
                'allergies' => $resident->allergies,
                'diagnoses' => $resident->diagnoses,
            ],
            'locations' => $locations->map(fn (Location $location): array => $this->locationPayload($location))->values(),
            'salutations' => Salutation::options(),
            'statuses' => ResidentStatus::options(),
        ]);
    }

    public function update(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeResidentManagement($request);
        $locations = $request->user()?->accessibleLocations() ?? collect();
        abort_unless($locations->contains('id', $resident->location_id), 403);

        $validated = $request->validate([
            'location_id' => [$locations->count() > 1 ? 'required' : 'nullable', 'string', 'uuid'],
            'status' => ['sometimes', Rule::enum(ResidentStatus::class)],
            ...$this->residentFieldRules(),
        ]);

        $locationId = $locations->count() === 1
            ? $locations->first()->id
            : (string) ($validated['location_id'] ?? $resident->location_id);

        if (! $locations->contains('id', $locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Du hast keinen Zugriff auf diesen Wohnbereich.',
            ]);
        }

        unset($validated['location_id']);

        // Status steuert die Aktiv-Kennzeichnung (Entlassen/Verstorben = inaktiv).
        $status = isset($validated['status'])
            ? ResidentStatus::from($validated['status'])
            : ($resident->status ?? ResidentStatus::Present);
        $validated['active'] = $status->isActive();

        if (! $status->isActive() && empty($validated['discharged_on'])) {
            $validated['discharged_on'] = today()->toDateString();
        }

        $resident->update($validated + ['location_id' => $locationId]);

        return to_route('residents.index', ['location_id' => $locationId]);
    }

    private function authorizeResidentManagement(Request $request): void
    {
        abort_unless($request->user()?->hasRole('PDL'), 403);
    }

    private function authorizeResidentViewing(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['PDL', 'Pflegekraft']), 403);
    }

    /**
     * @return Collection<int, Location>
     */
    private function residentViewLocations(Request $request): Collection
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
     * @param  Collection<int, Location>  $locations
     */
    private function selectedLocation(Request $request, Collection $locations): ?Location
    {
        $locationId = $request->string('location_id')->toString();

        if ($locationId === '') {
            return null;
        }

        return $locations->firstWhere('id', $locationId);
    }

    /**
     * Gemeinsame Validierungsregeln für Stammdaten (ohne location_id/status).
     *
     * @return array<string, array<int, mixed>>
     */
    private function residentFieldRules(): array
    {
        return [
            'salutation' => ['required', Rule::enum(Salutation::class)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'care_level' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
            'admitted_on' => ['nullable', 'date', 'before_or_equal:today'],
            'discharged_on' => ['nullable', 'date', 'after_or_equal:admitted_on'],
            'health_insurance' => ['nullable', 'string', 'max:150'],
            'insurance_number' => ['nullable', 'string', 'max:100'],
            'family_doctor' => ['nullable', 'string', 'max:150'],
            'family_doctor_phone' => ['nullable', 'string', 'max:50'],
            'guardian_name' => ['nullable', 'string', 'max:150'],
            'guardian_phone' => ['nullable', 'string', 'max:50'],
            'has_living_will' => ['sometimes', 'boolean'],
            'has_healthcare_proxy' => ['sometimes', 'boolean'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'diagnoses' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array{id: string, name: string}
     */
    private function locationPayload(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
        ];
    }
}
