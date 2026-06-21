<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Resident;
use App\Models\VitalSign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VitalSignController extends Controller
{
    /** Messwert-Felder mit erlaubten Wertebereichen (fachlich/medizinisch plausibel). */
    private const MEASUREMENTS = [
        'systolic' => ['integer', 'min:40', 'max:300'],
        'diastolic' => ['integer', 'min:20', 'max:200'],
        'pulse' => ['integer', 'min:20', 'max:300'],
        'respiratory_rate' => ['integer', 'min:4', 'max:80'],
        'oxygen_saturation' => ['integer', 'min:50', 'max:100'],
        'blood_sugar' => ['integer', 'min:10', 'max:1000'],
        'temperature' => ['numeric', 'min:30', 'max:45'],
        'weight' => ['numeric', 'min:1', 'max:400'],
    ];

    public function index(Request $request, Resident $resident): Response
    {
        $this->authorizeAccess($request, $resident);

        $vitalSigns = VitalSign::query()
            ->where('resident_id', $resident->id)
            ->with('recorder')
            ->latest('measured_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (VitalSign $vital): array => $this->payload($vital))
            ->values();

        return Inertia::render('Vitals/Index', [
            'resident' => [
                'id' => $resident->id,
                'fullName' => $resident->full_name,
                'locationName' => $resident->location?->name,
            ],
            'vitalSigns' => $vitalSigns,
        ]);
    }

    public function store(Request $request, Resident $resident): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        $rules = [
            'measured_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (self::MEASUREMENTS as $field => $fieldRules) {
            $rules[$field] = array_merge(['nullable'], $fieldRules);
        }

        $validated = $request->validate($rules);

        // Mindestens ein Messwert muss erfasst sein (sonst leerer Eintrag).
        $hasMeasurement = collect(array_keys(self::MEASUREMENTS))
            ->contains(fn (string $field): bool => ($validated[$field] ?? null) !== null);

        if (! $hasMeasurement) {
            throw ValidationException::withMessages([
                'systolic' => 'Bitte mindestens einen Messwert erfassen.',
            ]);
        }

        VitalSign::query()->create([
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'recorded_by' => $request->user()->id,
            'measured_at' => $validated['measured_at'],
            'systolic' => $validated['systolic'] ?? null,
            'diastolic' => $validated['diastolic'] ?? null,
            'pulse' => $validated['pulse'] ?? null,
            'respiratory_rate' => $validated['respiratory_rate'] ?? null,
            'oxygen_saturation' => $validated['oxygen_saturation'] ?? null,
            'blood_sugar' => $validated['blood_sugar'] ?? null,
            'temperature' => $validated['temperature'] ?? null,
            'weight' => $validated['weight'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        return to_route('residents.vitals.index', $resident);
    }

    public function destroy(Request $request, Resident $resident, VitalSign $vitalSign): RedirectResponse
    {
        $this->authorizeAccess($request, $resident);

        // Objektbezug pruefen: der Messwert muss zum Bewohner gehoeren.
        abort_unless($vitalSign->resident_id === $resident->id, HttpResponse::HTTP_NOT_FOUND);

        $vitalSign->delete();

        return to_route('residents.vitals.index', $resident);
    }

    private function authorizeAccess(Request $request, Resident $resident): void
    {
        $user = $request->user();

        abort_unless($user?->hasAnyRole(['PDL', 'Pflegekraft']), HttpResponse::HTTP_FORBIDDEN);
        abort_unless($user->canAccessLocation($resident->location_id), HttpResponse::HTTP_FORBIDDEN);
    }

    /** @return array<string, mixed> */
    private function payload(VitalSign $vital): array
    {
        return [
            'id' => $vital->id,
            'measuredAt' => $vital->measured_at->format('d.m.Y H:i'),
            'systolic' => $vital->systolic,
            'diastolic' => $vital->diastolic,
            'pulse' => $vital->pulse,
            'respiratoryRate' => $vital->respiratory_rate,
            'oxygenSaturation' => $vital->oxygen_saturation,
            'bloodSugar' => $vital->blood_sugar,
            'temperature' => $vital->temperature,
            'weight' => $vital->weight,
            'note' => $vital->note,
            'recordedByName' => $vital->recorder?->name,
        ];
    }
}
