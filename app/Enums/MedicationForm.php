<?php

declare(strict_types=1);

namespace App\Enums;

/** Darreichungsform eines Medikaments. */
enum MedicationForm: string
{
    case Tablette = 'tablette';
    case Kapsel = 'kapsel';
    case Tropfen = 'tropfen';
    case Saft = 'saft';
    case Spritze = 'spritze';
    case Pflaster = 'pflaster';
    case Salbe = 'salbe';
    case Inhalation = 'inhalation';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Tablette => 'Tablette',
            self::Kapsel => 'Kapsel',
            self::Tropfen => 'Tropfen',
            self::Saft => 'Saft/Lösung',
            self::Spritze => 'Spritze/Injektion',
            self::Pflaster => 'Pflaster',
            self::Salbe => 'Salbe/Creme',
            self::Inhalation => 'Inhalation',
            self::Sonstiges => 'Sonstiges',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f): string => $f->value, self::cases());
    }
}
