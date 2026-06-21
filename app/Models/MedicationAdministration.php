<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MedicationAdministrationStatus;
use App\Enums\MedicationSlot;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class MedicationAdministration extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'medication_id',
        'resident_id',
        'location_id',
        'administered_on',
        'slot',
        'status',
        'administered_by',
        'administered_at',
        'witness_by',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'administered_on' => 'date',
            'administered_at' => 'datetime',
            'slot' => MedicationSlot::class,
            'status' => MedicationAdministrationStatus::class,
            'note' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Medication, $this> */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /** @return BelongsTo<User, $this> */
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    /** @return BelongsTo<User, $this> */
    public function witnessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_by');
    }
}
