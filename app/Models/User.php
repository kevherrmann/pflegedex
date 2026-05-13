<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Concerns\HasUuidV7;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use AuditableTrait;
    use HasFactory;
    use HasRoles;
    use HasUuidV7;
    use Notifiable;

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function canRequestAbsence(): bool
    {
        return $this->employeeProfile?->canRequestAbsence() ?? false;
    }

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Felder, die NICHT im Audit-Trail erscheinen.
     *
     * @var list<string>
     */
    protected array $auditExclude = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Location, User>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsToMany<Location, User>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }

    /**
     * @return Collection<int, Location>
     */
    public function accessibleLocations(): Collection
    {
        $locations = $this->locations()->orderBy('name')->get();

        if ($this->location && !$locations->contains('id', $this->location->id)) {
            $locations->push($this->location);
        }

        return $locations->sortBy('name')->values();
    }

    public function canAccessLocation(Location|string $location): bool
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return $this->location_id === $locationId
            || $this->locations()->whereKey($locationId)->exists();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeForLocation(Builder $query, Location|string $location): Builder
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return $query->where('location_id', $locationId);
    }

    public function absenceRequests(): HasMany
    {
        return $this->hasMany(AbsenceRequest::class);
    }

    public function requestedAbsenceRequests(): HasMany
    {
        return $this->hasMany(AbsenceRequest::class, 'requested_by');
    }

    public function decidedAbsenceRequests(): HasMany
    {
        return $this->hasMany(AbsenceRequest::class, 'decided_by');
    }
}
