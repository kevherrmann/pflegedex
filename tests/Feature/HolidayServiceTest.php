<?php

declare(strict_types=1);

use App\Services\Rosters\HolidayService;
use Carbon\CarbonImmutable;

it('liefert die bundesweiten Feiertage', function (): void {
    $service = new HolidayService;
    $holidays = $service->holidaysForYear(2027, null);

    expect($holidays)->toHaveKey('2027-01-01')   // Neujahr
        ->toHaveKey('2027-05-01')                 // Tag der Arbeit
        ->toHaveKey('2027-10-03')                 // Deutsche Einheit
        ->toHaveKey('2027-12-25')
        ->toHaveKey('2027-12-26');

    // Bewegliche Feiertage liegen auf den korrekten Wochentagen.
    $karfreitag = array_search('Karfreitag', $holidays, true);
    $ostermontag = array_search('Ostermontag', $holidays, true);
    $himmelfahrt = array_search('Christi Himmelfahrt', $holidays, true);

    expect(CarbonImmutable::parse($karfreitag)->isFriday())->toBeTrue()
        ->and(CarbonImmutable::parse($ostermontag)->isMonday())->toBeTrue()
        ->and(CarbonImmutable::parse($himmelfahrt)->isThursday())->toBeTrue();
});

it('berücksichtigt bundeslandspezifische Feiertage', function (): void {
    $service = new HolidayService;

    // Allerheiligen: Feiertag in Bayern, nicht in Berlin.
    expect($service->isHoliday(CarbonImmutable::parse('2027-11-01'), 'BY'))->toBeTrue()
        ->and($service->isHoliday(CarbonImmutable::parse('2027-11-01'), 'BE'))->toBeFalse();

    // Reformationstag: in Brandenburg, nicht in Bayern.
    expect($service->isHoliday(CarbonImmutable::parse('2027-10-31'), 'BB'))->toBeTrue()
        ->and($service->isHoliday(CarbonImmutable::parse('2027-10-31'), 'BY'))->toBeFalse();
});
