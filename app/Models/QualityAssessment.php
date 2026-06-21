<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class QualityAssessment extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resident_id',
        'location_id',
        'period',
        'assessed_on',
        'answers',
        'note',
        'assessed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessed_on' => 'date',
            'answers' => 'encrypted:array',
            'note' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
