<?php

namespace App\Services\Rosters;

use App\Enums\AbsenceRequestStatus;
use App\Enums\EmploymentArea;
use App\Enums\ShiftSource;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RosterGeneratorService
{
    public function __construct(private readonly RosterDateService $rosterDateService)
    {
    }

    public function generate(Roster $roster): RosterGenerationResult
    {
        if (! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Nur bearbeitbare Dienstpläne können automatisch geplant werden.',
            ]);
        }

        $result = new RosterGenerationResult();

        $roster->load(['location']);

        $deletedAutoShifts = Shift::query()
            ->where('roster_id', $roster->id)
            ->where('source', ShiftSource::Auto->value)
            ->delete();

        $result->addDeletedAutoShifts($deletedAutoShifts);

        $shiftTemplates = ShiftTemplate::query()
            ->with('staffingRules')
            ->where('location_id', $roster->location_id)
            ->where('active', true)
            ->orderBy('starts_at')
            ->get();

        $employees = User::query()
            ->with('employeeProfile')
            ->where('location_id', $roster->location_id)
            ->whereHas('employeeProfile', fn ($query) => $query
                ->where('active', true)
                ->where('employment_area', EmploymentArea::Nursing->value))
            ->orderBy('name')
            ->get();

        foreach ($this->rosterDateService->datesForRosterMonth($roster) as $date) {
            foreach ($shiftTemplates as $shiftTemplate) {
                $staffingRule = $this->findStaffingRule($shiftTemplate, $date);

                if ($staffingRule === null) {
                    $result->addSkipped('missing_staffing_rule', 'Für diese Schichtvorlage gibt es keine Besetzungsregel.', [
                        'date' => $date->toDateString(),
                        'shiftTemplateId' => $shiftTemplate->id,
                        'shiftTemplateCode' => $shiftTemplate->code,
                    ]);

                    continue;
                }

                [$startsAt, $endsAt] = $this->rosterDateService->buildShiftTimes($date, $shiftTemplate);

                $this->fillMissingSpecialists($employees, $roster, $shiftTemplate, $date, $startsAt, $endsAt, $staffingRule, $result);
                $this->fillMissingStaff($employees, $roster, $shiftTemplate, $date, $startsAt, $endsAt, $staffingRule, $result);
            }
        }

        return $result;
    }

    private function fillMissingSpecialists(
        Collection $employees,
        Roster $roster,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ShiftStaffingRule $staffingRule,
        RosterGenerationResult $result,
    ): void {
        while ($this->actualSpecialists($roster, $shiftTemplate, $date) < $staffingRule->required_specialists) {
            $candidate = $this->nextCandidate($employees, $roster, $shiftTemplate, $date, $startsAt, $endsAt, needSpecialist: true);

            if ($candidate === null) {
                $result->addSkipped('no_candidate', 'Es wurde kein geeigneter Mitarbeiter gefunden.', [
                    'date' => $date->toDateString(),
                    'shiftTemplateId' => $shiftTemplate->id,
                    'shiftTemplateCode' => $shiftTemplate->code,
                    'needSpecialist' => true,
                ]);

                return;
            }

            $this->createAutoShift($roster, $candidate, $shiftTemplate, $date, $startsAt, $endsAt);
            $result->addCreatedShift();
        }
    }

    private function fillMissingStaff(
        Collection $employees,
        Roster $roster,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ShiftStaffingRule $staffingRule,
        RosterGenerationResult $result,
    ): void {
        while ($this->actualTotalStaff($roster, $shiftTemplate, $date) < $staffingRule->required_total_staff) {
            $candidate = $this->nextCandidate($employees, $roster, $shiftTemplate, $date, $startsAt, $endsAt, needSpecialist: false);

            if ($candidate === null) {
                $result->addSkipped('no_candidate', 'Es wurde kein geeigneter Mitarbeiter gefunden.', [
                    'date' => $date->toDateString(),
                    'shiftTemplateId' => $shiftTemplate->id,
                    'shiftTemplateCode' => $shiftTemplate->code,
                    'needSpecialist' => false,
                ]);

                return;
            }

            $this->createAutoShift($roster, $candidate, $shiftTemplate, $date, $startsAt, $endsAt);
            $result->addCreatedShift();
        }
    }

    private function nextCandidate(
        Collection $employees,
        Roster $roster,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $needSpecialist,
    ): ?User {
        return $this->sortedCandidates($employees, $roster)
            ->first(function (User $employee) use ($shiftTemplate, $date, $startsAt, $endsAt, $needSpecialist): bool {
                if ($needSpecialist && ! ($employee->employeeProfile?->is_nursing_specialist ?? false)) {
                    return false;
                }

                return $this->employeeCanWorkShiftTemplate($employee, $shiftTemplate)
                    && ! $this->employeeHasApprovedAbsenceOverlap($employee, $startsAt, $endsAt)
                    && ! $this->employeeAlreadyAssigned($employee, $shiftTemplate, $date->toDateString());
            });
    }

    private function createAutoShift(
        Roster $roster,
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): Shift {
        return Shift::query()->create([
            'roster_id' => $roster->id,
            'location_id' => $roster->location_id,
            'user_id' => $employee->id,
            'shift_template_id' => $shiftTemplate->id,
            'date' => $date->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => ShiftSource::Auto,
            'note' => null,
        ]);
    }

    private function actualTotalStaff(Roster $roster, ShiftTemplate $shiftTemplate, CarbonImmutable $date): int
    {
        return Shift::query()
            ->where('roster_id', $roster->id)
            ->where('shift_template_id', $shiftTemplate->id)
            ->whereDate('date', $date->toDateString())
            ->count();
    }

    private function actualSpecialists(Roster $roster, ShiftTemplate $shiftTemplate, CarbonImmutable $date): int
    {
        return Shift::query()
            ->where('roster_id', $roster->id)
            ->where('shift_template_id', $shiftTemplate->id)
            ->whereDate('date', $date->toDateString())
            ->whereHas('user.employeeProfile', fn ($query) => $query->where('is_nursing_specialist', true))
            ->count();
    }

    private function findStaffingRule(ShiftTemplate $shiftTemplate, CarbonImmutable $date): ?ShiftStaffingRule
    {
        $weekday = $date->dayOfWeekIso;

        return $shiftTemplate->staffingRules
            ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === $weekday)
            ?? $shiftTemplate->staffingRules
                ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === null);
    }

    private function employeeCanWorkShiftTemplate(User $employee, ShiftTemplate $shiftTemplate): bool
    {
        return match ($shiftTemplate->code) {
            'early' => $employee->employeeProfile?->can_work_early ?? false,
            'late' => $employee->employeeProfile?->can_work_late ?? false,
            'night' => $employee->employeeProfile?->can_work_night ?? false,
            default => true,
        };
    }

    private function employeeHasApprovedAbsenceOverlap(
        User $employee,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): bool {
        return AbsenceRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', AbsenceRequestStatus::Approved->value)
            ->whereDate('starts_on', '<=', $endsAt->toDateString())
            ->whereDate('ends_on', '>=', $startsAt->toDateString())
            ->exists();
    }

    private function employeeAlreadyAssigned(User $employee, ShiftTemplate $shiftTemplate, string $date): bool
    {
        return Shift::query()
            ->where('user_id', $employee->id)
            ->where('shift_template_id', $shiftTemplate->id)
            ->whereDate('date', $date)
            ->exists();
    }

    private function sortedCandidates(Collection $employees, Roster $roster): Collection
    {
        $shiftCounts = Shift::query()
            ->where('roster_id', $roster->id)
            ->selectRaw('user_id, count(*) as shifts_count')
            ->groupBy('user_id')
            ->pluck('shifts_count', 'user_id');

        return $employees
            ->sortBy([
                fn (User $employee): int => (int) ($shiftCounts[$employee->id] ?? 0),
                fn (User $employee): string => $employee->name,
            ])
            ->values();
    }
}
