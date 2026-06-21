<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class VitalSign extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resident_id',
        'location_id',
        'recorded_by',
        'measured_at',
        'systolic',
        'diastolic',
        'pulse',
        'respiratory_rate',
        'oxygen_saturation',
        'blood_sugar',
        'temperature',
        'weight',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'systolic' => 'integer',
            'diastolic' => 'integer',
            'pulse' => 'integer',
            'respiratory_rate' => 'integer',
            'oxygen_saturation' => 'integer',
            'blood_sugar' => 'integer',
            'temperature' => 'decimal:1',
            'weight' => 'decimal:1',
            // Freitext-Gesundheitsdatum at-rest verschluesselt (K1).
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
