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
 * Massnahmenplan (MP) - Header.
 *
 * 1:1 zu Resident (resident_id unique). Voraussetzung fuer Anlage:
 * SIS des Bewohners muss completed_at !== null haben (Pruefung im
 * Controller, nicht per DB).
 */
class CarePlan extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $table = 'care_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'resident_id',
        'location_id',
        'grundbotschaft',
        'started_at',
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
            'grundbotschaft' => 'encrypted',
            'started_at' => 'date',
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

    /** @return HasMany<CarePlanTopicEntry, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(CarePlanTopicEntry::class)->orderBy('topic_number');
    }

    /** @return HasMany<CarePlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CarePlanVersion::class);
    }

    public function isOverdue(): bool
    {
        return $this->next_evaluation_due !== null
            && $this->next_evaluation_due->isBefore(today());
    }

    /**
     * Schreibt einen JSON-Snapshot des aktuellen Zustands ins
     * care_plan_versions-Archiv.
     */
    public function appendVersion(string $reason, ?User $user = null): CarePlanVersion
    {
        $this->loadMissing(['topics']);

        $snapshot = [
            'grundbotschaft' => $this->grundbotschaft,
            'started_at' => $this->started_at?->toDateString(),
            'evaluated_at' => $this->evaluated_at?->toDateString(),
            'next_evaluation_due' => $this->next_evaluation_due?->toDateString(),
            'topics' => $this->topics->map(fn (CarePlanTopicEntry $t): array => [
                'topic_number' => $t->topic_number,
                'content' => $t->content,
            ])->all(),
        ];

        return $this->versions()->create([
            'content_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'snapshot_reason' => $reason,
            'created_by' => $user?->id,
        ]);
    }

    /**
     * Markiert den MP als evaluiert: setzt evaluated_at=heute,
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
     * @param  Builder<CarePlan>  $query
     * @return Builder<CarePlan>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('next_evaluation_due')
            ->whereDate('next_evaluation_due', '<', today());
    }
}
