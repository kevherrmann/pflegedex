<?php

namespace App\Services\Rosters\Planning;

use Carbon\CarbonImmutable;

/**
 * Reine Regel-Mathematik, die Generator und Validator gemeinsam nutzen,
 * damit beide Seiten nie unterschiedliche Regeln anwenden.
 */
class WorkRules
{
    /**
     * Samstag des Wochenendes, zu dem der Tag gehört, sonst null.
     */
    public static function weekendStartKey(CarbonImmutable $date): ?string
    {
        if ($date->dayOfWeekIso === 6) {
            return $date->toDateString();
        }

        if ($date->dayOfWeekIso === 7) {
            return $date->subDay()->toDateString();
        }

        return null;
    }

    /**
     * ISO-Kalenderwoche als Schlüssel, z. B. "2027-W01".
     */
    public static function isoWeekKey(CarbonImmutable $date): string
    {
        return sprintf('%04d-W%02d', $date->isoWeekYear, $date->isoWeek);
    }

    /**
     * Länge der zusammenhängenden Arbeitstage-Folge, die den Kandidatentag enthält.
     *
     * @param  array<string, true>  $workDates  bekannte Arbeitstage als Y-m-d Schlüssel
     */
    public static function consecutiveRunLengthContaining(array $workDates, CarbonImmutable $candidateDate): int
    {
        $length = 1;

        for ($date = $candidateDate->subDay(); isset($workDates[$date->toDateString()]); $date = $date->subDay()) {
            $length++;
        }

        for ($date = $candidateDate->addDay(); isset($workDates[$date->toDateString()]); $date = $date->addDay()) {
            $length++;
        }

        return $length;
    }

    /**
     * Prüft Überlappung oder zu kurze Ruhezeit gegen bekannte Dienstintervalle.
     *
     * @param  array<int, array{0: int, 1: int}>  $intervals  bekannte Dienste als [Start-, End-Timestamp]
     */
    public static function hasRestConflict(array $intervals, int $startsAtTimestamp, int $endsAtTimestamp, int $requiredRestMinutes): bool
    {
        $requiredRestSeconds = $requiredRestMinutes * 60;

        foreach ($intervals as [$existingStart, $existingEnd]) {
            if ($existingEnd <= $startsAtTimestamp) {
                $restSeconds = $startsAtTimestamp - $existingEnd;
            } elseif ($endsAtTimestamp <= $existingStart) {
                $restSeconds = $existingStart - $endsAtTimestamp;
            } else {
                return true;
            }

            if ($restSeconds < $requiredRestSeconds) {
                return true;
            }
        }

        return false;
    }
}
