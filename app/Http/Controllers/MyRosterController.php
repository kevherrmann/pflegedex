<?php

namespace App\Http\Controllers;

use App\Enums\AbsenceRequestStatus;
use App\Enums\RosterStatus;
use App\Models\AbsenceRequest;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Services\Rosters\Planning\TargetMinutesCalculator;
use App\Services\Rosters\Planning\WorkRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mein Dienstplan": Selbstauskunft für Mitarbeiter.
 *
 * Mitarbeiter sehen ausschließlich ihre eigenen Dienste aus veröffentlichten
 * oder gesperrten Dienstplänen — Entwürfe sind Arbeitsstand der PDL und
 * für die Belegschaft noch nicht verbindlich. Genehmigte Abwesenheiten
 * werden im Monat mit angezeigt.
 */
class MyRosterController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        abort_unless(
            (bool) ($user?->employeeProfile?->active ?? false),
            HttpResponse::HTTP_FORBIDDEN,
        );

        $month = $this->resolveMonth($request);
        $monthStart = $month;
        $monthEnd = $month->endOfMonth()->startOfDay();

        // Nur veroeffentlichte/gesperrte Plaene sind fuer Mitarbeiter verbindlich.
        $visibleStatuses = [RosterStatus::Published->value, RosterStatus::Locked->value];

        $shifts = Shift::query()
            ->with(['shiftTemplate', 'location', 'roster'])
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', $monthStart->toDateString())
            ->whereDate('date', '<=', $monthEnd->toDateString())
            ->whereHas('roster', fn ($query) => $query->whereIn('status', $visibleStatuses))
            ->orderBy('starts_at')
            ->get();

        $absences = AbsenceRequest::query()
            ->where('user_id', $user->id)
            ->where('status', AbsenceRequestStatus::Approved->value)
            ->whereDate('starts_on', '<=', $monthEnd->toDateString())
            ->whereDate('ends_on', '>=', $monthStart->toDateString())
            ->orderBy('starts_on')
            ->get();

        // Hinweis, wenn fuer den Monat zwar geplant wird, aber noch nichts
        // veroeffentlicht ist (Plan des eigenen Wohnbereichs in Arbeit).
        $hasUnpublishedRoster = Roster::query()
            ->where('location_id', $user->location_id)
            ->where('year', $month->year)
            ->where('month', $month->month)
            ->whereNotIn('status', $visibleStatuses)
            ->exists();

        $teamShifts = $this->loadTeamShifts($user, $shifts, $monthStart, $monthEnd, $visibleStatuses);

        return Inertia::render('MyRoster/Show', [
            'month' => [
                'year' => $month->year,
                'month' => $month->month,
                'label' => $month->locale('de')->isoFormat('MMMM YYYY'),
                'previous' => $month->subMonth()->format('Y-m'),
                'next' => $month->addMonth()->format('Y-m'),
                'daysInMonth' => $month->daysInMonth,
            ],
            'days' => $this->buildDays($monthStart, $monthEnd, $shifts, $absences, $teamShifts),
            'summary' => $this->buildSummary($user, $month, $shifts),
            'hasUnpublishedRoster' => $hasUnpublishedRoster,
        ]);
    }

    private function resolveMonth(Request $request): CarbonImmutable
    {
        $requested = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1) {
            [$year, $month] = array_map('intval', explode('-', $requested));

            return CarbonImmutable::create($year, $month, 1)->startOfDay();
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * Dienste der Kolleginnen und Kollegen im eigenen Wohnbereich — an allen
     * Tagen des Monats, damit Diensttausch-Absprachen möglich sind (wie der
     * ausgehängte Plan am Schwarzen Brett). Nur aus veröffentlichten bzw.
     * gesperrten Plänen, denn nur die sind verbindlich.
     *
     * @param  Collection<int, Shift>  $ownShifts
     * @param  array<int, string>  $visibleStatuses
     * @return Collection<int, Shift>
     */
    private function loadTeamShifts(
        $user,
        $ownShifts,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        array $visibleStatuses,
    ) {
        $locationIds = $ownShifts
            ->pluck('location_id')
            ->push($user->location_id)
            ->filter()
            ->unique();

        if ($locationIds->isEmpty()) {
            return collect();
        }

        return Shift::query()
            ->with(['shiftTemplate', 'user'])
            ->where('user_id', '!=', $user->id)
            ->whereIn('location_id', $locationIds)
            ->whereDate('date', '>=', $monthStart->toDateString())
            ->whereDate('date', '<=', $monthEnd->toDateString())
            ->whereHas('roster', fn ($query) => $query->whereIn('status', $visibleStatuses))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, AbsenceRequest>  $absences
     * @param  Collection<int, Shift>  $teamShifts
     * @return array<int, array<string, mixed>>
     */
    private function buildDays(
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        $shifts,
        $absences,
        $teamShifts,
    ): array {
        $shiftsByDate = $shifts->groupBy(
            fn (Shift $shift): string => CarbonImmutable::parse($shift->date)->toDateString(),
        );

        $teamShiftsByDate = $teamShifts->groupBy(
            fn (Shift $shift): string => CarbonImmutable::parse($shift->date)->toDateString(),
        );

        $days = [];

        for ($date = $monthStart; $date->lessThanOrEqualTo($monthEnd); $date = $date->addDay()) {
            $dateKey = $date->toDateString();

            $dayAbsence = $absences->first(fn (AbsenceRequest $absence): bool => $absence->starts_on->toDateString() <= $dateKey
                && $absence->ends_on->toDateString() >= $dateKey);

            $days[] = [
                'date' => $dateKey,
                'dayLabel' => $date->locale('de')->isoFormat('DD.MM.'),
                'weekdayLabel' => $date->locale('de')->isoFormat('dd'),
                'isWeekend' => $date->isWeekend(),
                'isToday' => $date->isToday(),
                'shifts' => $shiftsByDate->get($dateKey, collect())
                    ->map(fn (Shift $shift): array => [
                        'id' => $shift->id,
                        'shiftTemplateName' => $shift->shiftTemplate?->name,
                        'shiftTemplateCode' => $shift->shiftTemplate?->code,
                        'shiftTemplateColor' => $shift->shiftTemplate?->color,
                        'locationName' => $shift->location?->name,
                        'startsAt' => $shift->starts_at->format('H:i'),
                        'endsAt' => $shift->ends_at->format('H:i'),
                        'minutes' => (int) $shift->starts_at->diffInMinutes($shift->ends_at, true),
                        'note' => $shift->note,
                        'isLocked' => $shift->roster?->status === RosterStatus::Locked,
                    ])
                    ->values()
                    ->all(),
                'absence' => $dayAbsence === null ? null : [
                    'typeLabel' => $dayAbsence->type->label(),
                    'startsOn' => $dayAbsence->starts_on->toDateString(),
                    'endsOn' => $dayAbsence->ends_on->toDateString(),
                ],
                'team' => $this->buildTeamForDay(
                    $shiftsByDate->get($dateKey, collect()),
                    $teamShiftsByDate->get($dateKey, collect()),
                ),
            ];
        }

        return $days;
    }

    /**
     * Tagesbesetzung des Wohnbereichs, nach Schichtvorlage gruppiert. An
     * eigenen Arbeitstagen beantwortet sie "Mit wem arbeite ich zusammen?",
     * an freien Tagen "Wer ist im Dienst?" — beides braucht es, damit
     * Kolleginnen und Kollegen Diensttausche absprechen können.
     *
     * @param  Collection<int, Shift>  $ownDayShifts
     * @param  Collection<int, Shift>  $dayTeamShifts
     * @return array<int, array<string, mixed>>
     */
    private function buildTeamForDay($ownDayShifts, $dayTeamShifts): array
    {
        $ownTemplateIds = $ownDayShifts->pluck('shift_template_id')->unique();

        return $dayTeamShifts
            ->groupBy('shift_template_id')
            ->map(fn ($templateShifts): array => [
                'shiftTemplateName' => $templateShifts->first()->shiftTemplate?->name,
                'shiftTemplateCode' => $templateShifts->first()->shiftTemplate?->code,
                'shiftTemplateColor' => $templateShifts->first()->shiftTemplate?->color,
                'startsAt' => $templateShifts->first()->starts_at->format('H:i'),
                'endsAt' => $templateShifts->first()->ends_at->format('H:i'),
                'isOwnShift' => $ownTemplateIds->contains($templateShifts->first()->shift_template_id),
                'colleagues' => $templateShifts
                    ->map(fn (Shift $shift): ?string => $shift->user?->name)
                    ->filter()
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->sortBy('startsAt')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array<string, mixed>
     */
    private function buildSummary($user, CarbonImmutable $month, $shifts): array
    {
        $plannedMinutes = (int) $shifts->sum(
            fn (Shift $shift): int => (int) $shift->starts_at->diffInMinutes($shift->ends_at, true),
        );

        $weekendKeys = $shifts
            ->map(fn (Shift $shift): ?string => WorkRules::weekendStartKey(
                CarbonImmutable::parse($shift->date)->startOfDay(),
            ))
            ->filter()
            ->unique();

        $nightShifts = $shifts
            ->filter(fn (Shift $shift): bool => $shift->shiftTemplate?->code === 'night')
            ->count();

        // Repraesentative Schichtlaenge wie in Generator und Validator:
        // Durchschnitt der aktiven Vorlagen des eigenen Wohnbereichs.
        $shiftMinutes = (int) round((float) ShiftTemplate::query()
            ->where('location_id', $user->location_id)
            ->where('active', true)
            ->avg('duration_minutes'));
        $shiftMinutes = $shiftMinutes > 0 ? $shiftMinutes : (int) config('rostering.default_shift_minutes');

        $targetMinutes = (new TargetMinutesCalculator)->monthlyTargetMinutes(
            $user->employeeProfile,
            $month->year,
            $month->month,
            $shiftMinutes,
        );

        return [
            'shiftCount' => $shifts->count(),
            'plannedMinutes' => $plannedMinutes,
            'targetMinutes' => $targetMinutes,
            'weekends' => $weekendKeys->count(),
            'nightShifts' => $nightShifts,
            'workDays' => $shifts
                ->map(fn (Shift $shift): string => CarbonImmutable::parse($shift->date)->toDateString())
                ->unique()
                ->count(),
        ];
    }
}
