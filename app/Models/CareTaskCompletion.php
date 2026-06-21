<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CareTaskCompletionStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class CareTaskCompletion extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'care_task_id',
        'resident_id',
        'location_id',
        'performed_on',
        'status',
        'note',
        'performed_by',
        'performed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'performed_at' => 'datetime',
            'status' => CareTaskCompletionStatus::class,
            // Freitext-Gesundheitsdatum at-rest verschluesselt (K1).
            'note' => 'encrypted',
        ];
    }

    /** @return BelongsTo<CareTask, $this> */
    public function careTask(): BelongsTo
    {
        return $this->belongsTo(CareTask::class);
    }

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
