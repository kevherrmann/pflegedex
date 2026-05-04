<?php

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Database\Factories\CareReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class CareReport extends Model implements Auditable
{
    /** @use HasFactory<CareReportFactory> */
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resident_id',
        'location_id',
        'author_id',
        'occurred_at',
        'category',
        'body',
        'signed',
        'signed_at',
        'signed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'signed' => 'boolean',
            'signed_at' => 'datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** @return HasMany<ReportVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class);
    }

    public function isSigned(): bool
    {
        return $this->signed === true;
    }

    public function appendVersion(string $reason, ?User $user = null): ReportVersion
    {
        return $this->versions()->create([
            'content_snapshot' => $this->body,
            'snapshot_reason' => $reason,
            'created_by' => $user?->id,
        ]);
    }

    public function sign(User $user): void
    {
        if ($this->isSigned()) {
            return;
        }

        $this->forceFill([
            'signed' => true,
            'signed_at' => now(),
            'signed_by' => $user->id,
        ])->save();

        $this->appendVersion('signed', $user);
    }
}
