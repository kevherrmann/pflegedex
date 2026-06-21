<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Assessment extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resident_id',
        'location_id',
        'type',
        'assessed_on',
        'answers',
        'total_score',
        'risk_level',
        'note',
        'next_due',
        'assessed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AssessmentType::class,
            'assessed_on' => 'date',
            'next_due' => 'date',
            'total_score' => 'integer',
            // Detail-Antworten (Gesundheitsdaten) verschluesselt at-rest (K1).
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
