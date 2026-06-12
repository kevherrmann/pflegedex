<?php

namespace App\Enums;

enum ShiftWishKind: string
{
    case WishFree = 'wish_free';
    case WishShift = 'wish_shift';

    public function label(): string
    {
        return match ($this) {
            self::WishFree => 'Wunschfrei',
            self::WishShift => 'Wunschdienst',
        };
    }
}
