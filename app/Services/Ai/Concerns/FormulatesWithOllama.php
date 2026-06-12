<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

use App\Enums\Salutation;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gemeinsame Aufruf-Logik der Formulatoren: Modell aufrufen, Ausgabe
 * bereinigen, Personenbezeichnung pruefen — mit konfigurierbaren
 * Wiederholungsversuchen bei Transportfehlern und Genus-Verstoessen.
 *
 * Nach dem letzten Versuch wird ein Genus-Verstoss nicht hart verworfen,
 * sondern protokolliert und die (mechanisch bereinigte) Ausgabe verwendet:
 * Ein fehlendes Themenfeld waere fuer die Pflege schlechter als ein Text,
 * den die PDL beim Gegenlesen korrigiert.
 */
trait FormulatesWithOllama
{
    private function generateValidated(string $prompt, string $system, Salutation $salutation): string
    {
        $attempts = max(1, (int) config('ai.generation.attempts', 2));
        $lastText = '';
        $lastViolations = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $raw = $this->ollama->generate($prompt, $system);
            } catch (RuntimeException $exception) {
                if ($attempt === $attempts) {
                    throw $exception;
                }

                Log::info('Ollama-Aufruf wird wiederholt', [
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $sanitized = $this->sanitizer->sanitize($raw);

            if ($this->sanitizer->isNotApplicable($sanitized)) {
                return $sanitized;
            }

            ['text' => $lastText, 'violations' => $lastViolations] =
                $this->sanitizer->enforceSalutation($sanitized, $salutation);

            if (! in_array('wrong_gender_term', $lastViolations, true)) {
                return $lastText;
            }
        }

        Log::warning('LLM-Ausgabe verwendet falsche Personenbezeichnung, Text wird trotzdem uebernommen', [
            'violations' => $lastViolations,
            'salutation' => $salutation->value,
        ]);

        return $lastText;
    }
}
