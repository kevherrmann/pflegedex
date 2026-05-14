<?php

namespace App\Models;

use App\Enums\RosterStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Roster extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'location_id',
        'year',
        'month',
        'status',
        'generated_at',
        'published_at',
        'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => RosterStatus::class,
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function isPublished(): bool
    {
        return in_array($this->status, [
            RosterStatus::Published,
            RosterStatus::Locked,
        ], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            RosterStatus::Draft,
            RosterStatus::Generated,
            RosterStatus::Reviewed,
        ], true);
    }
}
