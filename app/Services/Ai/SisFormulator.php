<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\SisTopic;
use App\Models\Sis;

/**
 * Formuliert SIS-Stichpunkte mit Hilfe eines LLM in fachlichen Fliesstext um.
 *
 * Sequenziell pro Feld: Eingangsfrage zuerst, dann TF1..TF6 in Reihenfolge.
 * Jeder Schritt ruft OllamaClient::generate() einzeln auf - kleinere Prompts
 * = bessere Output-Qualitaet bei kleinen Modellen wie gemma:2b.
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
    public function formulateField(string $fieldLabel, ?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $system = $this->systemPrompt();
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

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            Du bist eine erfahrene Pflegefachkraft (PDL) und schreibst Eintraege fuer eine
            Strukturierte Informationssammlung (SIS) nach Beikirch/Roes-Konzept.
            Aufgabe: Du bekommst Stichpunkte einer Pflegekraft und formulierst daraus
            einen kurzen, fachlich praezisen Fliesstext.

            Regeln:
            - Sprache: Deutsch, formell, neutraler Pflegestil.
            - Hoechstens 3-4 Saetze pro Themenfeld.
            - Keine Erfindungen. Was nicht in den Stichpunkten steht, wird auch nicht erwaehnt.
            - Keine Diagnosen, keine medizinischen Bewertungen.
            - Personenbezeichnung: "Die Bewohnerin"/"Der Bewohner" - keine Namen, keine Pseudonyme.
            - Keine Floskeln wie "Es ist wichtig", "selbstverstaendlich".
            - Keine Anrede, keine Erklaerung. Nur den fertigen Text ausgeben.
            PROMPT;
    }

    private function fieldPrompt(string $fieldLabel, string $input): string
    {
        return sprintf(
            "Themenfeld: %s\n\nStichpunkte der Pflegefachkraft:\n%s\n\nFormuliere daraus den fertigen SIS-Text:",
            $fieldLabel,
            $input,
        );
    }
}
