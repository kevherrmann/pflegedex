<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SisRisk extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $table = 'sis_risks';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sis_id',
        'risk_kind',
        'is_at_risk',
        'needs_further_assessment',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_at_risk' => 'boolean',
            'needs_further_assessment' => 'boolean',
        ];
    }

    /** @return BelongsTo<Sis, $this> */
    public function sis(): BelongsTo
    {
        return $this->belongsTo(Sis::class, 'sis_id');
    }
}
