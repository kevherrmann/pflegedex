<?php

declare(strict_types=1);

namespace App\Enums;

/** Wundart. */
enum WoundType: string
{
    case Dekubitus = 'dekubitus';
    case UlcusCruris = 'ulcus_cruris';
    case DiabeticFoot = 'diabetic_foot';
    case Postoperative = 'postoperative';
    case SkinTear = 'skin_tear';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Dekubitus => 'Dekubitus',
            self::UlcusCruris => 'Ulcus cruris',
            self::DiabeticFoot => 'Diabetisches Fußsyndrom',
            self::Postoperative => 'Postoperative Wunde',
            self::SkinTear => 'Hautriss (Skin Tear)',
            self::Other => 'Sonstige',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t): array => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}
