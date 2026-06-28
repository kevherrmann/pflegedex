<?php

namespace App\Services\Absences;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\RosterBlackoutDay;
use App\Models\Shift;
use App\Models\User;
use App\Services\Rosters\RosterGeneratorService;
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

    /**
     * Krankmeldung durch die PDL: Eine Krankheit ist kein Antrag, sondern eine
     * Tatsache – sie wird sofort als genehmigte Abwesenheit erfasst. Die
     * betroffenen Dienste werden freigeräumt, aber NICHT automatisch nachbesetzt
     * (siehe {@see resolveRosterConflicts}). Die Vertretung wird anschließend
     * vom Planer über die Vertretungssuche abgestimmt (Human-in-the-Loop).
     *
     * @param  array{starts_on: string, ends_on?: string|null, location_id?: string|null, note?: string|null}  $data
     */
    public function reportSick(User $employee, User $reportedBy, array $data): AbsenceRequest
    {
        $startsOn = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        $endsOn = CarbonImmutable::parse($data['ends_on'] ?? $data['starts_on'])->startOfDay();

        if ($endsOn->lt($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => 'Das Enddatum darf nicht vor dem Startdatum liegen.',
            ]);
        }

        $absenceRequest = AbsenceRequest::query()->create([
            'user_id' => $employee->id,
            'location_id' => $data['location_id'] ?? $employee->location_id,
            'type' => AbsenceRequestType::Sick,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'days_count' => $startsOn->diffInDays($endsOn) + 1,
            'status' => AbsenceRequestStatus::Approved,
            'requested_by' => $reportedBy->id,
            'decided_by' => $reportedBy->id,
            'decided_at' => now(),
            'note' => $data['note'] ?? null,
        ]);

        $this->resolveRosterConflicts($absenceRequest);

        return $absenceRequest->refresh();
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

        $this->resolveRosterConflicts($absenceRequest);

        return $absenceRequest->refresh();
    }

    /**
     * Nach der Genehmigung darf es keine Doppelbuchung (Abwesenheit + Dienst)
     * geben: In editierbaren Dienstplänen werden die Dienste des Mitarbeiters im
     * Abwesenheitszeitraum entfernt. Veröffentlichte/gesperrte Pläne sowie
     * bereits gelaufene Tage (vor heute) bleiben unangetastet.
     *
     * Geplante Abwesenheiten (Urlaub, Überstundenfrei) werden anschließend
     * automatisch neu generiert, damit die frei gewordenen Slots nachbesetzt
     * werden. Kurzfristige Ausfälle (Krankmeldung) werden bewusst NICHT
     * automatisch nachbesetzt – die Vertretung muss mit den Mitarbeitenden
     * abgestimmt werden und läuft über die Vertretungssuche (Human-in-the-Loop).
     */
    private function resolveRosterConflicts(AbsenceRequest $absenceRequest): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $start = $absenceRequest->starts_on->toDateString();
        $end = $absenceRequest->ends_on->toDateString();

        // Eingefrorene Vergangenheit: nur ab heute aufräumen.
        $deleteFrom = $start >= $today ? $start : $today;

        if ($deleteFrom > $end) {
            return; // Abwesenheit liegt vollständig in der Vergangenheit.
        }

        $rosterIds = Shift::query()
            ->where('user_id', $absenceRequest->user_id)
            ->whereDate('date', '>=', $deleteFrom)
            ->whereDate('date', '<=', $end)
            ->distinct()
            ->pluck('roster_id');

        if ($rosterIds->isEmpty()) {
            return;
        }

        $autoRefill = $absenceRequest->type !== AbsenceRequestType::Sick;
        $generator = app(RosterGeneratorService::class);

        foreach (Roster::query()->whereIn('id', $rosterIds)->get() as $roster) {
            if (! $roster->isEditable()) {
                continue;
            }

            // Konfliktdienste des Mitarbeiters entfernen (auch manuelle).
            Shift::query()
                ->where('roster_id', $roster->id)
                ->where('user_id', $absenceRequest->user_id)
                ->whereDate('date', '>=', $deleteFrom)
                ->whereDate('date', '<=', $end)
                ->delete();

            // Geplante Abwesenheiten automatisch neu generieren (berücksichtigt
            // die Abwesenheit als harte Regel). Krankmeldungen bleiben offen für
            // die manuelle Vertretungssuche.
            if ($autoRefill) {
                $generator->generate($roster);
            }
        }
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
