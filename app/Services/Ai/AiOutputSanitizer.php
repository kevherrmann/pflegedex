<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Salutation;

/**
 * Bereinigt und prueft LLM-Ausgaben, bevor sie in SIS oder Massnahmenplan
 * uebernommen werden.
 *
 *  - Entfernt Markdown-Zaeune, umschliessende Anfuehrungszeichen und
 *    Antwort-Praefixe, die kleine Modelle gern voranstellen.
 *  - Erkennt den "NICHT_RELEVANT"-Sentinel robust (Gross-/Kleinschreibung,
 *    Unterstrich/Leerzeichen/Bindestrich, eingebettet in einen Satz).
 *  - Erzwingt die geschlechtsspezifische Personenbezeichnung: neutrale
 *    Schreibweisen ("Bewohner/in", "Bewohner:in", "BewohnerIn") werden
 *    mechanisch ersetzt; eine falsche Genus-Bezeichnung wird als Verstoss
 *    gemeldet, damit der Aufrufer einen erneuten Versuch starten kann.
 */
class AiOutputSanitizer
{
    private const NOT_APPLICABLE_PATTERN = '/\bNICHT[\s_-]?RELEVANT\b/iu';

    public function sanitize(string $raw): string
    {
        $text = trim($raw);

        // Markdown-Codezaeune entfernen (```text ... ``` oder ``` ... ```).
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/su', $text, $matches) === 1) {
            $text = $matches[1];
        }

        $text = preg_replace('/^```[a-z]*\s*/u', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/u', '', $text) ?? $text;

        // Antwort-Praefixe kleiner Modelle ("Antwort:", "Text:", "Ausgabe:").
        $text = preg_replace('/^(Antwort|Text|Ausgabe|Ergebnis)\s*:\s*/iu', '', trim($text)) ?? $text;

        // Umschliessende Anfuehrungszeichen.
        $text = trim($text);
        if (preg_match('/^(["\'„»])(.*)(["\'“«])$/su', $text, $matches) === 1) {
            $text = $matches[2];
        }

        return trim($text);
    }

    /**
     * Erkennt den Sentinel auch eingebettet ("NICHT RELEVANT, weil ...").
     */
    public function isNotApplicable(string $output): bool
    {
        if (trim($output) === '') {
            return true;
        }

        return preg_match(self::NOT_APPLICABLE_PATTERN, $output) === 1;
    }

    /**
     * Erzwingt die korrekte Personenbezeichnung.
     *
     * @return array{text: string, violations: list<string>}
     */
    public function enforceSalutation(string $text, Salutation $salutation): array
    {
        $violations = [];

        // Neutrale Schreibweisen mechanisch durch das korrekte Substantiv
        // ersetzen (nur das Substantiv, damit Artikel und Satzanfang halten).
        $neutralPattern = '/\bBewohner\s*(?:\/|:|\*)\s*in\b|\bBewohnerIn\b/u';

        if (preg_match($neutralPattern, $text) === 1) {
            $violations[] = 'neutral_term_replaced';
            $replacement = $salutation === Salutation::Herr ? 'Bewohner' : 'Bewohnerin';
            $text = preg_replace($neutralPattern, $replacement, $text) ?? $text;
        }

        // Falsches Genus melden (nicht mechanisch ersetzen: Pronomen und
        // Deklination im Satz wuerden sonst inkonsistent).
        $wrongTermPattern = $salutation === Salutation::Herr
            ? '/\bBewohnerin\b/u'
            : '/\bBewohner\b(?!in)/u';

        if (preg_match($wrongTermPattern, $text) === 1) {
            $violations[] = 'wrong_gender_term';
        }

        return ['text' => trim($text), 'violations' => $violations];
    }
}
