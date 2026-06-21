<?php

namespace App\Enums;

/**
 * KI-Anbieter eines Modells: lokal über Ollama oder eine OpenAI-kompatible
 * externe API (z. B. DeepSeek), die per API-Key angebunden wird.
 */
enum AiProvider: string
{
    case Ollama = 'ollama';
    case OpenAi = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::Ollama => 'Lokal (Ollama)',
            self::OpenAi => 'Externe API (OpenAI-kompatibel, z. B. DeepSeek)',
        };
    }
}
