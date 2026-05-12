<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\Salutation;
use App\Enums\SisTopic;
use App\Models\Sis;

/**
 * Formuliert SIS-Stichpunkte mit Hilfe eines LLM in fachlichen Fliesstext um.
 *
 * Sequenziell pro Feld: Eingangsfrage zuerst, dann TF1..TF6 in Reihenfolge.
 * Jeder Schritt ruft OllamaClient::generate() einzeln auf - kleinere Prompts
 * = bessere Output-Qualitaet bei kleinen Modellen wie gemma4:e2b.
 *
 * Anrede (herr/frau) wird in den Prompt eingebaut, damit das LLM
 * "der Bewohner" / "die Bewohnerin" konsistent verwendet, statt zu raten
 * oder neutral "Bewohner/Bewohnerin" zu schreiben.
 *
 * Risiko-Notes werden NICHT umformuliert - die sind bewusst kurz/stichwort-
 * artig (z.B. "Sturzrisiko bei Mobilisation"). Eine Ausformulierung wuerde
 * mehr verschleiern als helfen.
 */
class SisFormulator
{
    public function __construct(
        private readonly OllamaClient $ollama,
    ) {
    }

    /**
     * Schreibt den uebergebenen Stichpunkt in fachlichen Fliesstext um.
     * Liefert null zurueck, wenn $input leer/whitespace - dann gibt es
     * nichts zu formulieren.
     */
    public function formulateField(string $fieldLabel, ?string $input, Salutation $salutation): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $system = $this->systemPrompt($salutation);
        $prompt = $this->fieldPrompt($fieldLabel, $input);

        return $this->ollama->generate($prompt, $system);
    }

    /**
     * Liefert die geordneten Felder einer SIS in der Reihenfolge fuer den Job.
     *
     * @return list<array{key: string, label: string, content: string|null}>
     */
    public function fieldsForSis(Sis $sis): array
    {
        $fields = [];
        $fields[] = [
            'key' => 'opening_question',
            'label' => 'Was bewegt Sie im Augenblick?',
            'content' => $sis->opening_question,
        ];

        $sis->loadMissing('topicEntries');
        $entries = $sis->topicEntries->keyBy('topic_number');

        foreach (SisTopic::cases() as $topic) {
            $entry = $entries->get($topic->value);
            $fields[] = [
                'key' => 'topic_'.$topic->value,
                'label' => $topic->label(),
                'content' => $entry?->content,
            ];
        }

        return $fields;
    }

    private function systemPrompt(Salutation $salutation): string
    {
        $term = $salutation->residentTerm();          // "der Bewohner" / "die Bewohnerin"
        $termCapitalized = ucfirst($term);            // "Der Bewohner" / "Die Bewohnerin"
        $pronoun = $salutation->pronoun();            // "er" / "sie"

        return <<<PROMPT
            Du bist eine erfahrene Pflegefachkraft (PDL) und schreibst Eintraege fuer eine
            Strukturierte Informationssammlung (SIS) nach Beikirch/Roes-Konzept.
            Aufgabe: Du bekommst Stichpunkte einer Pflegekraft und formulierst daraus
            einen kurzen, fachlich praezisen Fliesstext.

            Regeln:
            - Sprache: Deutsch, formell, neutraler Pflegestil.
            - Schreibe pro Themenfeld einen fachlich ausreichenden Fliesstext.
            - Zielumfang: 5-8 fachliche Saetze, wenn die Stichpunkte genug Inhalt liefern.
            - Der Text soll Ressourcen, Einschraenkungen, Ursachen, Hilfebedarf, Vorlieben und Risiken benennen, sofern diese aus den Stichpunkten hervorgehen.
            - Bei Risiken benennen: welches Risiko besteht, wodurch es entsteht und welche Folgen es fuer den Pflegealltag hat.
            - Nichts erfinden. Wenn Stichpunkte knapp sind, knapp bleiben und keine Details hinzudichten.
            - Keine Erfindungen. Was nicht in den Stichpunkten steht, wird auch nicht erwaehnt.
            - Keine Diagnosen, keine medizinischen Bewertungen.
            - Personenbezeichnung: AUSSCHLIESSLICH "{$termCapitalized}" verwenden.
              NIEMALS "Der Bewohner / Die Bewohnerin", "Bewohner/in" oder "Bewohner:in" schreiben.
              Pronomen: "{$pronoun}" verwenden.
              Keine Namen, keine Pseudonyme.
            - Keine Floskeln wie "Es ist wichtig", "selbstverstaendlich".
            - Keine Anrede, keine Erklaerung. Nur den fertigen Text ausgeben.
            PROMPT;
    }

    private function fieldPrompt(string $fieldLabel, string $input): string
    {
        return sprintf(
            "Themenfeld: %s\n\nStichpunkte der Pflegefachkraft:\n%s\n\nFormuliere daraus den fertigen SIS-Text.\n\nAchte darauf:\n- Ressourcen und vorhandene Faehigkeiten benennen.\n- Einschraenkungen und Ursachen beschreiben.\n- Individuelle Wuensche, Gewohnheiten und Vorlieben aufnehmen.\n- Risiken nur benennen, wenn sie aus den Stichpunkten ableitbar sind.\n- Keine Massnahmenplanung schreiben, sondern eine fachliche Einschaetzung fuer die SIS.",
            $fieldLabel,
            $input,
        );
    }
}
