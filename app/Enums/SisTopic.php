<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Themenfelder einer SIS lt. Beikirch/Roes (BMG-Konzept v2.0/2017).
 *
 * Die Reihenfolge ist verbindlich.
 */
enum SisTopic: int
{
    case KognitivKommunikativ = 1;
    case Mobilitaet = 2;
    case Krankheit = 3;
    case Selbstversorgung = 4;
    case SozialeBeziehungen = 5;
    case Wohnen = 6;

    public function label(): string
    {
        return match ($this) {
            self::KognitivKommunikativ => 'Kognitive und kommunikative Fähigkeiten',
            self::Mobilitaet => 'Mobilität und Beweglichkeit',
            self::Krankheit => 'Krankheitsbezogene Anforderungen und Belastungen',
            self::Selbstversorgung => 'Selbstversorgung',
            self::SozialeBeziehungen => 'Leben in sozialen Beziehungen',
            self::Wohnen => 'Wohnen/Häuslichkeit',
        };
    }

    /**
     * @return list<int>
     */
    public static function numbers(): array
    {
        return array_map(fn(self $t): int => $t->value, self::cases());
    }
}
