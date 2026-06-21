<?php

declare(strict_types=1);

namespace App\Services\Rosters;

use Carbon\CarbonImmutable;

/**
 * Gesetzliche Feiertage in Deutschland, bundeslandabhängig.
 *
 * Bundesweite Feiertage gelten überall; weitere je Bundesland-Kürzel
 * (BW, BY, BE, BB, HB, HH, HE, MV, NI, NW, RP, SL, SN, ST, SH, TH).
 * Bewegliche Feiertage werden aus dem Osterdatum (Gauß/Meeus) berechnet —
 * ohne externe Abhängigkeit.
 */
class HolidayService
{
    /**
     * @return array<string, string> [Y-m-d => Name] aller Feiertage des Jahres im Bundesland.
     */
    public function holidaysForYear(int $year, ?string $state): array
    {
        $state = $state !== null ? strtoupper($state) : null;
        $easter = $this->easterSunday($year);

        $holidays = [
            $this->ymd($year, 1, 1) => 'Neujahr',
            $this->ymd($year, 5, 1) => 'Tag der Arbeit',
            $this->ymd($year, 10, 3) => 'Tag der Deutschen Einheit',
            $this->ymd($year, 12, 25) => '1. Weihnachtstag',
            $this->ymd($year, 12, 26) => '2. Weihnachtstag',
            $easter->subDays(2)->toDateString() => 'Karfreitag',
            $easter->addDay()->toDateString() => 'Ostermontag',
            $easter->addDays(39)->toDateString() => 'Christi Himmelfahrt',
            $easter->addDays(50)->toDateString() => 'Pfingstmontag',
        ];

        if ($state === null) {
            return $holidays;
        }

        // Heilige Drei Könige
        if (in_array($state, ['BW', 'BY', 'ST'], true)) {
            $holidays[$this->ymd($year, 1, 6)] = 'Heilige Drei Könige';
        }

        // Internationaler Frauentag
        if (in_array($state, ['BE', 'MV'], true)) {
            $holidays[$this->ymd($year, 3, 8)] = 'Internationaler Frauentag';
        }

        // Fronleichnam (Ostern + 60)
        if (in_array($state, ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'], true)) {
            $holidays[$easter->addDays(60)->toDateString()] = 'Fronleichnam';
        }

        // Mariä Himmelfahrt
        if ($state === 'SL') {
            $holidays[$this->ymd($year, 8, 15)] = 'Mariä Himmelfahrt';
        }

        // Weltkindertag
        if ($state === 'TH') {
            $holidays[$this->ymd($year, 9, 20)] = 'Weltkindertag';
        }

        // Reformationstag
        if (in_array($state, ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH'], true)) {
            $holidays[$this->ymd($year, 10, 31)] = 'Reformationstag';
        }

        // Allerheiligen
        if (in_array($state, ['BW', 'BY', 'NW', 'RP', 'SL'], true)) {
            $holidays[$this->ymd($year, 11, 1)] = 'Allerheiligen';
        }

        // Buß- und Bettag (Mittwoch vor dem 23. November)
        if ($state === 'SN') {
            $bbt = CarbonImmutable::create($year, 11, 23)->previous(CarbonImmutable::WEDNESDAY);
            $holidays[$bbt->toDateString()] = 'Buß- und Bettag';
        }

        return $holidays;
    }

    public function isHoliday(CarbonImmutable $date, ?string $state): bool
    {
        return array_key_exists($date->toDateString(), $this->holidaysForYear((int) $date->year, $state));
    }

    private function ymd(int $year, int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Ostersonntag nach der anonymen gregorianischen (Gauß/Meeus) Berechnung. */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
