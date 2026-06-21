<?php

namespace App\Services\Rosters;

use App\Models\Roster;
use App\Models\ShiftTemplate;
use Carbon\CarbonImmutable;

class RosterDateService
{
    /**
     * @return array<int, CarbonImmutable>
     */
    public function datesForRosterMonth(Roster $roster): array
    {
        $firstDay = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $days = [];

        for ($date = $firstDay; $date->month === $roster->month; $date = $date->addDay()) {
            $days[] = $date;
        }

        return $days;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function buildShiftTimes(CarbonImmutable $shiftDate, ShiftTemplate $shiftTemplate): array
    {
        $startsAt = CarbonImmutable::parse($shiftDate->toDateString().' '.$shiftTemplate->starts_at);
        $endsAt = CarbonImmutable::parse($shiftDate->toDateString().' '.$shiftTemplate->ends_at);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }

    public function isDateInRosterMonth(Roster $roster, CarbonImmutable $date): bool
    {
        return $date->year === $roster->year && $date->month === $roster->month;
    }
}
