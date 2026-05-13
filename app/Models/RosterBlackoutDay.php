<?php

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterBlackoutDay extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'location_id',
        'date',
        'reason',
        'blocks_vacation',
        'blocks_overtime_compensation',
        'created_by',
    ];

    protected $attributes = [
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => true,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'blocks_vacation' => 'boolean',
            'blocks_overtime_compensation' => 'boolean',
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

    public function scopeForLocation(Builder $query, Location|string $location): Builder
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return $query->where('location_id', $locationId);
    }

    public function scopeBetweenDates(Builder $query, string $startsOn, string $endsOn): Builder
    {
        return $query
            ->whereDate('date', '>=', $startsOn)
            ->whereDate('date', '<=', $endsOn);
    }

    public function scopeBlockingVacation(Builder $query): Builder
    {
        return $query->where('blocks_vacation', true);
    }

    public function scopeBlockingOvertimeCompensation(Builder $query): Builder
    {
        return $query->where('blocks_overtime_compensation', true);
    }
}
