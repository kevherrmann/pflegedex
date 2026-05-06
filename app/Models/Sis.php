<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Strukturierte Informationssammlung (SIS) - Header.
 *
 * 1:1 zu Resident. Die fachliche Konvention "1 SIS pro Bewohner" ergibt
 * sich aus der DB-Constraint (resident_id unique). Aktualisierungen
 * laufen ueber update() + Versions-Snapshot.
 */
class Sis extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $table = 'sis_assessments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'location_id',
        'opening_question',
        'started_at',
        'completed_at',
        'evaluated_at',
        'next_evaluation_due',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'completed_at' => 'date',
            'evaluated_at' => 'date',
            'next_evaluation_due' => 'date',
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

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<SisTopicEntry, $this> */
    public function topicEntries(): HasMany
    {
        return $this->hasMany(SisTopicEntry::class, 'sis_id')->orderBy('topic_number');
    }

    /** @return HasMany<SisRisk, $this> */
    public function risks(): HasMany
    {
        return $this->hasMany(SisRisk::class, 'sis_id');
    }

    /** @return HasMany<SisVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SisVersion::class, 'sis_id');
    }

    public function isOverdue(): bool
    {
        return $this->next_evaluation_due !== null
            && $this->next_evaluation_due->isBefore(today());
    }

    /**
     * Schreibt einen JSON-Snapshot des aktuellen Zustands (Header +
     * Themenfelder + Risiken) ins sis_versions-Archiv.
     */
    public function appendVersion(string $reason, ?User $user = null): SisVersion
    {
        $this->loadMissing(['topicEntries', 'risks']);

        $snapshot = [
            'opening_question' => $this->opening_question,
            'started_at' => $this->started_at?->toDateString(),
            'completed_at' => $this->completed_at?->toDateString(),
            'evaluated_at' => $this->evaluated_at?->toDateString(),
            'next_evaluation_due' => $this->next_evaluation_due?->toDateString(),
            'topics' => $this->topicEntries->map(fn(SisTopicEntry $t): array => [
                'topic_number' => $t->topic_number,
                'content' => $t->content,
            ])->all(),
            'risks' => $this->risks->map(fn(SisRisk $r): array => [
                'risk_kind' => $r->risk_kind,
                'is_at_risk' => $r->is_at_risk,
                'needs_further_assessment' => $r->needs_further_assessment,
                'notes' => $r->notes,
            ])->all(),
        ];

        return $this->versions()->create([
            'content_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'snapshot_reason' => $reason,
            'created_by' => $user?->id,
        ]);
    }

    /**
     * Markiert die SIS als evaluiert: setzt evaluated_at=heute,
     * next_evaluation_due=+8 Wochen, und schreibt einen Versions-Snapshot.
     */
    public function markEvaluated(User $user): void
    {
        $this->forceFill([
            'evaluated_at' => today(),
            'next_evaluation_due' => today()->addWeeks(8),
            'updated_by' => $user->id,
        ])->save();

        $this->appendVersion('evaluated', $user);
    }

    /**
     * @param  Builder<Sis>  $query
     * @return Builder<Sis>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('next_evaluation_due')
            ->whereDate('next_evaluation_due', '<', today());
    }
}
