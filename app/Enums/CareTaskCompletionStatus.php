<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status einer Massnahmen-Durchfuehrung (Leistungsnachweis): wurde die geplante
 * Massnahme an einem Tag geleistet, abgelehnt, nicht benoetigt oder ausgelassen?
 */
enum CareTaskCompletionStatus: string
{
    case Done = 'done';
    case Refused = 'refused';
    case NotNeeded = 'not_needed';
    case Omitted = 'omitted';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Durchgeführt',
            self::Refused => 'Abgelehnt',
            self::NotNeeded => 'Nicht erforderlich',
            self::Omitted => 'Nicht durchgeführt',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
