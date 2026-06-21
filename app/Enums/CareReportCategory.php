<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pflegebericht-Kategorien.
 *
 * Werte sind die in DB und UI sichtbaren deutschen Bezeichnungen.
 * Case-Namen sind ASCII (PHP-Identifier-Regeln), Werte deutsch mit Umlauten.
 */
enum CareReportCategory: string
{
    case Grundpflege = 'Grundpflege';
    case Beobachtung = 'Beobachtung';
    case Mobilitaet = 'Mobilität';
    case Medikation = 'Medikation';
    case Uebergabe = 'Übergabe';
    case Sonstiges = 'Sonstiges';

    /**
     * Geordnete Liste aller Werte fuer Tabs/Tabs-Sortierung in der UI.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }
}
