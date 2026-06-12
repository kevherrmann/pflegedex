<?php

namespace App\Http\Controllers;

use App\Enums\BlackoutScope;
use App\Enums\QualificationLevel;
use App\Models\Location;
use App\Models\RosterBlackoutDay;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RosterBlackoutDayController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        return Inertia::render('RosterBlackoutDays/Index', [
            'blackoutDays' => RosterBlackoutDay::query()
                ->with(['location', 'createdBy', 'employees'])
                ->orderByDesc('date')
                ->get()
                ->map(fn (RosterBlackoutDay $blackoutDay): array => [
                    'id' => $blackoutDay->id,
                    'locationName' => $blackoutDay->location?->name,
                    'date' => $blackoutDay->date->toDateString(),
                    'reason' => $blackoutDay->reason,
                    'scope' => $blackoutDay->scope->value,
                    'scopeLabel' => $blackoutDay->scope->label(),
                    'qualificationLabels' => collect($blackoutDay->qualification_levels ?? [])
                        ->map(fn (string $value): string => QualificationLevel::from($value)->label())
                        ->values(),
                    'employeeNames' => $blackoutDay->employees->pluck('name')->values(),
                    'blocksVacation' => $blackoutDay->blocks_vacation,
                    'blocksOvertimeCompensation' => $blackoutDay->blocks_overtime_compensation,
                    'createdByName' => $blackoutDay->createdBy?->name,
                    'createdAt' => $blackoutDay->created_at?->toDateString(),
                ])
                ->values(),
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                ])
                ->values(),
            'qualificationLevels' => collect(QualificationLevel::cases())
                ->map(fn (QualificationLevel $level): array => [
                    'value' => $level->value,
                    'label' => $level->label(),
                ])
                ->values(),
            // Mitarbeiter mit Profil, damit das Formular gezielt einzelne Personen sperren kann.
            'staff' => User::query()
                ->whereHas('employeeProfile')
                ->with('employeeProfile')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'locationId' => $user->location_id,
                    'qualificationLabel' => $user->employeeProfile?->qualification_level?->label(),
                    'areaLabel' => $user->employeeProfile?->employment_area?->label(),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'date' => [
                'required',
                'date',
                // whereDate statt Rule::unique, damit der Vergleich unabhaengig vom
                // Speicherformat der Datumsspalte (PostgreSQL date vs. SQLite Text) greift.
                function (string $attribute, mixed $value, callable $fail) use ($request): void {
                    $exists = RosterBlackoutDay::query()
                        ->where('location_id', $request->input('location_id'))
                        ->whereDate('date', $value)
                        ->exists();

                    if ($exists) {
                        $fail('Für diesen Wohnbereich gibt es an diesem Datum bereits eine Urlaubssperre.');
                    }
                },
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
            'scope' => ['sometimes', Rule::enum(BlackoutScope::class)],
            'qualification_levels' => [
                'array',
                Rule::requiredIf(fn (): bool => $request->input('scope') === BlackoutScope::Qualification->value),
            ],
            'qualification_levels.*' => [Rule::enum(QualificationLevel::class)],
            'employee_ids' => [
                'array',
                Rule::requiredIf(fn (): bool => $request->input('scope') === BlackoutScope::Employees->value),
            ],
            'employee_ids.*' => [
                'uuid',
                Rule::exists('users', 'id')->where('location_id', $request->input('location_id')),
            ],
            'blocks_vacation' => ['sometimes', 'boolean'],
            'blocks_overtime_compensation' => ['sometimes', 'boolean'],
        ], [
            'employee_ids.required' => 'Bitte wähle mindestens einen Mitarbeiter aus.',
            'qualification_levels.required' => 'Bitte wähle mindestens eine Qualifikationsstufe aus.',
        ]);

        $scope = BlackoutScope::tryFrom($validated['scope'] ?? BlackoutScope::All->value) ?? BlackoutScope::All;

        $blackoutDay = RosterBlackoutDay::query()->create([
            'location_id' => $validated['location_id'],
            'date' => $validated['date'],
            'scope' => $scope,
            'qualification_levels' => $scope === BlackoutScope::Qualification
                ? array_values($validated['qualification_levels'] ?? [])
                : null,
            'reason' => $validated['reason'] ?? null,
            'blocks_vacation' => array_key_exists('blocks_vacation', $validated)
                ? $validated['blocks_vacation']
                : true,
            'blocks_overtime_compensation' => array_key_exists('blocks_overtime_compensation', $validated)
                ? $validated['blocks_overtime_compensation']
                : true,
            'created_by' => $request->user()->id,
        ]);

        if ($scope === BlackoutScope::Employees) {
            $blackoutDay->employees()->sync(array_values($validated['employee_ids'] ?? []));
        }

        return back()->with('status', 'roster-blackout-day-created');
    }
}
