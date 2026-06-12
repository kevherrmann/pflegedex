<?php

namespace App\Services\Rosters\Planning;

use App\Models\EmployeeProfile;
use Carbon\CarbonImmutable;

/**
 * Monatliche Soll-Kapazität in Minuten = das Strengere aus Wochenstunden
 * und Regel-Arbeitstagen. Schichten sind feste Blöcke, daher bindet bei
 * Teilzeit oft die vereinbarte Tageszahl statt der Stunden.
 */
class TargetMinutesCalculator
{
    public function monthlyTargetMinutes(?EmployeeProfile $profile, int $year, int $month, int $shiftMinutes): int
    {
        $daysInMonth = CarbonImmutable::create($year, $month, 1)->daysInMonth;

        return $this->targetMinutes($profile, $daysInMonth / 7, $shiftMinutes);
    }

    public function targetMinutes(?EmployeeProfile $profile, float $weeksFactor, int $shiftMinutes): int
    {
        $targets = [];

        $weeklyHours = (float) ($profile?->weekly_hours ?? 0);
        if ($weeklyHours > 0) {
            $targets[] = (int) round($weeklyHours * 60 * $weeksFactor);
        }

        $regularDays = (int) ($profile?->regular_work_days_per_week ?? 0);
        if ($regularDays > 0 && $shiftMinutes > 0) {
            $targets[] = (int) round($regularDays * $shiftMinutes * $weeksFactor);
        }

        return $targets === [] ? 0 : min($targets);
    }
}
