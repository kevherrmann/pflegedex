<?php

use App\Support\BrandPalette;

it('uses the Sander Pflege inspired bordeaux palette', function () {
    expect(BrandPalette::primary())->toBe('#9B1C3B')
        ->and(BrandPalette::primaryDark())->toBe('#7F1730')
        ->and(BrandPalette::primarySoft())->toBe('#F7E8ED')
        ->and(BrandPalette::neutralText())->toBe('#333333');
});
