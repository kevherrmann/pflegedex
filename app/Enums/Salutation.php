<?php

declare(strict_types=1);

namespace App\Enums;

enum Salutation: string
{
    case Herr = 'herr';
    case Frau = 'frau';

    public function label(): string
    {
        return match ($this) {
            self::Herr => 'Herr',
            self::Frau => 'Frau',
        };
    }

    /**
     * Geschlechtsspezifische Bezeichnung fuer SIS-Texte.
     */
    public function residentTerm(): string
    {
        return match ($this) {
            self::Herr => 'der Bewohner',
            self::Frau => 'die Bewohnerin',
        };
    }

    public function pronoun(): string
    {
        return match ($this) {
            self::Herr => 'er',
            self::Frau => 'sie',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn(self $s): array => ['value' => $s->value, 'label' => $s->label()],
            self::cases(),
        );
    }
}
