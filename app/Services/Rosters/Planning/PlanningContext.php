<?php

namespace App\Services\Rosters\Planning;

use App\Enums\AbsenceRequestStatus;
use App\Enums\EmploymentArea;
use App\Enums\ShiftWishKind;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\RosterBlackoutDay;
use App\Models\Shift;
use App\Models\ShiftCategoryStaffingRule;
use App\Models\ShiftStaffingRule;
use App\Models\ShiftTemplate;
use App\Models\ShiftWish;
use App\Models\User;
use App\Services\Rosters\CategoryStaffingResolver;
use App\Services\Rosters\HolidayService;
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
 *
 * Zuweisungen können wieder entfernt werden (Tausch- und Verschiebezüge der
 * lokalen Suche), daher sind alle Indizes als Zähler gepflegt.
 */
class PlanningContext
{
    /** Rangfolge für die Vorwärtsrotation Früh -> Spät -> Nacht. */
    private const ROTATION_RANKS = ['early' => 0, 'late' => 1, 'night' => 2];

    /** @var Collection<int, User> */
    public Collection $employees;

    /** @var EloquentCollection<int, ShiftTemplate> */
    public EloquentCollection $shiftTemplates;

    public int $representativeShiftMinutes;

    public readonly CarbonImmutable $monthStart;

    public readonly CarbonImmutable $monthEnd;

    /**
     * Ab diesem Tag wird (neu) geplant. Dienste davor sind eingefroren: Sie
     * zählen als feste Anker in alle Zähler hinein, werden aber nie ersetzt
     * (auch nicht in der Vorschau). Standard: Monatsanfang.
     */
    public readonly CarbonImmutable $planningStart;

    private readonly int $requiredRestMinutes;

    private readonly int $maxConsecutiveWorkDays;

    private readonly int $maxConsecutiveNightShifts;

    private readonly int $maxWeekendsPerMonth;

    private readonly int $weeklyMaxMinutes;

    private readonly int $dailyMaxMinutes;

    /** @var array<string, array<string, int>> userId => [Y-m-d => Dienste an diesem Tag], fensterweit */
    private array $workDates = [];

    /** @var array<string, array<string, int>> userId => [Y-m-d => Minuten an diesem Tag], fensterweit */
    private array $dailyMinutes = [];

    /** @var array<string, array<string, array{0: int, 1: int}>> userId => [Belegungsschlüssel => Intervall-Timestamps] */
    private array $intervals = [];

    /** @var array<string, int> userId => geplante Minuten im aktuellen Dienstplan */
    private array $plannedMinutes = [];

    /** @var array<string, int> userId => Dienste im aktuellen Dienstplan */
    private array $shiftCounts = [];

    /** @var array<string, array<string, int>> userId => [Wochenend-Schlüssel => Dienste], nur Dienstplanmonat */
    private array $weekendKeys = [];

    /** @var array<string, array<string, int>> userId => [ISO-Woche => Minuten], fensterweit */
    private array $weeklyMinutes = [];

    /** @var array<string, true> Belegungsschlüssel "userId|date|templateId", fensterweit */
    private array $assignedTemplates = [];

    /** @var array<string, array<string, array<int, int>>> userId => [Y-m-d => [Rotationsrang => Dienste]] */
    private array $rotationRanks = [];

    /** @var array<string, int> userId => Nachtdienste im aktuellen Dienstplan */
    private array $nightShiftCounts = [];

    /** @var array<string, array<int, array{0: string, 1: string}>> userId => genehmigte Abwesenheiten [von, bis] */
    private array $approvedAbsences = [];

    /** @var array<string, array<int, array{0: string, 1: string}>> userId => beantragte Abwesenheiten [von, bis] */
    private array $requestedAbsences = [];

    /** @var array<string, int> "date|templateId" => Gesamtbesetzung im aktuellen Dienstplan */
    private array $slotTotals = [];

    /** @var array<string, int> "date|templateId" => Fachkräfte im aktuellen Dienstplan */
    private array $slotSpecialists = [];

    /** @var array<string, int> "date|category" => Gesamtbesetzung der Kategorie an dem Tag */
    private array $slotCategoryTotals = [];

    /** @var array<string, int> "date|category" => Fachkräfte der Kategorie an dem Tag */
    private array $slotCategorySpecialists = [];

    /** @var array<string, array<int, ShiftCategoryStaffingRule>> category => Regeln (Wochentag-spezifisch + Standard) */
    private array $categoryStaffingRules = [];

    /** @var array<string, Collection<int, ShiftTemplate>> category => aktive Vorlagen */
    private array $templatesByCategory = [];

    /** @var array<string, true> Urlaubssperren-Tage des Standorts im Monat */
    private array $blackoutDates = [];

    /** @var array<string, int> userId => monatliche Soll-Minuten */
    private array $targetMinutes = [];

    /** @var array<string, array<string, true>> userId => [Y-m-d => true] Wunschfrei-Tage */
    private array $wishFreeDates = [];

    /** @var array<string, array<string, string|null>> userId => [Y-m-d => gewünschte Vorlage oder null] */
    private array $wishShifts = [];

    /** @var array<string, bool> userId => ist Fachkraft */
    private array $specialists = [];

    /** @var array<string, string> Y-m-d => Vortag, für Carbon-freie Nachbarschaftsprüfungen */
    private array $previousDayKeys = [];

    /** @var array<string, string> Y-m-d => Folgetag */
    private array $nextDayKeys = [];

    /** @var array<int, array{0: string, 1: array<int, string>}> [Sonntag, bekannte Fenstertage im Monat] */
    private array $monthSundayWindows = [];

    /** @var array<string, array{week: string, weekend: string|null, inMonth: bool}> Y-m-d => Metadaten */
    private array $dateMeta = [];

    /** @var array<string, true> Y-m-d => gesetzlicher Feiertag (bundeslandabhängig) */
    private array $holidays = [];

    public function __construct(
        private readonly Roster $roster,
        bool $ignoreAutoShifts = false,
        ?CarbonImmutable $planningStart = null,
    ) {
        $this->requiredRestMinutes = (int) config('rostering.required_rest_minutes');
        $this->maxConsecutiveWorkDays = (int) config('rostering.max_consecutive_work_days');
        $this->maxConsecutiveNightShifts = (int) config('rostering.max_consecutive_night_shifts');
        $this->maxWeekendsPerMonth = (int) config('rostering.max_weekends_per_month');
        $this->weeklyMaxMinutes = (int) config('rostering.weekly_max_minutes');
        $this->dailyMaxMinutes = (int) config('rostering.daily_max_minutes');

        $this->monthStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $this->monthEnd = $this->monthStart->endOfMonth()->startOfDay();
        $this->planningStart = ($planningStart !== null && $planningStart->greaterThan($this->monthStart))
            ? $planningStart->startOfDay()
            : $this->monthStart;

        $this->buildDateLookups();
        $this->loadTemplates();
        $this->loadCategoryStaffing();
        $this->loadEmployees();
        $this->loadShifts($ignoreAutoShifts);
        $this->loadAbsences();
        $this->loadBlackoutDates();
        $this->loadWishes();
        $this->calculateTargets();
        $this->loadHolidays();
    }

    private function loadHolidays(): void
    {
        $state = $this->roster->location?->state;
        $service = new HolidayService;

        $startYear = (int) $this->monthStart->subDays(10)->year;
        $endYear = (int) $this->monthEnd->addDays(10)->year;

        for ($year = $startYear; $year <= $endYear; $year++) {
            foreach (array_keys($service->holidaysForYear($year, $state)) as $dateKey) {
                $this->holidays[$dateKey] = true;
            }
        }
    }

    public function isHoliday(CarbonImmutable $date): bool
    {
        return isset($this->holidays[$date->toDateString()]);
    }

    /**
     * Vorberechnete Datums-Nachbarschaften und Sonntags-Ausgleichsfenster,
     * damit die Strafbewertung ohne Carbon-Objekte auskommt (heißer Pfad
     * der lokalen Suche).
     */
    private function buildDateLookups(): void
    {
        [$windowStart, $windowEnd] = $this->windowBounds();

        $previous = null;

        for ($date = $windowStart->subDay(); $date->lessThanOrEqualTo($windowEnd->addDay()); $date = $date->addDay()) {
            $key = $date->toDateString();

            if ($previous !== null) {
                $this->previousDayKeys[$key] = $previous;
                $this->nextDayKeys[$previous] = $key;
            }

            $this->dateMeta[$key] = [
                'week' => WorkRules::isoWeekKey($date),
                'weekend' => WorkRules::weekendStartKey($date),
                'inMonth' => $date->between($this->monthStart, $this->monthEnd),
            ];

            $previous = $key;
        }

        for ($date = $this->monthStart; $date->lessThanOrEqualTo($this->monthEnd); $date = $date->addDay()) {
            if ($date->dayOfWeekIso !== 7) {
                continue;
            }

            $window = [];

            for ($windowDate = $date->subDays(6); $windowDate->lessThanOrEqualTo($date->addDays(7)); $windowDate = $windowDate->addDay()) {
                if ($windowDate->between($this->monthStart, $this->monthEnd)) {
                    $window[] = $windowDate->toDateString();
                }
            }

            $this->monthSundayWindows[] = [$date->toDateString(), $window];
        }
    }

    public function previousDayKey(string $date): string
    {
        return $this->previousDayKeys[$date]
            ?? CarbonImmutable::parse($date)->subDay()->toDateString();
    }

    public function nextDayKey(string $date): string
    {
        return $this->nextDayKeys[$date]
            ?? CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    /**
     * @return array{week: string, weekend: string|null, inMonth: bool}
     */
    private function dateMetaFor(CarbonImmutable $date): array
    {
        return $this->dateMeta[$date->toDateString()] ?? [
            'week' => WorkRules::isoWeekKey($date),
            'weekend' => WorkRules::weekendStartKey($date),
            'inMonth' => $date->between($this->monthStart, $this->monthEnd),
        ];
    }

    public function weekendStartKeyFor(CarbonImmutable $date): ?string
    {
        return $this->dateMetaFor($date)['weekend'];
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

        $this->templatesByCategory = $this->shiftTemplates
            ->groupBy(fn (ShiftTemplate $template): string => $template->category)
            ->all();
    }

    private function loadCategoryStaffing(): void
    {
        $this->categoryStaffingRules = (new CategoryStaffingResolver)
            ->forLocation($this->roster->location_id)
            ->groupBy('category')
            ->map(fn ($rules): array => $rules->all())
            ->all();
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

        foreach ($this->employees as $employee) {
            $this->specialists[$employee->id] = (bool) ($employee->employeeProfile?->is_nursing_specialist ?? false);
        }
    }

    private function loadShifts(bool $ignoreAutoShifts): void
    {
        [$windowStart, $windowEnd] = $this->windowBounds();

        // Alle Dienste dieses Dienstplans (auch von inzwischen nicht mehr
        // berechtigten Mitarbeitern), damit Besetzungszaehler vollstaendig sind.
        // In der Vorschau werden Auto-Dienste ignoriert (als ob sie ersetzt
        // würden) – aber nur ab dem Planungsstart. Auto-Dienste davor sind
        // eingefroren und bleiben als feste Anker erhalten (wie in generate()).
        $rosterShifts = Shift::query()
            ->with(['user.employeeProfile', 'shiftTemplate:id,code,category'])
            ->where('roster_id', $this->roster->id)
            ->when($ignoreAutoShifts, fn ($query) => $query->where(fn ($query) => $query
                ->where('source', '!=', 'auto')
                ->orWhereDate('date', '<', $this->planningStart->toDateString())))
            ->get();

        foreach ($rosterShifts as $shift) {
            $this->indexShift(
                $shift->user_id,
                $shift->shift_template_id,
                $this->rotationRankForCode($shift->shiftTemplate?->category),
                CarbonImmutable::parse($shift->date)->startOfDay(),
                $shift->starts_at->toImmutable(),
                $shift->ends_at->toImmutable(),
                belongsToRoster: true,
                isSpecialist: (bool) ($shift->user?->employeeProfile?->is_nursing_specialist ?? false),
                category: $shift->shiftTemplate?->category,
            );
        }

        // Dienste derselben Mitarbeiter im Randfenster, inklusive anderer
        // Dienstplaene und Standorte (Ruhezeiten, Folgetage, Wochenstunden).
        $boundaryShifts = Shift::query()
            ->with('shiftTemplate:id,code,category')
            ->whereIn('user_id', $this->employees->pluck('id'))
            ->where('roster_id', '!=', $this->roster->id)
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->whereDate('date', '<=', $windowEnd->toDateString())
            ->get(['id', 'roster_id', 'user_id', 'shift_template_id', 'date', 'starts_at', 'ends_at']);

        foreach ($boundaryShifts as $shift) {
            $this->indexShift(
                $shift->user_id,
                $shift->shift_template_id,
                $this->rotationRankForCode($shift->shiftTemplate?->category),
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

    private function loadWishes(): void
    {
        $wishes = ShiftWish::query()
            ->whereIn('user_id', $this->employees->pluck('id'))
            ->whereDate('date', '>=', $this->monthStart->toDateString())
            ->whereDate('date', '<=', $this->monthEnd->toDateString())
            ->get(['user_id', 'date', 'kind', 'shift_template_id']);

        foreach ($wishes as $wish) {
            $dateKey = CarbonImmutable::parse($wish->date)->toDateString();

            if ($wish->kind === ShiftWishKind::WishFree) {
                $this->wishFreeDates[$wish->user_id][$dateKey] = true;
            } else {
                $this->wishShifts[$wish->user_id][$dateKey] = $wish->shift_template_id;
            }
        }
    }

    private function calculateTargets(): void
    {
        $calculator = new TargetMinutesCalculator;

        foreach ($this->employees as $employee) {
            $baseTarget = $calculator->monthlyTargetMinutes(
                $employee->employeeProfile,
                $this->roster->year,
                $this->roster->month,
                $this->representativeShiftMinutes,
            );

            // Überstunden-Carryover: ein positiver Saldo (im Vormonat zu viel
            // gearbeitet) senkt das Monats-Soll, ein negativer (zu wenig) erhöht
            // es. Nie unter 0.
            $overtimeBalance = (int) ($employee->employeeProfile?->overtime_minutes_balance ?? 0);

            $this->targetMinutes[$employee->id] = max(0, $baseTarget - $overtimeBalance);
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

    public function rotationRankForCode(?string $code): ?int
    {
        return self::ROTATION_RANKS[$code] ?? null;
    }

    /**
     * Registriert eine neue Zuweisung im Planungszustand.
     */
    public function addAssignment(PlannedAssignment $assignment): void
    {
        $this->indexShift(
            $assignment->employee->id,
            $assignment->shiftTemplate->id,
            $this->rotationRankForCode($assignment->shiftTemplate->category),
            $assignment->date,
            $assignment->startsAt,
            $assignment->endsAt,
            belongsToRoster: true,
            isSpecialist: $this->isSpecialist($assignment->employee),
            category: $assignment->shiftTemplate->category,
        );
    }

    /**
     * Entfernt eine zuvor registrierte Zuweisung wieder (lokale Suche).
     */
    public function removeAssignment(PlannedAssignment $assignment): void
    {
        $userId = $assignment->employee->id;
        $dateKey = $assignment->date->toDateString();
        $minutes = $assignment->minutes();
        $occupancyKey = $dateKey.'|'.$assignment->shiftTemplate->id;
        $meta = $this->dateMetaFor($assignment->date);

        $this->workDates[$userId][$dateKey]--;
        if ($this->workDates[$userId][$dateKey] <= 0) {
            unset($this->workDates[$userId][$dateKey]);
        }

        unset(
            $this->intervals[$userId][$occupancyKey],
            $this->assignedTemplates[$userId.'|'.$occupancyKey],
        );

        $this->weeklyMinutes[$userId][$meta['week']] -= $minutes;

        if (isset($this->dailyMinutes[$userId][$dateKey])) {
            $this->dailyMinutes[$userId][$dateKey] -= $minutes;
            if ($this->dailyMinutes[$userId][$dateKey] <= 0) {
                unset($this->dailyMinutes[$userId][$dateKey]);
            }
        }

        if ($meta['inMonth'] && $meta['weekend'] !== null && isset($this->weekendKeys[$userId][$meta['weekend']])) {
            $this->weekendKeys[$userId][$meta['weekend']]--;
            if ($this->weekendKeys[$userId][$meta['weekend']] <= 0) {
                unset($this->weekendKeys[$userId][$meta['weekend']]);
            }
        }

        $rotationRank = $this->rotationRankForCode($assignment->shiftTemplate->category);
        if ($rotationRank !== null) {
            $this->rotationRanks[$userId][$dateKey][$rotationRank]--;
            if ($this->rotationRanks[$userId][$dateKey][$rotationRank] <= 0) {
                unset($this->rotationRanks[$userId][$dateKey][$rotationRank]);
            }
        }

        if ($assignment->shiftTemplate->category === 'night') {
            $this->nightShiftCounts[$userId]--;
        }

        $this->plannedMinutes[$userId] -= $minutes;
        $this->shiftCounts[$userId]--;
        $this->slotTotals[$occupancyKey]--;

        $categoryKey = $dateKey.'|'.$assignment->shiftTemplate->category;
        if (isset($this->slotCategoryTotals[$categoryKey])) {
            $this->slotCategoryTotals[$categoryKey]--;
        }

        if ($this->isSpecialist($assignment->employee)) {
            $this->slotSpecialists[$occupancyKey]--;

            if (isset($this->slotCategorySpecialists[$categoryKey])) {
                $this->slotCategorySpecialists[$categoryKey]--;
            }
        }
    }

    private function indexShift(
        string $userId,
        string $shiftTemplateId,
        ?int $rotationRank,
        CarbonImmutable $date,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $belongsToRoster,
        bool $isSpecialist,
        ?string $category = null,
    ): void {
        $dateKey = $date->toDateString();
        $minutes = (int) $startsAt->diffInMinutes($endsAt, true);
        $occupancyKey = $dateKey.'|'.$shiftTemplateId;
        $categoryKey = $category === null ? null : $dateKey.'|'.$category;
        $meta = $this->dateMetaFor($date);

        $this->workDates[$userId][$dateKey] = ($this->workDates[$userId][$dateKey] ?? 0) + 1;
        $this->intervals[$userId][$occupancyKey] = [$startsAt->getTimestamp(), $endsAt->getTimestamp()];
        $this->assignedTemplates[$userId.'|'.$occupancyKey] = true;

        $this->weeklyMinutes[$userId][$meta['week']] = ($this->weeklyMinutes[$userId][$meta['week']] ?? 0) + $minutes;
        $this->dailyMinutes[$userId][$dateKey] = ($this->dailyMinutes[$userId][$dateKey] ?? 0) + $minutes;

        if ($rotationRank !== null) {
            $this->rotationRanks[$userId][$dateKey][$rotationRank] =
                ($this->rotationRanks[$userId][$dateKey][$rotationRank] ?? 0) + 1;
        }

        if ($meta['inMonth'] && $meta['weekend'] !== null) {
            $this->weekendKeys[$userId][$meta['weekend']] = ($this->weekendKeys[$userId][$meta['weekend']] ?? 0) + 1;
        }

        if ($belongsToRoster) {
            $this->plannedMinutes[$userId] = ($this->plannedMinutes[$userId] ?? 0) + $minutes;
            $this->shiftCounts[$userId] = ($this->shiftCounts[$userId] ?? 0) + 1;
            $this->slotTotals[$occupancyKey] = ($this->slotTotals[$occupancyKey] ?? 0) + 1;

            if ($categoryKey !== null) {
                $this->slotCategoryTotals[$categoryKey] = ($this->slotCategoryTotals[$categoryKey] ?? 0) + 1;
            }

            if ($rotationRank === self::ROTATION_RANKS['night']) {
                $this->nightShiftCounts[$userId] = ($this->nightShiftCounts[$userId] ?? 0) + 1;
            }

            if ($isSpecialist) {
                $this->slotSpecialists[$occupancyKey] = ($this->slotSpecialists[$occupancyKey] ?? 0) + 1;

                if ($categoryKey !== null) {
                    $this->slotCategorySpecialists[$categoryKey] = ($this->slotCategorySpecialists[$categoryKey] ?? 0) + 1;
                }
            }
        }
    }

    public function isSpecialist(User $employee): bool
    {
        return $this->specialists[$employee->id]
            ?? (bool) ($employee->employeeProfile?->is_nursing_specialist ?? false);
    }

    public function staffingRuleFor(ShiftTemplate $shiftTemplate, CarbonImmutable $date): ?ShiftStaffingRule
    {
        // Feiertage werden besetzungstechnisch wie Sonntage behandelt (ISO-Wochentag 7).
        $weekday = isset($this->holidays[$date->toDateString()]) ? 7 : $date->dayOfWeekIso;

        return $shiftTemplate->staffingRules
            ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === $weekday)
            ?? $shiftTemplate->staffingRules
                ->first(fn (ShiftStaffingRule $rule): bool => $rule->weekday === null);
    }

    /**
     * Besetzungsregel der Kategorie an dem Tag (Wochentag-spezifisch vor Standard).
     * Feiertage zählen wie Sonntag (ISO 7).
     */
    public function categoryStaffingFor(string $category, CarbonImmutable $date): ?ShiftCategoryStaffingRule
    {
        $weekday = isset($this->holidays[$date->toDateString()]) ? 7 : $date->dayOfWeekIso;
        $rules = $this->categoryStaffingRules[$category] ?? [];

        $specific = null;
        $default = null;

        foreach ($rules as $rule) {
            if ($rule->weekday === $weekday) {
                $specific = $rule;
            } elseif ($rule->weekday === null) {
                $default = $rule;
            }
        }

        return $specific ?? $default;
    }

    /**
     * @return Collection<int, ShiftTemplate>
     */
    public function templatesForCategory(string $category): Collection
    {
        return $this->templatesByCategory[$category] ?? collect();
    }

    /**
     * Kategorien, für die es aktive Vorlagen gibt (early/late/night).
     *
     * @return array<int, string>
     */
    public function categoriesWithTemplates(): array
    {
        return array_keys($this->templatesByCategory);
    }

    public function slotCategoryTotal(CarbonImmutable $date, string $category): int
    {
        return $this->slotCategoryTotals[$date->toDateString().'|'.$category] ?? 0;
    }

    public function slotCategorySpecialistCount(CarbonImmutable $date, string $category): int
    {
        return $this->slotCategorySpecialists[$date->toDateString().'|'.$category] ?? 0;
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
            array_values($this->intervals[$employee->id] ?? []),
            $startsAt->getTimestamp(),
            $endsAt->getTimestamp(),
            $this->requiredRestMinutes,
        );
    }

    public function wouldExceedConsecutiveWorkDays(User $employee, CarbonImmutable $date): bool
    {
        $dateKey = $date->toDateString();
        $workDates = $this->workDates[$employee->id] ?? [];

        if (isset($workDates[$dateKey])) {
            // Der Tag ist bereits Arbeitstag, eine weitere Schicht ändert die Folge nicht.
            return false;
        }

        $runLength = 1;

        for ($day = $this->previousDayKeys[$dateKey] ?? null; $day !== null && isset($workDates[$day]); $day = $this->previousDayKeys[$day] ?? null) {
            $runLength++;
        }

        for ($day = $this->nextDayKeys[$dateKey] ?? null; $day !== null && isset($workDates[$day]); $day = $this->nextDayKeys[$day] ?? null) {
            $runLength++;
        }

        $maxConsecutive = $this->maxConsecutiveWorkDays;

        // Individuelles, strengeres Limit aus den Sonderregelungen des Mitarbeiters.
        $override = $employee->employeeProfile?->max_consecutive_days_override;
        if ($override !== null && $override > 0 && $override < $maxConsecutive) {
            $maxConsecutive = $override;
        }

        return $runLength > $maxConsecutive;
    }

    /**
     * Würde eine zusätzliche Nachtschicht an diesem Tag die zulässige Anzahl
     * aufeinanderfolgender Nachtdienste überschreiten? Es zählt der zusammen-
     * hängende Block aus Nachtdiensten (auch über Dienstpläne/Monatsgrenzen
     * hinweg, da Randfenster-Dienste mit Rotationsrang indiziert sind). Andere
     * Schichtarten unterbrechen den Block. 0 = Regel deaktiviert.
     */
    public function wouldExceedConsecutiveNightShifts(User $employee, CarbonImmutable $date): bool
    {
        if ($this->maxConsecutiveNightShifts <= 0) {
            return false;
        }

        $nightRank = self::ROTATION_RANKS['night'];
        $ranks = $this->rotationRanks[$employee->id] ?? [];
        $dateKey = $date->toDateString();

        // Liegt an dem Tag bereits eine Nachtschicht, ändert eine weitere die Folge nicht.
        if (isset($ranks[$dateKey][$nightRank])) {
            return false;
        }

        $runLength = 1;

        for ($day = $this->previousDayKeys[$dateKey] ?? null; $day !== null && isset($ranks[$day][$nightRank]); $day = $this->previousDayKeys[$day] ?? null) {
            $runLength++;
        }

        for ($day = $this->nextDayKeys[$dateKey] ?? null; $day !== null && isset($ranks[$day][$nightRank]); $day = $this->nextDayKeys[$day] ?? null) {
            $runLength++;
        }

        return $runLength > $this->maxConsecutiveNightShifts;
    }

    public function wouldExceedWeekendLoad(User $employee, CarbonImmutable $date): bool
    {
        $weekendKey = $this->dateMetaFor($date)['weekend'];

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
        return isset($this->assignedTemplates[$employee->id.'|'.$date->toDateString().'|'.$shiftTemplate->id]);
    }

    public function wouldExceedWeeklyMaxMinutes(User $employee, CarbonImmutable $date, int $shiftMinutes): bool
    {
        $weekKey = $this->dateMetaFor($date)['week'];
        $currentMinutes = $this->weeklyMinutes[$employee->id][$weekKey] ?? 0;

        return $currentMinutes + $shiftMinutes > $this->weeklyMaxMinutes;
    }

    public function wouldExceedDailyMaxMinutes(User $employee, CarbonImmutable $date, int $shiftMinutes): bool
    {
        $currentMinutes = $this->dailyMinutes[$employee->id][$date->toDateString()] ?? 0;

        return $currentMinutes + $shiftMinutes > $this->dailyMaxMinutes;
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

    public function nightShiftCountFor(User $employee): int
    {
        return $this->nightShiftCounts[$employee->id] ?? 0;
    }

    public function weekendCountFor(User $employee): int
    {
        return count($this->weekendKeys[$employee->id] ?? []);
    }

    public function hasWeekend(User $employee, string $weekendKey): bool
    {
        return isset($this->weekendKeys[$employee->id][$weekendKey]);
    }

    public function isWorkDate(User $employee, string $date): bool
    {
        return isset($this->workDates[$employee->id][$date]);
    }

    /**
     * Höchster Rotationsrang des Mitarbeiters an einem Tag oder null.
     */
    public function maxRotationRank(User $employee, string $date): ?int
    {
        $ranks = $this->rotationRanks[$employee->id][$date] ?? [];

        return $ranks === [] ? null : max(array_keys($ranks));
    }

    /**
     * Niedrigster Rotationsrang des Mitarbeiters an einem Tag oder null.
     */
    public function minRotationRank(User $employee, string $date): ?int
    {
        $ranks = $this->rotationRanks[$employee->id][$date] ?? [];

        return $ranks === [] ? null : min(array_keys($ranks));
    }

    public function hasWishFree(User $employee, string $date): bool
    {
        return isset($this->wishFreeDates[$employee->id][$date]);
    }

    /**
     * Wunschdienst des Mitarbeiters an einem Tag: true = Vorlage passt,
     * false = kein Wunsch oder andere Vorlage gewünscht.
     */
    public function fulfillsWishShift(User $employee, string $date, ShiftTemplate $shiftTemplate): bool
    {
        if (! array_key_exists($date, $this->wishShifts[$employee->id] ?? [])) {
            return false;
        }

        $wishedTemplateId = $this->wishShifts[$employee->id][$date];

        return $wishedTemplateId === null || $wishedTemplateId === $shiftTemplate->id;
    }

    /**
     * Anzahl der gearbeiteten Sonntage im Monat ohne bekannten freien
     * Ersatzruhetag im Ausgleichszeitraum (6 Tage davor bis 7 Tage danach).
     */
    public function sundayCompensationViolations(User $employee, ?string $extraWorkDate = null): int
    {
        $workDates = $this->workDates[$employee->id] ?? [];

        if ($extraWorkDate !== null) {
            $workDates[$extraWorkDate] = ($workDates[$extraWorkDate] ?? 0) + 1;
        }

        if ($workDates === []) {
            return 0;
        }

        $violations = 0;

        foreach ($this->monthSundayWindows as [$sunday, $windowDates]) {
            if (! isset($workDates[$sunday])) {
                continue;
            }

            $hasFreeDay = false;

            foreach ($windowDates as $windowDate) {
                if (! isset($workDates[$windowDate])) {
                    $hasFreeDay = true;
                    break;
                }
            }

            if (! $hasFreeDay) {
                $violations++;
            }
        }

        return $violations;
    }
}
