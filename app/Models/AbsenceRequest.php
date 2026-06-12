<?php

namespace App\Models;

use App\Enums\AbsenceRequestStatus;
use App\Enums\AbsenceRequestType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceRequest extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'location_id',
        'type',
        'starts_on',
        'ends_on',
        'days_count',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'rejection_reason',
        'override_reason',
        'note',
    ];

    protected $attributes = [
        'type' => 'vacation',
        'status' => 'requested',
    ];

    protected function casts(): array
    {
        return [
            'type' => AbsenceRequestType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days_count' => 'decimal:2',
            'status' => AbsenceRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function scopeBlockingOverlap(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AbsenceRequestStatus::Requested->value,
            AbsenceRequestStatus::Approved->value,
        ]);
    }

    public function overlaps(string $startsOn, string $endsOn): bool
    {
        return $this->starts_on->toDateString() <= $endsOn
            && $this->ends_on->toDateString() >= $startsOn;
    }

    public function isOpen(): bool
    {
        return $this->status === AbsenceRequestStatus::Requested;
    }

    public function isApproved(): bool
    {
        return $this->status === AbsenceRequestStatus::Approved;
    }
}
