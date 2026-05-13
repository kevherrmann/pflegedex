<?php

namespace App\Services\Absences;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Models\AbsenceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
class AbsenceRequestService
{
    /**
     * @param  array{
     *     type?: AbsenceRequestType|string,
     *     starts_on: string,
     *     ends_on: string,
     *     days_count?: float|int|string|null,
     *     location_id?: string|null,
     *     note?: string|null
     * }  $data
     */
    public function request(User $employee, User $requestedBy, array $data): AbsenceRequest
    {
        if (!$employee->canRequestAbsence()) {
            throw ValidationException::withMessages([
                'user_id' => 'Dieser Mitarbeiter darf über dieses Modul keinen Urlaub beantragen.',
            ]);
        }

        $startsOn = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $endsOn = CarbonImmutable::parse($data['ends_on'])->startOfDay();

        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
            ]);
        }

        $this->ensureNoOverlappingRequest($employee, $startsOn->toDateString(), $endsOn->toDateString());

        $daysCount = $data['days_count'] ?? $startsOn->diffInDays($endsOn) + 1;

        return AbsenceRequest::query()->create([
            'user_id' => $employee->id,
            'location_id' => $data['location_id'] ?? $employee->location_id,
            'type' => $data['type'] ?? AbsenceRequestType::Vacation,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'days_count' => $daysCount,
            'status' => AbsenceRequestStatus::Requested,
            'requested_by' => $requestedBy->id,
            'note' => $data['note'] ?? null,
        ]);
    }

    private function ensureNoOverlappingRequest(User $employee, string $startsOn, string $endsOn): void
    {
        $hasOverlap = $employee
            ->absenceRequests()
            ->blockingOverlap()
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->exists();

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'starts_on' => 'Für diesen Zeitraum existiert bereits ein aktiver Abwesenheitsantrag.',
            ]);
        }
    }

    public function approve(AbsenceRequest $absenceRequest, User $decidedBy): AbsenceRequest
    {
        if (!$absenceRequest->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'Nur offene Anträge können genehmigt werden.',
            ]);
        }

        $absenceRequest->forceFill([
            'status' => AbsenceRequestStatus::Approved,
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return $absenceRequest->refresh();
    }

    public function reject(AbsenceRequest $absenceRequest, User $decidedBy, string $reason): AbsenceRequest
    {
        if (!$absenceRequest->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'Nur offene Anträge können abgelehnt werden.',
            ]);
        }

        $absenceRequest->forceFill([
            'status' => AbsenceRequestStatus::Rejected,
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        return $absenceRequest->refresh();
    }
}