<?php

declare(strict_types=1);

namespace App\Enums;

/** Aufenthaltsstatus eines Bewohners. */
enum ResidentStatus: string
{
    case Present = 'present';
    case OnLeave = 'on_leave';
    case Hospital = 'hospital';
    case Discharged = 'discharged';
    case Deceased = 'deceased';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Anwesend',
            self::OnLeave => 'Beurlaubt',
            self::Hospital => 'Krankenhaus',
            self::Discharged => 'Entlassen',
            self::Deceased => 'Verstorben',
        };
    }

    /** Status, bei denen der Bewohner aktiv in der Einrichtung geführt wird. */
    public function isActive(): bool
    {
        return in_array($this, [self::Present, self::OnLeave, self::Hospital], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
