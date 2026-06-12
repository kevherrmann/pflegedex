<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\CarePlanTopic;
use App\Enums\Salutation;
use App\Models\Sis;
use App\Services\Ai\Concerns\FormulatesWithOllama;

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
    use FormulatesWithOllama;

    /** Sentinel-String. Wenn das LLM den ausgibt, weiss der Job: Thema ueberspringen. */
    private const NOT_APPLICABLE_TOKEN = 'NICHT_RELEVANT';

    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly AiOutputSanitizer $sanitizer = new AiOutputSanitizer,
    ) {}

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

        $output = $this->generateValidated($prompt, $system, $salutation);

        // Robuste Sentinel-Erkennung: auch eingebettet ("nicht relevant,
        // weil ...") oder mit Leerzeichen/Bindestrich geschrieben.
        if ($this->sanitizer->isNotApplicable($output)) {
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
                ->map(fn ($t): array => [
                    'number' => (int) $t->topic_number,
                    'content' => (string) $t->content,
                ])
                ->values()
                ->all(),
            'risks' => $sis->risks
                ->map(fn ($r): array => [
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
            - Schreibe handlungsleitend und ausreichend konkret.
            - Zielumfang pro relevantem Themenblock: 4-8 konkrete Massnahmenpunkte oder kurze Absaetze.
            - Der Text muss fuer eine fremde Pflegekraft verstaendlich sein.
            - Beschreibe nur personelle Hilfen, Anleitung, Erinnerung, Motivation, Kontrolle, Beobachtung oder Uebernahme durch Pflege/Betreuung.
            - Selbststaendige Handlungen des Bewohners nicht als Massnahme planen.
            - Keine Selbstverstaendlichkeiten aus dem Immersobeweis wiederholen.
            - Risiken nicht erneut als Begruendung ausformulieren, sondern daraus konkrete Massnahmen ableiten.
            - Wenn Zeitangaben noetig sind, als ca.-Zeiten oder situativ formulieren.
            - Abweichungen und Auffaelligkeiten gehoeren in den Pflegebericht.
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
            $instruction = <<<'TEXT'
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
            "Massnahmenplan-Themenblock: %s\n\nSIS-Inhalt des Bewohners:\n%s\n\nFormuliere fuer den Themenblock \"%s\" einen professionellen, handlungsleitenden Massnahmenplan.\n\nStruktur:\n- Schreibe nur Massnahmen, die aus der SIS ableitbar sind.\n- Beschreibe konkret, was Pflege/Betreuung tun soll.\n- Benenne Hilfsmittel, Unterstuetzungsform, Situationen, ca.-Zeitpunkte oder Ausloeser, sofern ableitbar.\n- Benenne Beobachtungsauftraege und wann Abweichungen im Pflegebericht zu dokumentieren sind.\n- Keine Diagnosen erfinden.\n- Keine allgemeinen Selbstverstaendlichkeiten wie freundliche Begruessung, Intimsphaere achten, Zimmer lueften, Bett machen etc., ausser es gibt einen individuellen pflegerischen Grund.\n\nWenn fuer diesen Themenblock wirklich keine konkrete Massnahme ableitbar ist, antworte ausschliesslich mit \"NICHT_RELEVANT\".",
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
