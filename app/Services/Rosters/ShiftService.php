<?php

namespace App\Services\Rosters;

use App\Enums\AbsenceRequestStatus;
use App\Enums\EmploymentArea;
use App\Enums\ShiftSource;
use App\Models\AbsenceRequest;
use App\Models\EmployeeProfile;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    public function __construct(private readonly RosterDateService $rosterDateService) {}

    public function assignManualShift(
        Roster $roster,
        User $employee,
        ShiftTemplate $shiftTemplate,
        string $date,
        ?string $note = null,
    ): Shift {
        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können geändert werden.',
            ]);
        }

        $employee->loadMissing('employeeProfile');
        $employeeProfile = $employee->employeeProfile;

        $this->ensureEmployeeCanBeAssigned($employeeProfile);
        $this->ensureShiftTemplateMatchesRoster($roster, $shiftTemplate);

        $shiftDate = $this->parseDateForRoster($roster, $date);
        [$startsAt, $endsAt] = $this->rosterDateService->buildShiftTimes($shiftDate, $shiftTemplate);

        $this->ensureEmployeeCanWorkShiftTemplate($employeeProfile, $shiftTemplate);
        $this->ensureNoApprovedAbsenceOverlap($employee, $startsAt, $endsAt);
        $this->ensureNoDuplicateShift($employee, $shiftTemplate, $shiftDate->toDateString());

        return Shift::query()->create([
            'roster_id' => $roster->id,
            'location_id' => $roster->location_id,
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => $shiftDate->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => ShiftSource::Manual,
            'note' => $note,
        ]);
    }

    public function updateManualShift(
        Shift $shift,
        User $employee,
        ShiftTemplate $shiftTemplate,
        string $date,
        ?string $note = null,
    ): Shift {
        $shift->loadMissing('roster');
        $roster = $shift->roster;

        if ($roster === null || ! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können geändert werden.',
            ]);
        }

        $employee->loadMissing('employeeProfile');
        $employeeProfile = $employee->employeeProfile;

        $this->ensureEmployeeCanBeAssigned($employeeProfile);
        $this->ensureShiftTemplateMatchesRoster($roster, $shiftTemplate);

        $shiftDate = $this->parseDateForRoster($roster, $date);
        [$startsAt, $endsAt] = $this->rosterDateService->buildShiftTimes($shiftDate, $shiftTemplate);

        $this->ensureEmployeeCanWorkShiftTemplate($employeeProfile, $shiftTemplate);
        $this->ensureNoApprovedAbsenceOverlap($employee, $startsAt, $endsAt);
        $this->ensureNoDuplicateShift($employee, $shiftTemplate, $shiftDate->toDateString(), $shift->id);

        $shift->update([
            'location_id' => $roster->location_id,
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => $shiftDate->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => ShiftSource::Manual,
            'note' => $note,
        ]);

        return $shift->refresh();
    }

    private function ensureEmployeeCanBeAssigned(?EmployeeProfile $employeeProfile): void
    {
        if ($employeeProfile === null || ! $employeeProfile->active) {
            throw ValidationException::withMessages([
                'user_id' => 'Der Mitarbeiter hat kein aktives Mitarbeiterprofil.',
            ]);
        }

        if ($employeeProfile->employment_area !== EmploymentArea::Nursing) {
            throw ValidationException::withMessages([
                'user_id' => 'Nur Pflegekräfte können in den Pflege-Dienstplan eingetragen werden.',
            ]);
        }
    }

    private function ensureShiftTemplateMatchesRoster(Roster $roster, ShiftTemplate $shiftTemplate): void
    {
        if ($shiftTemplate->location_id !== $roster->location_id) {
            throw ValidationException::withMessages([
                'shift_template_id' => 'Die Schichtvorlage gehört nicht zum Wohnbereich des Dienstplans.',
            ]);
        }
    }

    private function parseDateForRoster(Roster $roster, string $date): CarbonImmutable
    {
        try {
            $shiftDate = CarbonImmutable::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'date' => 'Das Datum ist ungültig.',
            ]);
        }

        if (! $this->rosterDateService->isDateInRosterMonth($roster, $shiftDate)) {
            throw ValidationException::withMessages([
                'date' => 'Das Datum muss im Monat des Dienstplans liegen.',
            ]);
        }

        return $shiftDate;
    }

    private function ensureEmployeeCanWorkShiftTemplate(EmployeeProfile $employeeProfile, ShiftTemplate $shiftTemplate): void
    {
        $canWorkShift = match ($shiftTemplate->category) {
            'early' => $employeeProfile->can_work_early,
            'late' => $employeeProfile->can_work_late,
            'night' => $employeeProfile->can_work_night,
            default => true,
        };

        if (! $canWorkShift) {
            throw ValidationException::withMessages([
                'shift_template_id' => 'Der Mitarbeiter darf diese Schicht nicht arbeiten.',
            ]);
        }
    }

    private function ensureNoApprovedAbsenceOverlap(
        User $employee,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $hasApprovedAbsence = AbsenceRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', AbsenceRequestStatus::Approved->value)
            ->whereDate('starts_on', '<=', $endsAt->toDateString())
            ->whereDate('ends_on', '>=', $startsAt->toDateString())
            ->exists();

        if ($hasApprovedAbsence) {
            throw ValidationException::withMessages([
                'user_id' => 'Der Mitarbeiter ist im Zeitraum der Schicht abwesend.',
            ]);
        }
    }

    private function ensureNoDuplicateShift(
        User $employee,
        ShiftTemplate $shiftTemplate,
        string $date,
        ?string $exceptShiftId = null,
    ): void {
        $query = Shift::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $date)
            ->where('shift_template_id', $shiftTemplate->id);

        if ($exceptShiftId !== null) {
            $query->whereKeyNot($exceptShiftId);
        }

        $exists = $query->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'user_id' => 'Der Mitarbeiter ist an diesem Tag bereits für diese Schicht eingetragen.',
            ]);
        }
    }
}
