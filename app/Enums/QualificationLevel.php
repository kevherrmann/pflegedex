<?php

namespace App\Enums;

/**
 * Qualifikationsstufe einer Pflegekraft im Sinne des Personalbemessungs-
 * verfahrens (PeBeM nach Paragraph 113c SGB XI). Bildet den Qualifikationsmix
 * ab, der die alte starre Fachkraftquote abgeloest hat.
 */
enum QualificationLevel: string
{
    // Examinierte Pflegefachkraft (QN 4), z. B. Altenpfleger, Gesundheits- und
    // Krankenpfleger. Auch Wohnbereichsleitungen sind in der Regel Fachkraefte.
    case Specialist = 'specialist';

    // Pflegeassistent (QN 3) mit ein- bis zweijaehriger Ausbildung.
    case Assistant = 'assistant';

    // Pflegehilfskraft (QN 1/2), angelernt oder ohne pflegerische Ausbildung.
    case Aide = 'aide';

    public function label(): string
    {
        return match ($this) {
            self::Specialist => 'Pflegefachkraft',
            self::Assistant => 'Pflegeassistent',
            self::Aide => 'Pflegehilfskraft',
        };
    }

    /**
     * Examinierte Fachkraft. Steuert den vom Dienstplan-Generator und Validator
     * genutzten Fachkraftbedarf (is_nursing_specialist).
     */
    public function isSpecialist(): bool
    {
        return $this === self::Specialist;
    }
}
