<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceRequestType;
use App\Services\Absences\AbsenceRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\AbsenceRequest;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use App\Enums\AbsenceRequestStatus;
use App\Models\User;
use App\Services\Absences\VacationBalanceService;

class AbsenceRequestController extends Controller
{
    public function index(Request $request, VacationBalanceService $vacationBalanceService): Response
    {
        $user = $request->user();

        abort_unless($user?->canRequestAbsence(), HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('AbsenceRequests/Index', [
            'absenceRequests' => AbsenceRequest::query()
                ->where('user_id', $user->id)
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn(AbsenceRequest $absenceRequest): array => [
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

    public function manage(Request $request): Response
    {
        abort_unless(
            $request->user()?->hasRole('PDL'),
            HttpResponse::HTTP_FORBIDDEN,
        );

        return Inertia::render('AbsenceRequests/Manage', [
            'absenceRequests' => AbsenceRequest::query()
                ->with(['user.employeeProfile', 'user.location', 'requestedBy', 'decidedBy'])
                ->orderByRaw("case when status = ? then 0 else 1 end", [AbsenceRequestStatus::Requested->value])
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn(AbsenceRequest $absenceRequest): array => [
                    'id' => $absenceRequest->id,
                    'employeeName' => $absenceRequest->user?->name,
                    'employeeEmail' => $absenceRequest->user?->email,
                    'employmentAreaLabel' => $absenceRequest->user?->employeeProfile?->employment_area?->label(),
                    'locationName' => $absenceRequest->location?->name ?? $absenceRequest->user?->location?->name,
                    'type' => $absenceRequest->type->value,
                    'typeLabel' => $absenceRequest->type->label(),
                    'startsOn' => $absenceRequest->starts_on->toDateString(),
                    'endsOn' => $absenceRequest->ends_on->toDateString(),
                    'daysCount' => $absenceRequest->days_count,
                    'status' => $absenceRequest->status->value,
                    'statusLabel' => $absenceRequest->status->label(),
                    'note' => $absenceRequest->note,
                    'rejectionReason' => $absenceRequest->rejection_reason,
                    'requestedByName' => $absenceRequest->requestedBy?->name,
                    'decidedByName' => $absenceRequest->decidedBy?->name,
                    'decidedAt' => $absenceRequest->decided_at?->toDateTimeString(),
                    'createdAt' => $absenceRequest->created_at?->toDateString(),
                ])
                ->values(),
        ]);
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

        $absenceRequestService->approve($absenceRequest, $request->user());

        return back()->with('status', 'absence-request-approved');
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