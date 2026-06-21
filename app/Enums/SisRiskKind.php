<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Risikoarten in der SIS-Risikomatrix lt. Beikirch/Roes-Konzept.
 *
 * Werte sind ASCII (DB-Spalte string), Labels sind die in der UI
 * sichtbaren deutschen Bezeichnungen.
 */
enum SisRiskKind: string
{
    case Dekubitus = 'dekubitus';
    case Sturz = 'sturz';
    case Inkontinenz = 'inkontinenz';
    case Schmerz = 'schmerz';
    case Ernaehrung = 'ernaehrung';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Dekubitus => 'Dekubitus',
            self::Sturz => 'Sturz',
            self::Inkontinenz => 'Inkontinenz',
            self::Schmerz => 'Schmerz',
            self::Ernaehrung => 'Ernährung',
            self::Sonstiges => 'Sonstiges',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }
}
