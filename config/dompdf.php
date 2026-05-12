<?php

declare(strict_types=1);

/**
 * Pflegedex-Override fuer barryvdh/laravel-dompdf.
 *
 * Standardmaessig wird die Library-eigene Config geladen (Vendor-Default).
 * Hier ueberschreiben wir gezielt:
 *
 * - options.enable_php = true  : aktiviert dompdf-PHP-Bloecke in den
 *   Blade-Templates. Wir nutzen das fuer Seitenzahl/Total-Pagecount im
 *   Footer (counter(page)/counter(pages) liefert in dompdf unzuverlaessig
 *   "0", daher der PHP-Block in _layout.blade.php).
 *
 * - options.isRemoteEnabled = false : Templates sollen NIEMALS externe
 *   URLs nachladen (Security). Standard, hier explizit gesetzt.
 *
 * Falls die Library spaeter mehr Optionen ergaenzt: rest verlassen wir
 * auf Library-Default.
 */
return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,
    'options' => [
        'enable_php' => true,
        'isRemoteEnabled' => false,
        'enable_remote' => false,
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'default_font' => 'DejaVu Sans',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
    ],
];
