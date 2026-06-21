<?php

declare(strict_types=1);

namespace App\Enums;

/** Einnahmezeitpunkt (Stellzeit) bzw. Bedarf. */
enum MedicationSlot: string
{
    case Morning = 'morning';
    case Noon = 'noon';
    case Evening = 'evening';
    case Night = 'night';
    case Prn = 'prn';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Morgens',
            self::Noon => 'Mittags',
            self::Evening => 'Abends',
            self::Night => 'Nachts',
            self::Prn => 'Bei Bedarf',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
