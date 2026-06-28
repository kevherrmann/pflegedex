<?php

namespace App\Services\Rosters;

use App\Enums\EmploymentArea;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Rosters\Planning\PlanningContext;
use App\Services\Rosters\Planning\TargetMinutesCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Bucht beim Veröffentlichen eines Dienstplans die Differenz (geplante Minuten −
 * Monats-Soll) pro Mitarbeiter auf das Überstundenkonto und macht das beim
 * Wieder-Öffnen rückgängig. Der Saldo fließt im Folgemonat über
 * {@see PlanningContext} ins reduzierte/erhöhte Soll ein.
 */
class OvertimeBookingService
{
    public function bookForRoster(Roster $roster): void
    {
        if ($roster->overtime_booked_at !== null) {
            return; // Schon gebucht – Doppelbuchung vermeiden.
        }

        DB::transaction(function () use ($roster): void {
            foreach ($this->deltasByEmployee($roster) as $userId => $delta) {
                User::query()->whereKey($userId)->first()?->employeeProfile?->increment(
                    'overtime_minutes_balance',
                    $delta,
                );
            }

            $roster->forceFill(['overtime_booked_at' => now()])->save();
        });
    }

    public function reverseForRoster(Roster $roster): void
    {
        if ($roster->overtime_booked_at === null) {
            return; // Nichts gebucht.
        }

        DB::transaction(function () use ($roster): void {
            foreach ($this->deltasByEmployee($roster) as $userId => $delta) {
                User::query()->whereKey($userId)->first()?->employeeProfile?->decrement(
                    'overtime_minutes_balance',
                    $delta,
                );
            }

            $roster->forceFill(['overtime_booked_at' => null])->save();
        });
    }

    /**
     * Differenz (geplante Minuten − Monats-Soll) je Mitarbeiter mit Diensten im Plan.
     *
     * @return array<string, int>
     */
    private function deltasByEmployee(Roster $roster): array
    {
        $representativeShiftMinutes = (int) round((float) ShiftTemplate::query()
            ->where('location_id', $roster->location_id)
            ->where('active', true)
            ->avg('duration_minutes') ?? 0);

        if ($representativeShiftMinutes <= 0) {
            $representativeShiftMinutes = (int) config('rostering.default_shift_minutes');
        }

        $plannedByUser = Shift::query()
            ->where('roster_id', $roster->id)
            ->get(['user_id', 'starts_at', 'ends_at'])
            ->groupBy('user_id')
            ->map(fn ($shifts): int => (int) $shifts->sum(
                fn (Shift $shift): int => (int) $shift->starts_at->diffInMinutes($shift->ends_at, true),
            ));

        $calculator = new TargetMinutesCalculator;
        $deltas = [];

        $employees = User::query()
            ->with('employeeProfile')
            ->whereIn('id', $plannedByUser->keys())
            ->whereHas('employeeProfile', fn ($query) => $query
                ->where('active', true)
                ->where('employment_area', EmploymentArea::Nursing->value))
            ->get();

        foreach ($employees as $employee) {
            $baseTarget = $calculator->monthlyTargetMinutes(
                $employee->employeeProfile,
                $roster->year,
                $roster->month,
                $representativeShiftMinutes,
            );

            $deltas[$employee->id] = (int) $plannedByUser->get($employee->id, 0) - $baseTarget;
        }

        return $deltas;
    }
}
