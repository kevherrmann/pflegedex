<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WoundStatus;
use App\Enums\WoundType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Wound extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resident_id',
        'location_id',
        'body_site',
        'type',
        'acquired_in_house',
        'opened_on',
        'closed_on',
        'status',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WoundType::class,
            'status' => WoundStatus::class,
            'acquired_in_house' => 'boolean',
            'opened_on' => 'date',
            'closed_on' => 'date',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WoundAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(WoundAssessment::class);
    }
}
