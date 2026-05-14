<?php

namespace App\Enums;

enum RosterStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Reviewed = 'reviewed';
    case Published = 'published';
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Generated => 'Generiert',
            self::Reviewed => 'Geprüft',
            self::Published => 'Veröffentlicht',
            self::Locked => 'Gesperrt',
        };
    }
}
