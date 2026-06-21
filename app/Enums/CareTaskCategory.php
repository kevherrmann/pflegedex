<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kategorie einer geplanten Pflegemassnahme (Leistung) im Durchfuehrungsnachweis.
 * Case-Werte sind ASCII-Slugs (DB), label() liefert die deutsche Bezeichnung (UI).
 */
enum CareTaskCategory: string
{
    case Grundpflege = 'grundpflege';
    case Behandlungspflege = 'behandlungspflege';
    case Mobilitaet = 'mobilitaet';
    case Ernaehrung = 'ernaehrung';
    case Betreuung = 'betreuung';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Grundpflege => 'Grundpflege',
            self::Behandlungspflege => 'Behandlungspflege',
            self::Mobilitaet => 'Mobilität',
            self::Ernaehrung => 'Ernährung',
            self::Betreuung => 'Betreuung',
            self::Sonstiges => 'Sonstiges',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
