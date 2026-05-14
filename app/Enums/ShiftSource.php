<?php

namespace App\Enums;

enum ShiftSource: string
{
    case Manual = 'manual';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuell',
            self::Auto => 'Automatisch',
        };
    }
}
