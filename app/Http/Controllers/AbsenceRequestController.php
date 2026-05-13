<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceRequestType;
use App\Services\Absences\AbsenceRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AbsenceRequestController extends Controller
{
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