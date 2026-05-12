<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\CarePlanTopic;
use App\Enums\Salutation;
use App\Models\Sis;

/**
 * Leitet einen Massnahmenplan aus einer fertiggestellten SIS ab.
 *
 * Arbeitsweise (analog SisFormulator):
 *   - Sequenziell pro Feld: erst Grundbotschaft, dann 16 Themenbloecke
 *     in der Reihenfolge laut Handlungsanleitung.
 *   - Fuer jeden Schritt wird ein eigener Ollama-Call gemacht
 *     (kleinere Prompts = bessere Qualitaet bei e2b-Modellen).
 *   - Pro Themenblock bekommt das LLM den kompletten SIS-Kontext
 *     (alle 6 Themenfelder + alle Risiken), aber wird angewiesen,
 *     gezielt fuer den jeweiligen MP-Themenblock zu formulieren.
 *
 * Wichtig: Das LLM darf einen leeren String zurueckliefern, wenn
 * aus der SIS fuer diesen Themenblock keine konkrete Massnahme
 * ableitbar ist. Die Doku sagt explizit: nicht alle 16 Themen sind
 * pro Bewohner relevant.
 *
 * Nicht ausformuliert wird: Risiken (sind in der SIS bereits durch
 * Ja/Nein und Notizen abgedeckt). Sie fliessen in den Kontext mit ein.
 */
class CarePlanFormulator
{
    /** Sentinel-String. Wenn das LLM den ausgibt, weiss der Job: Thema ueberspringen. */
    private const NOT_APPLICABLE_TOKEN = 'NICHT_RELEVANT';

    public function __construct(
        private readonly OllamaClient $ollama,
    ) {
    }

    /**
     * Liefert die geordneten Felder eines neuen MP fuer den Job.
     *
     * @return list<array{key: string, label: string}>
     */
    public function fieldsForCarePlan(): array
    {
        $fields = [
            ['key' => 'grundbotschaft', 'label' => 'Grundbotschaft'],
        ];

        foreach (CarePlanTopic::cases() as $topic) {
            $fields[] = [
                'key' => 'topic_'.$topic->value,
                'label' => $topic->label(),
            ];
        }

        return $fields;
    }

    /**
     * Formuliert einen einzelnen MP-Themenblock auf Basis des
     * uebergebenen SIS-Kontexts.
     *
     * Liefert null zurueck, wenn das Modell entscheidet, dass das
     * Thema fuer diesen Bewohner nicht relevant ist.
     *
     * @param  array<string, mixed>  $sisContext
     */
    public function formulateField(string $fieldKey, string $fieldLabel, array $sisContext, Salutation $salutation): ?string
    {
        $system = $this->systemPrompt($salutation);
        $prompt = $this->fieldPrompt($fieldKey, $fieldLabel, $sisContext);

        $output = trim($this->ollama->generate($prompt, $system));

        if ($output === '' || $output === self::NOT_APPLICABLE_TOKEN) {
            return null;
        }

        // Vorsichtsmassnahme: wenn das Modell den Token irgendwo im
        // Text einbettet ("NICHT_RELEVANT, weil ..."), trotzdem als
        // "nicht relevant" werten.
        if (str_contains($output, self::NOT_APPLICABLE_TOKEN)) {
            return null;
        }

        return $output;
    }

    /**
     * Baut den SIS-Kontext, der bei JEDEM Themenblock-Call mitgegeben
     * wird. Wird einmal vor der Schleife im Job berechnet.
     *
     * @return array<string, mixed>
     */
    public function sisContext(Sis $sis): array
    {
        $sis->loadMissing(['topicEntries', 'risks']);

        return [
            'opening_question' => $sis->opening_question,
            'topics' => $sis->topicEntries
                ->sortBy('topic_number')
                ->map(fn($t): array => [
                    'number' => (int) $t->topic_number,
                    'content' => (string) $t->content,
                ])
                ->values()
                ->all(),
            'risks' => $sis->risks
                ->map(fn($r): array => [
                    'kind' => (string) $r->risk_kind,
                    'is_at_risk' => (bool) $r->is_at_risk,
                    'notes' => (string) ($r->notes ?? ''),
                ])
                ->values()
                ->all(),
        ];
    }

    private function systemPrompt(Salutation $salutation): string
    {
        $term = $salutation->residentTerm();
        $termCapitalized = ucfirst($term);
        $pronoun = $salutation->pronoun();

        return <<<PROMPT
            Du bist eine erfahrene Pflegedienstleitung und schreibst einen Massnahmenplan
            (MP) nach Beikirch/Roes-Konzept aus einer fertiggestellten SIS.

            Aufgabe: Du bekommst den kompletten SIS-Inhalt eines Bewohners und sollst
            fuer einen einzelnen MP-Themenblock konkrete, handlungsleitende Pflege-
            massnahmen formulieren.

            Regeln:
            - Sprache: Deutsch, sachlich, fachlicher Pflegestil.
            - 2-5 Saetze. Kurze, konkrete, handlungsleitende Massnahmen.
            - Bezug zum SIS-Inhalt MUSS erkennbar sein. Nichts erfinden.
            - Keine Diagnosen, keine medizinischen Bewertungen.
            - Personenbezeichnung: AUSSCHLIESSLICH "{$termCapitalized}".
              Pronomen: "{$pronoun}".
              Keine Namen, keine Pseudonyme, kein "Bewohner/in" oder "Bewohner:in".
            - Keine Floskeln wie "Es ist wichtig", "selbstverstaendlich".
            - Keine Anrede, keine Erklaerung. Nur den fertigen MP-Text ausgeben.
            - WICHTIG: Wenn aus dem SIS-Inhalt fuer den angefragten Themenblock
              KEINE konkrete pflegerische Massnahme ableitbar ist (weil das Thema
              fuer diesen Bewohner nicht relevant erscheint), gib EXAKT das Wort
              "NICHT_RELEVANT" aus, und sonst nichts.
            PROMPT;
    }

    /**
     * @param  array<string, mixed>  $sisContext
     */
    private function fieldPrompt(string $fieldKey, string $fieldLabel, array $sisContext): string
    {
        $sisBlock = $this->renderSisContext($sisContext);

        if ($fieldKey === 'grundbotschaft') {
            $instruction = <<<TEXT
                Aufgabe: Formuliere die GRUNDBOTSCHAFT des Massnahmenplans.

                Die Grundbotschaft enthaelt kurze, immer geltende Hinweise zum Bewohner,
                die im taeglichen Umgang grundsaetzlich gelten (z.B. immer geltende
                Hilfsmittel-Vorgaben, Ansprache-Praeferenzen, "Pflege nur zu zweit"-
                Hinweise, fixe Anweisungen zur Medikamenteneinnahme). Nicht den ganzen
                Tagesablauf - nur das, was sich im Tagesablauf wiederholt.

                Wenn aus dem SIS-Inhalt nichts grundsaetzlich Wiederkehrendes ableitbar
                ist: Gib "NICHT_RELEVANT" aus.
                TEXT;

            return $instruction."\n\nSIS-Inhalt des Bewohners:\n".$sisBlock;
        }

        return sprintf(
            "Massnahmenplan-Themenblock: %s\n\nSIS-Inhalt des Bewohners:\n%s\n\nFormuliere fuer den Themenblock \"%s\" konkrete, handlungsleitende Pflegemassnahmen, die aus dem SIS-Inhalt ableitbar sind. Falls dieser Themenblock fuer den Bewohner nicht relevant ist, antworte ausschliesslich mit \"NICHT_RELEVANT\".",
            $fieldLabel,
            $sisBlock,
            $fieldLabel,
        );
    }

    /**
     * @param  array<string, mixed>  $sisContext
     */
    private function renderSisContext(array $sisContext): string
    {
        $lines = [];

        if (! empty($sisContext['opening_question'])) {
            $lines[] = '[Eingangsfrage] '.$sisContext['opening_question'];
        }

        foreach ($sisContext['topics'] ?? [] as $topic) {
            if (! is_array($topic) || empty($topic['content'])) {
                continue;
            }
            $lines[] = '[SIS-Themenfeld '.$topic['number'].'] '.$topic['content'];
        }

        $atRiskKinds = [];
        foreach ($sisContext['risks'] ?? [] as $risk) {
            if (! is_array($risk)) {
                continue;
            }
            if (! empty($risk['is_at_risk'])) {
                $atRiskKinds[] = $risk['kind'].(empty($risk['notes']) ? '' : ' ('.$risk['notes'].')');
            }
        }
        if ($atRiskKinds !== []) {
            $lines[] = '[Erkannte Risiken] '.implode(', ', $atRiskKinds);
        }

        if ($lines === []) {
            return '(SIS enthaelt keine inhaltlichen Eintraege.)';
        }

        return implode("\n", $lines);
    }
}
