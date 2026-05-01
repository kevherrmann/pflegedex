<?php

namespace App\Models;

use Database\Factories\ResidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resident extends Model
{
    /** @use HasFactory<ResidentFactory> */
    use HasFactory;

    protected $fillable = [
        'location_id',
        'first_name',
        'last_name',
        'birth_date',
        'room_number',
        'care_level',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'birth_date' => 'date',
            'care_level' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Location, Resident>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<CareReport>
     */
    public function careReports(): HasMany
    {
        return $this->hasMany(CareReport::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * @param  Builder<Resident>  $query
     * @return Builder<Resident>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Resident>  $query
     * @return Builder<Resident>
     */
    public function scopeForLocation(Builder $query, Location|int $location): Builder
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return $query->where('location_id', $locationId);
    }
}
