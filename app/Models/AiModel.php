<?php

namespace App\Models;

use App\Enums\AiProvider;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein konfigurierbares KI-Modell. Genau eins ist aktiv und wird vom Generator
 * und Health-Check verwendet. Der lokale Gemma-Standard (is_default) bleibt
 * immer vorhanden. Externe Anbieter (DeepSeek u. a.) hinterlegen einen
 * api_key, der verschlüsselt at-rest gespeichert wird.
 */
class AiModel extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'label',
        'provider',
        'model',
        'base_url',
        'api_key',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
