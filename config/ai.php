<?php

declare(strict_types=1);

return [
    /*
     * Ollama-Konfiguration.
     *
     * In Tests wird OLLAMA_URL i.d.R. auf einen Dummy-Wert gesetzt und
     * Http::fake() abgefangen - der Service ruft also nie einen echten
     * Server an.
     *
     * Im Default zeigt OLLAMA_URL auf den ollama-Service im Compose-Stack
     * (interner Service-Name 'ollama'). Beim Kunden-Deployment ist das
     * gleiche Kommando ausreichend - das Modell wird vom ollama-init-
     * Container automatisch beim ersten Hochfahren gezogen.
     */
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://ollama:11434'),
        'model' => env('AI_MODEL', 'gemma4:e2b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT_SECONDS', 120),
        // Sampling-Parameter fuer /api/generate.
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.3),
        'top_p' => (float) env('OLLAMA_TOP_P', 0.9),
        // Health-Check-TTL: gecachte Verfuegbarkeit (Sekunden), damit nicht
        // jeder Inertia-Request einen HTTP-Roundtrip zum LLM macht.
        'health_cache_ttl' => (int) env('OLLAMA_HEALTH_TTL', 30),
    ],

    /*
     * Versuche pro Feld-Generierung: Transportfehler und Genus-Verstoesse
     * werden bis zu dieser Anzahl wiederholt, bevor der Job scheitert bzw.
     * die bereinigte Ausgabe mit Protokoll-Hinweis uebernommen wird.
     */
    'generation' => [
        'attempts' => (int) env('AI_GENERATION_ATTEMPTS', 2),
    ],
];
