<?php

namespace App\Services\Rosters\Planning;

use App\Enums\AbsenceRequestStatus;
use App\Enums\EmploymentArea;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\RosterBlackoutDay;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Einmal geladener, in-memory gehaltener Planungszustand für eine Generierung.
 *
 * Lädt alle Dienste der berechtigten Mitarbeiter in einem Randfenster um den
 * Dienstplanmonat (inklusive anderer Dienstpläne und Standorte), damit
 * Ruhezeiten, Folgetage und Wochenstunden nicht an der Monatsgrenze abreißen.
 * Alle Regel-Prüfungen arbeiten danach ohne weitere Datenbankzugriffe.
 */
class PlanningContext
{
    /** @var Collection<int, User> */
    public Collection $employees;

    /** @var EloquentCollection<int, ShiftTemplate> */
    public EloquentCollection $shiftTemplates;

    public int $representativeShiftMinutes;

    public readonly CarbonImmutable $monthStart;

    public readonly CarbonImmutable $monthEnd;

    private readonly int $requiredRestMinutes;

    private readonly int $maxConsecutiveWorkDays;

    private readonly int $maxWeekendsPerMonth;

    private readonly int $weeklyMaxMinutes;

    /** @var array<string, array<string, true>> userId => [Y-m-d => true], alle bekannten Arbeitstage im Fenster */
    private array $workDates = [];

    /** @var array<string, array<int, array{0: int, 1: int}>> userId => Dienstintervalle als Timestamps */
    private array $intervals = [];

    /** @var array<string, int> userId => geplante Minuten im aktuellen Dienstplan */
    private array $plannedMinutes = [];

    /** @var array<string, int> userId => Dienste im aktuellen Dienstplan */
    private array $shiftCounts = [];

    /** @var array<string, array<string, true>> userId => Wochenend-Schlüssel im Dienstplanmonat */
    private array $weekendKeys = [];

    /** @var array<string, array<string, int>> userId => [ISO-Woche => Minuten], fensterweit */
    private array $weeklyMinutes = [];

    /** @var array<string, array<string, true>> userId => ["date|templateId" => true], fensterweit */
    private array $assignedTemplates = [];

    /** @var array<string, array<int, array{0: string, 1: string}>> userId => genehmigte Abwesenheiten [von, bis] */
    private array $approvedAbsences = [];

    /** @var array<string, array<int, array{0: string, 1: string}>> userId => beantragte Abwesenheiten [von, bis] */
    private array $requestedAbsences = [];

    /** @var array<string, int> "date|templateId" => Gesamtbesetzung im aktuellen Dienstplan */
    private array $slotTotals = [];

    /** @var array<string, int> "date|templateId" => Fachkräfte im aktuellen Dienstplan */
    private array $slotSpecialists = [];

    /** @var array<string, true> Urlaubssperren-Tage des Standorts im Monat */
    private array $blackoutDates = [];

    /** @var array<string, int> userId => monatliche Soll-Minuten */
    private array $targetMinutes = [];

    public function __construct(private readonly Roster $roster)
    {
        $this->requiredRestMinutes = (int) config('rostering.required_rest_minutes');
        $this->maxConsecutiveWorkDays = (int) config('rostering.max_consecutive_work_days');
        $this->maxWeekendsPerMonth = (int) config('rostering.max_weekends_per_month');
        $this->weeklyMaxMinutes = (int) config('rostering.weekly_max_minutes');

        $this->monthStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $this->monthEnd = $this->monthStart->endOfMonth()->startOfDay();

        $this->loadTemplates();
        $this->loadEmployees();
        $this->loadShifts();
        $this->loadAbsences();
        $this->loadBlackoutDates();
        $this->calculateTargets();
    }

    private function loadTemplates(): void
    {
        $this->shiftTemplates = ShiftTemplate::query()
            ->with('staffingRules')
            ->where('location_id', $this->roster->location_id)
            ->where('active', true)
            ->orderBy('starts_at')
            ->get();

        $shiftMinutes = (int) round((float) ($this->shiftTemplates->avg('duration_minutes') ?? 0));
        $this->representativeShiftMinutes = $shiftMinutes > 0
            ? $shiftMinutes
            : (int) config('rostering.default_shift_minutes');
    }

    private function loadEmployees(): void
    {
        $this->employees = User::query()
            ->with('employeeProfile')
            ->where('location_id', $this->roster->location_id)
            ->whereHas('employeeProfile', fn ($query) => $query
                ->where('active', true)
                ->where('employment_area', EmploymentArea::Nursing->value))
            ->orderBy('name')
            ->get()
            ->values();
    }

    private function loadShifts(): void
    {
        [$windowStart, $windowEnd] = $this->windowBounds();

        // Alle Dienste dieses Dienstplans (auch von inzwischen nicht mehr
        // berechtigten Mitarbeitern), damit Besetzungszaehler vollstaendig sind.
        $rosterShifts = Shift::query()
            ->with('user.employeeProfile')
            ->where('roster_id', $this->roster->id)
            ->get();

        foreach ($rosterShifts as $shift) {
            $this->indexShift(
                $shift->user_id,
                $shift->shift_template_id,
                CarbonImmutable::parse($shift->date)->startOfDay(),
                $shift->starts_at->toImmutable(),
                $shift->ends_at->toImmutable(),
                belongsToRoster: true,
                isSpecialist: (bool) ($shift->user?->employeeProfile?->is_nursing_specialist ?? false),
            );
        }

        // Dienste derselben Mitarbeiter im Randfenster, inklusive anderer
        // Dienstplaene und Standorte (Ruhezeiten, Folgetage, Wochenstunden).
        $boundaryShifts = Shift::query()
            ->whereIn('user_id', $this->employees->pluck('id'))
            ->where('roster_id', '!=', $this->roster->id)
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->whereDate('date', '<=', $windowEnd->toDateString())
            ->get(['id', 'roster_id', 'user_id', 'shift_template_id', 'date', 'starts_at', 'ends_at']);

        foreach ($boundaryShifts as $shift) {
            $this->indexShift(
                $shift->user_id,
                $shift->shift_template_id,
                CarbonImmutable::parse($shift->date)->startOfDay(),
                $shift->starts_at->toImmutable(),
                $shift->ends_at->toImmutable(),
                belongsToRoster: false,
                isSpecialist: false,
            );
        }
    }

    private function loadAbsences(): void
    {
        [$windowStart, $windowEnd] = $this->windowBounds();

        $absences = AbsenceRequest::query()
            ->whereIn('user_id', $this->employees->pluck('id'))
            ->whereIn('status', [AbsenceRequestStatus::Approved->value, AbsenceRequestStatus::Requested->value])
            ->whereDate('starts_on', '<=', $windowEnd->toDateString())
            ->whereDate('ends_on', '>=', $windowStart->toDateString())
            ->get(['user_id', 'status', 'starts_on', 'ends_on']);

        foreach ($absences as $absence) {
            $range = [$absence->starts_on->toDateString(), $absence->ends_on->toDateString()];

            if ($absence->status === AbsenceRequestStatus::Approved) {
                $this->approvedAbsences[$absence->user_id][] = $range;
            } else {
                $this->requestedAbsences[$absence->user_id][] = $range;
            }
        }
    }

    private function loadBlackoutDates(): void
    {
        $dates = RosterBlackoutDay::query()
            ->where('location_id', $this->roster->location_id)
            ->whereDate('date', '>=', $this->monthStart->toDateString())
            ->whereDate('date', '<=', $this->monthEnd->toDateString())
            ->pluck('date');

        foreach ($dates as $date) {
            $this->blackoutDates[CarbonImmutable::parse($date)->toDateString()] = true;
        }
    }

    private function calculateTargets(): void
    {
        $calculator = new TargetMinutesCalculator;

        foreach ($this->employees as $employee) {
            $this->targetMinutes[$employee->id] = $calculator->monthlyTargetMinutes(
                $employee->employeeProfile,
                $this->roster->year,
                $this->roster->month,
                $this->representativeShiftMinutes,
            );
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowBounds(): array
    {
        $windowDays = (int) config('rostering.boundary_window_days');

        return [$this->monthStart->subDays($windowDays), $this->monthEnd->addDays($windowDays)];
    }

    /**
     * Registriert eine neue Zuweisung im Planungszustand.
     */
    public function addAssignment(
        User $employee,
        ShiftTemplate $shiftTemplate,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $this->indexShift(
            $employee->id,
            $shiftTemplate->id,
            $date,
            $startsAt,
            $endsAt,
            belongsToRoster: true,
            isSpecialist: (bool) ($employee->employeeProfile?->is_nursing_specialist ?? false),
        );
    }

    private function indexShift(
        string $userId,
        string $shiftTemplateId,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $belongsToRoster,
        bool $isSpecialist,
    ): void {
        $dateKey = $date->toDateString();
        $minutes = (int) $startsAt->diffInMinutes($endsAt, true);

        $this->workDates[$userId][$dateKey] = true;
        $this->intervals[$userId][] = [$startsAt->getTimestamp(), $endsAt->getTimestamp()];
        $this->assignedTemplates[$userId][$dateKey.'|'.$shiftTemplateId] = true;

        $weekKey = WorkRules::isoWeekKey($date);
        $this->weeklyMinutes[$userId][$weekKey] = ($this->weeklyMinutes[$userId][$weekKey] ?? 0) + $minutes;

        if ($date->between($this->monthStart, $this->monthEnd)) {
            $weekendKey = WorkRules::weekendStartKey($date);

            if ($weekendKey !== null) {
                $this->weekendKeys[$userId][$weekendKey] = true;
            }
        }

        if ($belongsToRoster) {
            $this->plannedMinutes[$userId] = ($this->plannedMinutes[$userId] ?? 0) + $minutes;
            $this->shiftCounts[$userId] = ($this->shiftCounts[$userId] ?? 0) + 1;

            $slotKey = $dateKey.'|'.$shiftTemplateId;
            $this->slotTotals[$slotKey] = ($this->slotTotals[$slotKey] ?? 0) + 1;

            if ($isSpecialist) {
                $this->slotSpecialists[$slotKey] = ($this->slotSpecialists[$slotKey] ?? 0) + 1;
            }
        }
    }

    public function slotTotalStaff(CarbonImmutable $date, ShiftTemplate $shiftTemplate): int
    {
        return $this->slotTotals[$date->toDateString().'|'.$shiftTemplate->id] ?? 0;
    }

    public function slotSpecialistCount(CarbonImmutable $date, ShiftTemplate $shiftTemplate): int
    {
        return $this->slotSpecialists[$date->toDateString().'|'.$shiftTemplate->id] ?? 0;
    }

    public function isBlackoutDate(CarbonImmutable $date): bool
    {
        return isset($this->blackoutDates[$date->toDateString()]);
    }

    public function hasApprovedAbsenceOverlap(User $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return $this->absenceOverlaps($this->approvedAbsences[$employee->id] ?? [], $startsAt, $endsAt);
    }

    public function hasRequestedAbsenceOverlap(User $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return $this->absenceOverlaps($this->requestedAbsences[$employee->id] ?? [], $startsAt, $endsAt);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $ranges
     */
    private function absenceOverlaps(array $ranges, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $shiftStart = $startsAt->toDateString();
        $shiftEnd = $endsAt->toDateString();

        foreach ($ranges as [$startsOn, $endsOn]) {
            if ($startsOn <= $shiftEnd && $endsOn >= $shiftStart) {
                return true;
            }
        }

        return false;
    }

    public function hasRestConflict(User $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return WorkRules::hasRestConflict(
            $this->intervals[$employee->id] ?? [],
            $startsAt->getTimestamp(),
            $endsAt->getTimestamp(),
            $this->requiredRestMinutes,
        );
    }

    public function wouldExceedConsecutiveWorkDays(User $employee, CarbonImmutable $date): bool
    {
        if (isset($this->workDates[$employee->id][$date->toDateString()])) {
            // Der Tag ist bereits Arbeitstag, eine weitere Schicht ändert die Folge nicht.
            return false;
        }

        $runLength = WorkRules::consecutiveRunLengthContaining(
            $this->workDates[$employee->id] ?? [],
            $date,
        );

        return $runLength > $this->maxConsecutiveWorkDays;
    }

    public function wouldExceedWeekendLoad(User $employee, CarbonImmutable $date): bool
    {
        $weekendKey = WorkRules::weekendStartKey($date);

        if ($weekendKey === null) {
            return false;
        }

        $weekends = $this->weekendKeys[$employee->id] ?? [];

        if (isset($weekends[$weekendKey])) {
            return false;
        }

        return count($weekends) + 1 > $this->maxWeekendsPerMonth;
    }

    public function isAlreadyAssigned(User $employee, ShiftTemplate $shiftTemplate, CarbonImmutable $date): bool
    {
        return isset($this->assignedTemplates[$employee->id][$date->toDateString().'|'.$shiftTemplate->id]);
    }

    public function wouldExceedWeeklyMaxMinutes(User $employee, CarbonImmutable $date, int $shiftMinutes): bool
    {
        $weekKey = WorkRules::isoWeekKey($date);
        $currentMinutes = $this->weeklyMinutes[$employee->id][$weekKey] ?? 0;

        return $currentMinutes + $shiftMinutes > $this->weeklyMaxMinutes;
    }

    public function plannedMinutesFor(User $employee): int
    {
        return $this->plannedMinutes[$employee->id] ?? 0;
    }

    public function shiftCountFor(User $employee): int
    {
        return $this->shiftCounts[$employee->id] ?? 0;
    }

    public function targetMinutesFor(User $employee): int
    {
        return $this->targetMinutes[$employee->id] ?? 0;
    }

    public function utilizationPermilleFor(User $employee): int
    {
        $targetMinutes = $this->targetMinutesFor($employee);

        if ($targetMinutes <= 0) {
            return 1000000;
        }

        return (int) round($this->plannedMinutesFor($employee) * 1000 / $targetMinutes);
    }
}
