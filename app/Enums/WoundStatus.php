<?php

declare(strict_types=1);

namespace App\Enums;

/** Status einer Wunde. */
enum WoundStatus: string
{
    case Open = 'open';
    case Healing = 'healing';
    case Healed = 'healed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Offen',
            self::Healing => 'In Abheilung',
            self::Healed => 'Abgeheilt',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
