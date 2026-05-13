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

class AbsenceRequestController extends Controller
{
    public function index(Request $request): Response
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
}