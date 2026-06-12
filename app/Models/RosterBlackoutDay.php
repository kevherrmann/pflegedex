<?php

namespace App\Models;

use App\Enums\BlackoutScope;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RosterBlackoutDay extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'location_id',
        'date',
        'scope',
        'qualification_levels',
        'reason',
        'blocks_vacation',
        'blocks_overtime_compensation',
        'created_by',
    ];

    protected $attributes = [
        'scope' => BlackoutScope::All->value,
        'blocks_vacation' => true,
        'blocks_overtime_compensation' => true,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'scope' => BlackoutScope::class,
            'qualification_levels' => 'array',
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

    /**
     * Mitarbeiter, die die Sperre gezielt betrifft (nur bei scope = employees).
     *
     * @return BelongsToMany<User, $this>
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Greift diese Sperre fuer den angegebenen Mitarbeiter? Bei einer Sperre
     * fuer den ganzen Wohnbereich immer, bei einer Qualifikations- oder
     * Mitarbeitersperre nur, wenn der Mitarbeiter dazu passt.
     */
    public function appliesTo(User $employee): bool
    {
        return match ($this->scope) {
            BlackoutScope::All => true,
            BlackoutScope::Qualification => in_array(
                $employee->employeeProfile?->qualification_level?->value,
                $this->qualification_levels ?? [],
                true,
            ),
            BlackoutScope::Employees => $this->employees->contains('id', $employee->id),
        };
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
