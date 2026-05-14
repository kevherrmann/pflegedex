<?php

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftTemplate extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'location_id',
        'name',
        'code',
        'starts_at',
        'ends_at',
        'duration_minutes',
        'color',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'string',
            'ends_at' => 'string',
            'duration_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function staffingRules(): HasMany
    {
        return $this->hasMany(ShiftStaffingRule::class);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function startsAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function endsAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 5),
        );
    }
}
