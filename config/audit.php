<?php

declare(strict_types=1);
use App\Models\Audit;
use OwenIt\Auditing\Resolvers\IpAddressResolver;
use OwenIt\Auditing\Resolvers\UrlResolver;
use OwenIt\Auditing\Resolvers\UserAgentResolver;
use OwenIt\Auditing\Resolvers\UserResolver;

/*
 * Konfiguration fuer owen-it/laravel-auditing.
 *
 * Pflegedex-spezifische Anpassungen:
 *  - 'driver' => 'database' (Standard, schreibt in audits-Tabelle)
 *  - 'console' => false: Audits aus CLI (Seeder, Migrations) werden NICHT geschrieben.
 *    Begruendung: Demo-Seeder soll keinen Audit-Laerm produzieren.
 *  - 'user.morph_prefix' default 'user'
 */
return [
    'enabled' => env('AUDITING_ENABLED', true),

    'implementation' => Audit::class,

    'user' => [
        'morph_prefix' => 'user',
        'guards' => [
            'web',
        ],
        'resolver' => UserResolver::class,
    ],

    'resolver' => [
        'ip_address' => IpAddressResolver::class,
        'user_agent' => UserAgentResolver::class,
        'url' => UrlResolver::class,
    ],

    'events' => [
        'created',
        'updated',
        'deleted',
        'restored',
    ],

    'strict' => false,

    'timestamps' => false,

    'threshold' => 0,

    'driver' => 'database',

    'drivers' => [
        'database' => [
            'table' => 'audits',
            'connection' => null,
        ],
    ],

    /*
     * Audits werden auch in CLI-Kontexten geschrieben (PHPUnit, Artisan-Commands).
     * Begruendung: Mit 'console' => false faengt das Package auch PHPUnit-Tests ab,
     * was unsere Audit-Tests bricht (Issue owen-it/laravel-auditing#520).
     *
     * Demo-Seeder schuetzen sich selbst per Model::withoutAuditing() im
     * DatabaseSeeder, sodass keine Audits fuer Demo-Daten entstehen.
     */
    'console' => true,

    'queue' => [
        'enable' => false,
        'connection' => 'sync',
        'queue' => 'default',
        'delay' => 0,
    ],
];
