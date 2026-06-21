<?php

declare(strict_types=1);

namespace App\Enums;

/** Status einer Medikamentengabe. */
enum MedicationAdministrationStatus: string
{
    case Administered = 'administered';
    case Refused = 'refused';
    case Omitted = 'omitted';

    public function label(): string
    {
        return match ($this) {
            self::Administered => 'Verabreicht',
            self::Refused => 'Abgelehnt',
            self::Omitted => 'Nicht gegeben',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
