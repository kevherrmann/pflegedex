<?php

namespace App\Support;

final class BrandPalette
{
    public static function primary(): string
    {
        return '#9B1C3B';
    }

    public static function primaryDark(): string
    {
        return '#7F1730';
    }

    public static function primarySoft(): string
    {
        return '#F7E8ED';
    }

    public static function neutralText(): string
    {
        return '#333333';
    }

    /**
     * @return array{name: string, primaryColor: string, primaryDark: string, primarySoft: string, neutralText: string}
     */
    public static function inertiaPayload(): array
    {
        return [
            'name' => 'Pflegedex',
            'primaryColor' => self::primary(),
            'primaryDark' => self::primaryDark(),
            'primarySoft' => self::primarySoft(),
            'neutralText' => self::neutralText(),
        ];
    }
}
