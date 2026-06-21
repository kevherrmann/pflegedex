<?php

declare(strict_types=1);

namespace App\Enums;

/** Wundstadium (v.a. Dekubitus-Kategorien nach EPUAP/NPIAP). */
enum WoundStage: string
{
    case Grade1 = 'grade_1';
    case Grade2 = 'grade_2';
    case Grade3 = 'grade_3';
    case Grade4 = 'grade_4';
    case DeepTissue = 'deep_tissue';
    case Unstageable = 'unstageable';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Grade1 => 'Kategorie 1 (nicht wegdrückbare Rötung)',
            self::Grade2 => 'Kategorie 2 (Teilverlust der Haut)',
            self::Grade3 => 'Kategorie 3 (Verlust der Haut)',
            self::Grade4 => 'Kategorie 4 (vollständiger Gewebeverlust)',
            self::DeepTissue => 'Tiefe Gewebeschädigung',
            self::Unstageable => 'Nicht klassifizierbar',
            self::NotApplicable => 'Nicht zutreffend',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s): string => $s->value, self::cases());
    }
}
