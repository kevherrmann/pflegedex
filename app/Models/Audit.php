<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * Pflegedex-Audit-Model.
 *
 * Erweitert das Standard-Audit-Model von owen-it/laravel-auditing um
 * unseren UUIDv7-Primary-Key. Die audits-Tabelle hat in Pflegedex
 * uuid('id') statt bigIncrements - ohne dieses Model wird beim Insert
 * kein id-Wert mitgesendet und Postgres lehnt mit NOT NULL ab.
 */
final class Audit extends BaseAudit
{
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;
}
