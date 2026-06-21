<?php

namespace App\Enums;

/**
 * Schicht-Kategorie (Früh/Spät/Nacht). Mehrere Schichtvorlagen können dieselbe
 * Kategorie haben (z. B. Früh1 6 h und Früh2 8 h). Die Kategorie steuert die
 * Fachlogik (Fähigkeit can_work_*, Mutterschutz-Nacht, Nacht-Zählung, Rotation),
 * während jede einzelne Vorlage eigene Zeiten/Stunden und Besetzung hat.
 */
enum ShiftCategory: string
{
    case Early = 'early';
    case Late = 'late';
    case Night = 'night';

    public function label(): string
    {
        return match ($this) {
            self::Early => 'Frühdienst',
            self::Late => 'Spätdienst',
            self::Night => 'Nachtdienst',
        };
    }

    /**
     * Rang für die Rotationslogik (Früh vor Spät vor Nacht).
     */
    public function rotationRank(): int
    {
        return match ($this) {
            self::Early => 0,
            self::Late => 1,
            self::Night => 2,
        };
    }

    /**
     * Standardfarbe als Vorschlag beim Anlegen einer Schicht der Kategorie.
     */
    public function defaultColor(): string
    {
        return match ($this) {
            self::Early => '#F59E0B',
            self::Late => '#3B82F6',
            self::Night => '#6366F1',
        };
    }
}
