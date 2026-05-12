<?php

namespace App\Enums;

enum EmploymentArea: string
{
    case Nursing = 'nursing';
    case Cleaning = 'cleaning';
    case Caretaker = 'caretaker';
    case Pdl = 'pdl';

    public function label(): string
    {
        return match ($this) {
            self::Nursing => 'Pflege',
            self::Cleaning => 'Putzkraft',
            self::Caretaker => 'Hausmeister',
            self::Pdl => 'PDL',
        };
    }

    public function canRequestAbsence(): bool
    {
        return in_array($this, [
            self::Nursing,
            self::Cleaning,
        ], true);
    }
}
