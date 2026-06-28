<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\Location;
use App\Models\User;
use App\Services\Absences\AbsenceRequestService;
use App\Services\Absences\VacationBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AbsenceRequestController extends Controller
{
    public function index(
        Request $request,
        VacationBalanceService $vacationBalanceService,
        AbsenceRequestService $absenceRequestService,
    ): Response {
        $user = $request->user();

        abort_unless($user?->canRequestAbsence(), HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('AbsenceRequests/Index', [
            'absenceRequests' => AbsenceRequest::query()
                ->where('user_id', $user->id)
                ->with('user.employeeProfile')
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AbsenceRequest $absenceRequest): array => [
                    'id' => $absenceRequest->id,
                    'type' => $absenceRequest->type->value,
                    'typeLabel' => $absenceRequest->type->label(),
                    'startsOn' => $absenceRequest->starts_on->toDateString(),
                    'endsOn' => $absenceRequest->ends_on->toDateString(),
                    'daysCount' => $absenceRequest->days_count,
                    'status' => $absenceRequest->status->value,
                    'statusLabel' => $absenceRequest->status->label(),
                    'note' => $absenceRequest->note,
                    'rejectionReason' => $absenceRequest->rejection_reason,
                    'overrideReason' => $absenceRequest->override_reason,
                    'hitsBlackout' => $absenceRequestService->hitsBlackout($absenceRequest),
                    'createdAt' => $absenceRequest->created_at?->toDateString(),
                ])
                ->values(),
            'canRequestAbsence' => true,
            'vacationBalance' => $vacationBalanceService->forUser($user),
            'absenceTypes' => [
                [
                    'value' => AbsenceRequestType::Vacation->value,
                    'label' => AbsenceRequestType::Vacation->label(),
                ],
                [
                    'value' => AbsenceRequestType::OvertimeCompensation->value,
                    'label' => AbsenceRequestType::OvertimeCompensation->label(),
                ],
            ],
        ]);
    }

    public function store(Request $request, AbsenceRequestService $absenceRequestService): RedirectResponse
    {
        $validated = $request->validate([
            'type' => [
                'required',
                'string',
                Rule::in([
                    AbsenceRequestType::Vacation->value,
                    AbsenceRequestType::OvertimeCompensation->value,
                ]),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'days_count' => ['nullable', 'numeric', 'min:0.5', 'max:366'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $absenceRequestService->request(
            employee: $request->user(),
            requestedBy: $request->user(),
            data: $validated,
        );

        return back()->with('status', 'absence-request-created');
    }

    public function manage(Request $request, AbsenceRequestService $absenceRequestService): Response
    {
        $user = $request->user();

        abort_unless($user?->hasRole('PDL'), HttpResponse::HTTP_FORBIDDEN);

        $locations = $user->accessibleLocations();
        $locationIds = $locations->pluck('id')->all();

        // Nur Mitarbeiter, die ueberhaupt Abwesenheiten haben koennen (Pflege + Reinigung).
        $absenceAreas = [EmploymentArea::Nursing->value, EmploymentArea::Cleaning->value];

        $employees = User::query()
            ->with('employeeProfile')
            ->whereIn('location_id', $locationIds)
            ->whereHas('employeeProfile', fn ($query) => $query->whereIn('employment_area', $absenceAreas))
            ->orderBy('name')
            ->get();

        $employeeIds = $employees->pluck('id')->all();

        $monthStart = $this->resolveTimelineMonth($request, $employeeIds);
        $monthEnd = $monthStart->endOfMonth();

        // Bestaetigte und offene Abwesenheiten, die in den Monat hineinragen.
        $absencesByEmployee = AbsenceRequest::query()
            ->with(['requestedBy', 'decidedBy', 'user.employeeProfile'])
            ->whereIn('user_id', $employeeIds)
            ->whereIn('status', [AbsenceRequestStatus::Requested->value, AbsenceRequestStatus::Approved->value])
            ->whereDate('starts_on', '<=', $monthEnd->toDateString())
            ->whereDate('ends_on', '>=', $monthStart->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->groupBy('user_id');

        $groups = $locations
            ->sortBy('name')
            ->map(fn (Location $location): array => [
                'locationId' => $location->id,
                'locationName' => $location->name,
                'employees' => $employees
                    ->where('location_id', $location->id)
                    ->values()
                    ->map(fn (User $employee): array => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employmentAreaLabel' => $employee->employeeProfile?->employment_area?->label(),
                        'qualificationLabel' => $employee->employeeProfile?->qualification_level?->label(),
                        'absences' => ($absencesByEmployee->get($employee->id) ?? collect())
                            ->map(fn (AbsenceRequest $absence): array => $this->timelineAbsence(
                                $absence,
                                $monthStart,
                                $monthEnd,
                                $absenceRequestService->hitsBlackout($absence),
                            ))
                            ->values(),
                    ])
                    ->values(),
            ])
            ->values();

        $openRequestsCount = AbsenceRequest::query()
            ->whereIn('user_id', $employeeIds)
            ->where('status', AbsenceRequestStatus::Requested->value)
            ->count();

        return Inertia::render('AbsenceRequests/Manage', [
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $this->germanMonthLabel($monthStart),
            'prevMonth' => $monthStart->subMonth()->format('Y-m'),
            'nextMonth' => $monthStart->addMonth()->format('Y-m'),
            'currentMonth' => CarbonImmutable::now()->format('Y-m'),
            'today' => CarbonImmutable::now()->toDateString(),
            'days' => $this->buildMonthDays($monthStart),
            'groups' => $groups,
            'openRequestsCount' => $openRequestsCount,
        ]);
    }

    /**
     * Ermittelt den anzuzeigenden Monat: explizit per Query, sonst der aktuelle
     * Monat. Liegt dort keine Abwesenheit, springt die Ansicht zum naechsten
     * anstehenden bzw. zuletzt geplanten Antrag, damit die Timeline nicht leer ist.
     *
     * @param  list<string>  $employeeIds
     */
    private function resolveTimelineMonth(Request $request, array $employeeIds): CarbonImmutable
    {
        $requested = $request->query('month');

        if (is_string($requested) && preg_match('/^\d{4}-\d{2}$/', $requested)) {
            [$year, $month] = array_map('intval', explode('-', $requested));

            if ($month >= 1 && $month <= 12) {
                return CarbonImmutable::create($year, $month, 1)->startOfDay();
            }
        }

        $current = CarbonImmutable::now()->startOfMonth();

        if ($employeeIds === []) {
            return $current;
        }

        $relevantStatuses = [AbsenceRequestStatus::Requested->value, AbsenceRequestStatus::Approved->value];

        $overlapsCurrent = AbsenceRequest::query()
            ->whereIn('user_id', $employeeIds)
            ->whereIn('status', $relevantStatuses)
            ->whereDate('starts_on', '<=', $current->endOfMonth()->toDateString())
            ->whereDate('ends_on', '>=', $current->toDateString())
            ->exists();

        if ($overlapsCurrent) {
            return $current;
        }

        $today = CarbonImmutable::now()->toDateString();

        $upcoming = AbsenceRequest::query()
            ->whereIn('user_id', $employeeIds)
            ->whereIn('status', $relevantStatuses)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('starts_on')
            ->value('starts_on');

        if ($upcoming !== null) {
            return CarbonImmutable::parse($upcoming)->startOfMonth();
        }

        $latest = AbsenceRequest::query()
            ->whereIn('user_id', $employeeIds)
            ->whereIn('status', $relevantStatuses)
            ->orderByDesc('starts_on')
            ->value('starts_on');

        return $latest !== null
            ? CarbonImmutable::parse($latest)->startOfMonth()
            : $current;
    }

    /**
     * @return list<array{day: int, date: string, weekdayShort: string, isWeekend: bool}>
     */
    private function buildMonthDays(CarbonImmutable $monthStart): array
    {
        $weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $monthEnd = $monthStart->endOfMonth();
        $days = [];

        for ($cursor = $monthStart; $cursor->lte($monthEnd); $cursor = $cursor->addDay()) {
            $iso = $cursor->isoWeekday();
            $days[] = [
                'day' => $cursor->day,
                'date' => $cursor->toDateString(),
                'weekdayShort' => $weekdays[$iso - 1],
                'isWeekend' => $iso >= 6,
            ];
        }

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineAbsence(AbsenceRequest $absence, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, bool $hitsBlackout): array
    {
        $starts = CarbonImmutable::parse($absence->starts_on);
        $ends = CarbonImmutable::parse($absence->ends_on);

        $clampedStart = $starts->lt($monthStart) ? $monthStart : $starts;
        $clampedEnd = $ends->gt($monthEnd) ? $monthEnd : $ends;

        return [
            'id' => $absence->id,
            'type' => $absence->type->value,
            'typeLabel' => $absence->type->label(),
            'status' => $absence->status->value,
            'statusLabel' => $absence->status->label(),
            'startsOn' => $starts->toDateString(),
            'endsOn' => $ends->toDateString(),
            'startDay' => $clampedStart->day,
            'endDay' => $clampedEnd->day,
            'continuesBefore' => $starts->lt($monthStart),
            'continuesAfter' => $ends->gt($monthEnd),
            'daysCount' => $absence->days_count,
            'note' => $absence->note,
            'hitsBlackout' => $hitsBlackout,
            'overrideReason' => $absence->override_reason,
            'requestedByName' => $absence->requestedBy?->name,
            'decidedByName' => $absence->decidedBy?->name,
            'decidedAt' => $absence->decided_at?->toDateTimeString(),
        ];
    }

    private function germanMonthLabel(CarbonImmutable $monthStart): string
    {
        $months = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];

        return $months[$monthStart->month].' '.$monthStart->year;
    }

    public function approve(
        Request $request,
        AbsenceRequest $absenceRequest,
        AbsenceRequestService $absenceRequestService,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        // Standort-Scope: PDL darf nur Antraege aus eigenen Wohnbereichen entscheiden
        // (sonst Zugriff auf fremde Bereiche moeglich).
        abort_unless(
            $request->user()->canAccessLocation($absenceRequest->location_id),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $absenceRequestService->approve(
            $absenceRequest,
            $request->user(),
            $validated['override_reason'] ?? null,
        );

        return back()->with('status', 'absence-request-approved');
    }

    /**
     * Krankmeldung durch die PDL: erfasst sofort eine genehmigte Krank-
     * Abwesenheit für eine Pflegekraft und räumt deren Dienste frei. Die
     * Vertretung wird anschließend manuell über die Vertretungssuche besetzt.
     */
    public function reportSick(
        Request $request,
        AbsenceRequestService $absenceRequestService,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = User::query()->with('employeeProfile')->findOrFail($validated['user_id']);

        // Standort-Scope: PDL darf nur eigene Wohnbereiche krankmelden.
        abort_unless(
            $request->user()->canAccessLocation($employee->location_id),
            HttpResponse::HTTP_FORBIDDEN,
        );

        abort_unless(
            $employee->employeeProfile?->employment_area === EmploymentArea::Nursing,
            HttpResponse::HTTP_FORBIDDEN,
        );

        $absenceRequestService->reportSick(
            employee: $employee,
            reportedBy: $request->user(),
            data: $validated,
        );

        return back()->with('status', 'absence-sick-reported');
    }

    public function reject(
        Request $request,
        AbsenceRequest $absenceRequest,
        AbsenceRequestService $absenceRequestService,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        // Standort-Scope: PDL darf nur Antraege aus eigenen Wohnbereichen entscheiden
        // (sonst Zugriff auf fremde Bereiche moeglich).
        abort_unless(
            $request->user()->canAccessLocation($absenceRequest->location_id),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $absenceRequestService->reject(
            $absenceRequest,
            $request->user(),
            $validated['rejection_reason'],
        );

        return back()->with('status', 'absence-request-rejected');
    }
}
