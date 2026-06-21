<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MedicationForm;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Medication extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resident_id',
        'location_id',
        'name',
        'form',
        'strength',
        'dose_morning',
        'dose_noon',
        'dose_evening',
        'dose_night',
        'prn',
        'prn_instruction',
        'is_btm',
        'prescriber',
        'starts_on',
        'ends_on',
        'note',
        'active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'form' => MedicationForm::class,
            'prn' => 'boolean',
            'is_btm' => 'boolean',
            'active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'prn_instruction' => 'encrypted',
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

    /** @return HasMany<MedicationAdministration, $this> */
    public function administrations(): HasMany
    {
        return $this->hasMany(MedicationAdministration::class);
    }
}
