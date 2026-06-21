<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WoundStage;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class WoundAssessment extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'wound_id',
        'resident_id',
        'location_id',
        'assessed_on',
        'stage',
        'length_mm',
        'width_mm',
        'depth_mm',
        'pain',
        'wound_description',
        'measures',
        'assessed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'stage' => WoundStage::class,
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'depth_mm' => 'integer',
            'pain' => 'integer',
            'wound_description' => 'encrypted',
            'measures' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Wound, $this> */
    public function wound(): BelongsTo
    {
        return $this->belongsTo(Wound::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
