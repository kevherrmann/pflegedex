<?php

namespace App\Services\Absences;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Models\AbsenceRequest;
use App\Models\RosterBlackoutDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

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
        if (! $employee->canRequestAbsence()) {
            throw ValidationException::withMessages([
                'user_id' => 'Dieser Mitarbeiter darf über dieses Modul keinen Urlaub beantragen.',
            ]);
        }

        $startsOn = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $endsOn = CarbonImmutable::parse($data['ends_on'])->startOfDay();
        $type = $data['type'] ?? AbsenceRequestType::Vacation;
        $type = $type instanceof AbsenceRequestType ? $type : AbsenceRequestType::from($type);
        $locationId = $data['location_id'] ?? $employee->location_id;

        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
            ]);
        }

        // Eine Urlaubssperre blockiert den Antrag nicht mehr hart. Der Antrag
        // wird angelegt und kann von der PDL individuell geprueft und nur mit
        // dokumentierter Begruendung als Ausnahme genehmigt werden.
        $this->ensureNoOverlappingRequest($employee, $startsOn->toDateString(), $endsOn->toDateString());

        $daysCount = $data['days_count'] ?? $startsOn->diffInDays($endsOn) + 1;

        return AbsenceRequest::query()->create([
            'user_id' => $employee->id,
            'location_id' => $locationId,
            'type' => $type,
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

    /**
     * Faellt dieser Antrag in eine Urlaubssperre, die auf den Mitarbeiter
     * zutrifft? Dient als Hinweis in der Verwaltung und als Ausloeser fuer die
     * Begruendungspflicht bei der Genehmigung.
     */
    public function hitsBlackout(AbsenceRequest $absenceRequest): bool
    {
        $employee = $absenceRequest->user;

        if (! $employee instanceof User) {
            return false;
        }

        return $this->applicableBlackoutExists(
            $employee,
            $absenceRequest->type,
            $absenceRequest->location_id,
            $absenceRequest->starts_on->toDateString(),
            $absenceRequest->ends_on->toDateString(),
        );
    }

    private function applicableBlackoutExists(
        User $employee,
        AbsenceRequestType $type,
        ?string $locationId,
        string $startsOn,
        string $endsOn,
    ): bool {
        if ($locationId === null) {
            return false;
        }

        $query = RosterBlackoutDay::query()
            ->forLocation($locationId)
            ->betweenDates($startsOn, $endsOn)
            ->with('employees:id');

        if ($type === AbsenceRequestType::Vacation) {
            $query->blockingVacation();
        }

        if ($type === AbsenceRequestType::OvertimeCompensation) {
            $query->blockingOvertimeCompensation();
        }

        // Nur Sperren betrachten, die tatsaechlich auf diesen Mitarbeiter zutreffen
        // (ganzer Wohnbereich, passende Qualifikation oder benannte Person).
        $employee->loadMissing('employeeProfile');

        return $query->get()->contains(
            fn (RosterBlackoutDay $blackoutDay): bool => $blackoutDay->appliesTo($employee),
        );
    }

    public function approve(AbsenceRequest $absenceRequest, User $decidedBy, ?string $overrideReason = null): AbsenceRequest
    {
        if (! $absenceRequest->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'Nur offene Anträge können genehmigt werden.',
            ]);
        }

        $hitsBlackout = $this->hitsBlackout($absenceRequest);
        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;

        // Faellt der Antrag in eine Urlaubssperre, ist eine Genehmigung nur als
        // dokumentierte Ausnahme mit Begruendung moeglich (Einzelfallpruefung).
        if ($hitsBlackout && ($overrideReason === null || $overrideReason === '')) {
            throw ValidationException::withMessages([
                'override_reason' => 'Dieser Antrag fällt in eine Urlaubssperre. Bitte begründe die Ausnahme.',
            ]);
        }

        $absenceRequest->forceFill([
            'status' => AbsenceRequestStatus::Approved,
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
            'rejection_reason' => null,
            'override_reason' => $hitsBlackout ? $overrideReason : null,
        ])->save();

        return $absenceRequest->refresh();
    }

    public function reject(AbsenceRequest $absenceRequest, User $decidedBy, string $reason): AbsenceRequest
    {
        if (! $absenceRequest->isOpen()) {
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
