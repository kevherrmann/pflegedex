<?php

namespace App\Enums;

/**
 * Geltungsbereich einer Urlaubssperre. Eine Sperre kann den ganzen Wohnbereich
 * betreffen oder gezielt nur bestimmte Qualifikationsstufen bzw. einzelne
 * Mitarbeiter. So lassen sich rechtlich heikle Pauschalsperren vermeiden und
 * z. B. nur Fachkraefte oder benannte Personen sperren.
 */
enum BlackoutScope: string
{
    case All = 'all';
    case Qualification = 'qualification';
    case Employees = 'employees';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Ganzer Wohnbereich',
            self::Qualification => 'Bestimmte Qualifikation',
            self::Employees => 'Bestimmte Mitarbeiter',
        };
    }
}
