<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Themenbloecke des Massnahmenplans laut Handlungsanleitung.
 *
 * Reihenfolge entspricht der Doku "Handlungsanleitung Massnahmenplan".
 * Anders als bei der SIS sind diese Themen NICHT alle pflicht: pro
 * Bewohner werden nur die Themen gefuellt, die fachlich relevant sind
 * (on-demand, siehe Maerkblatt der Doku).
 */
enum CarePlanTopic: int
{
    case Mobilitaet = 1;
    case Ernaehrung = 2;
    case Kontinenz = 3;
    case Koerperpflege = 4;
    case Medikation = 5;
    case Schmerz = 6;
    case Wundversorgung = 7;
    case BesondereBedarfslagen = 8;
    case SonstigeTherapie = 9;
    case Sinneswahrnehmung = 10;
    case Tagesstruktur = 11;
    case Nacht = 12;
    case Eingewoehnung = 13;
    case Krankenhausueberleitung = 14;
    case HerausforderndesVerhalten = 15;
    case FreiheitsentziehendeMassnahmen = 16;

    public function label(): string
    {
        return match ($this) {
            self::Mobilitaet => 'Mobilität und Beweglichkeit',
            self::Ernaehrung => 'Ernährung und Flüssigkeit',
            self::Kontinenz => 'Kontinenzverlust, Kontinenzförderung',
            self::Koerperpflege => 'Körperpflege',
            self::Medikation => 'Medikamentöse Therapie',
            self::Schmerz => 'Schmerzmanagement',
            self::Wundversorgung => 'Wundversorgung',
            self::BesondereBedarfslagen => 'Besondere medizinisch-pflegerische Bedarfslagen',
            self::SonstigeTherapie => 'Sonstige therapiebedingte Anforderungen',
            self::Sinneswahrnehmung => 'Beeinträchtigung der Sinneswahrnehmung',
            self::Tagesstruktur => 'Tagesstrukturierung, Beschäftigung, Kommunikation',
            self::Nacht => 'Nächtliche Versorgung',
            self::Eingewoehnung => 'Eingewöhnungsphase nach Einzug',
            self::Krankenhausueberleitung => 'Überleitung bei Krankenhausaufenthalten',
            self::HerausforderndesVerhalten => 'Herausforderndes Verhalten und psychische Problemlagen',
            self::FreiheitsentziehendeMassnahmen => 'Freiheitsentziehende Maßnahmen',
        };
    }

    /**
     * @return list<int>
     */
    public static function numbers(): array
    {
        return array_map(fn (self $t): int => $t->value, self::cases());
    }
}
