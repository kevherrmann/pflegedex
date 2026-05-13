<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use App\Enums\EmploymentArea;

class StaffController extends Controller
{
    /** @var list<string> */
    private const STAFF_ROLES = ['Pflegekraft', 'Putzkraft', 'Hausmeister'];

    public function index(Request $request): Response
    {
        $this->authorizePdl($request);

        $locations = $request->user()?->accessibleLocations() ?? collect();
        $locationIds = $locations->pluck('id')->all();

        return Inertia::render('Staff/Index', [
            'staffUsers' => empty($locationIds)
                ? []
                : User::query()
                    ->role(self::STAFF_ROLES)
                    ->where(function (Builder $query) use ($locationIds): void {
                        $query->whereIn('location_id', $locationIds)
                            ->orWhereHas('locations', fn(Builder $query) => $query->whereIn('locations.id', $locationIds));
                    })
                    ->with(['roles', 'location', 'locations', 'employeeProfile'])
                    ->orderBy('name')
                    ->get()
                    ->map(fn(User $user): array => $this->staffPayload($user))
                    ->values(),
            'locations' => $locations->map(fn(Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
            ])->values(),
            'roles' => self::STAFF_ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePdl($request);
        $validated = $this->validateStaff($request);

        DB::transaction(function () use ($validated): void {
            $locationIds = array_values(array_unique(array_map('strval', $validated['location_ids'])));

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'location_id' => $locationIds[0],
            ]);

            $user->assignRole($validated['role']);
            $user->locations()->sync($locationIds);
            $user->employeeProfile()->create($this->employeeProfileData($validated));
        });

        return to_route('staff.index');
    }

    public function edit(Request $request, User $staff): Response
    {
        $this->authorizePdl($request);
        $this->authorizeStaffAccess($request, $staff);

        $locations = $request->user()?->accessibleLocations() ?? collect();

        return Inertia::render('Staff/Edit', [
            'staffUser' => $this->staffPayload($staff->load(['roles', 'location', 'locations', 'employeeProfile'])),
            'locations' => $locations->map(fn(Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
            ])->values(),
            'roles' => self::STAFF_ROLES,
        ]);
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $this->authorizePdl($request);
        $this->authorizeStaffAccess($request, $staff);
        $validated = $this->validateStaff($request, $staff);

        DB::transaction(function () use ($staff, $validated): void {
            $locationIds = array_values(array_unique(array_map('strval', $validated['location_ids'])));

            $staff->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'location_id' => $locationIds[0],
                ...(!empty($validated['password']) ? ['password' => Hash::make($validated['password'])] : []),
            ])->save();

            $staff->syncRoles([$validated['role']]);
            $staff->locations()->sync($locationIds);
            $staff->employeeProfile()->updateOrCreate(
                ['user_id' => $staff->id],
                $this->employeeProfileData($validated),
            );
        });

        return to_route('staff.index');
    }

    /** @return array<string, mixed> */
    private function validateStaff(Request $request, ?User $staff = null): array
    {
        $accessibleLocationIds = $request->user()?->accessibleLocations()->pluck('id')->all() ?? [];

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff)],
            'password' => [$staff ? 'nullable' : 'required', 'string', Password::defaults()],
            'role' => ['required', 'string', Rule::in(self::STAFF_ROLES)],
            'location_ids' => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($accessibleLocationIds): void {
                    $submittedIds = is_array($value) ? array_map('strval', $value) : [];

                    if (!empty(array_diff($submittedIds, $accessibleLocationIds))) {
                        $fail('Bitte wähle nur Wohnbereiche aus, auf die du Zugriff hast.');
                    }
                },
            ],
            'location_ids.*' => ['string', 'uuid'],
            'is_nursing_specialist' => ['sometimes', 'boolean'],
            'weekly_hours' => ['sometimes', 'numeric', 'min:0', 'max:99.99'],
            'regular_work_days_per_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'annual_vacation_days' => ['sometimes', 'integer', 'min:0', 'max:366'],
            'vacation_days_carried_over' => ['sometimes', 'integer', 'min:0', 'max:366'],
            'overtime_minutes_balance' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
            'can_work_early' => ['sometimes', 'boolean'],
            'can_work_late' => ['sometimes', 'boolean'],
            'can_work_night' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return $validated;
    }

    private function authorizePdl(Request $request): void
    {
        abort_unless($request->user()?->hasRole('PDL'), 403);
    }

    private function authorizeStaffAccess(Request $request, User $staff): void
    {
        abort_unless($this->hasStaffRole($staff), 404);

        $accessibleLocationIds = $request->user()?->accessibleLocations()->pluck('id')->all() ?? [];
        $staffLocationIds = $staff->accessibleLocations()->pluck('id')->all();

        abort_unless(!empty(array_intersect($accessibleLocationIds, $staffLocationIds)), 403);
    }

    private function hasStaffRole(User $user): bool
    {
        return $user->hasAnyRole(self::STAFF_ROLES);
    }

    /** @return array<string, mixed> */
    private function staffPayload(User $user): array
    {
        $locationIds = $user->accessibleLocations()->pluck('id')->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'locationIds' => $locationIds,
            'locations' => Location::query()
                ->whereIn('id', $locationIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn(Location $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                ])
                ->values(),
            'employeeProfile' => $user->employeeProfile ? [
                'employmentArea' => $user->employeeProfile->employment_area->value,
                'employmentAreaLabel' => $user->employeeProfile->employment_area->label(),
                'isNursingSpecialist' => $user->employeeProfile->is_nursing_specialist,
                'weeklyHours' => $user->employeeProfile->weekly_hours,
                'regularWorkDaysPerWeek' => $user->employeeProfile->regular_work_days_per_week,
                'annualVacationDays' => $user->employeeProfile->annual_vacation_days,
                'vacationDaysCarriedOver' => $user->employeeProfile->vacation_days_carried_over,
                'overtimeMinutesBalance' => $user->employeeProfile->overtime_minutes_balance,
                'canWorkEarly' => $user->employeeProfile->can_work_early,
                'canWorkLate' => $user->employeeProfile->can_work_late,
                'canWorkNight' => $user->employeeProfile->can_work_night,
                'active' => $user->employeeProfile->active,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function employeeProfileData(array $validated): array
    {
        $employmentArea = $this->employmentAreaForRole((string) $validated['role']);

        return [
            'employment_area' => $employmentArea,
            'is_nursing_specialist' => $employmentArea === EmploymentArea::Nursing
                ? (bool) ($validated['is_nursing_specialist'] ?? false)
                : false,
            'weekly_hours' => $validated['weekly_hours'] ?? 39.00,
            'regular_work_days_per_week' => $validated['regular_work_days_per_week'] ?? null,
            'annual_vacation_days' => $validated['annual_vacation_days'] ?? 30,
            'vacation_days_carried_over' => $validated['vacation_days_carried_over'] ?? 0,
            'overtime_minutes_balance' => $validated['overtime_minutes_balance'] ?? 0,
            'can_work_early' => $validated['can_work_early'] ?? true,
            'can_work_late' => $validated['can_work_late'] ?? true,
            'can_work_night' => $validated['can_work_night'] ?? false,
            'active' => $validated['active'] ?? true,
        ];
    }

    private function employmentAreaForRole(string $role): EmploymentArea
    {
        return match ($role) {
            'Pflegekraft' => EmploymentArea::Nursing,
            'Putzkraft' => EmploymentArea::Cleaning,
            'Hausmeister' => EmploymentArea::Caretaker,
            default => EmploymentArea::Nursing,
        };
    }
}
