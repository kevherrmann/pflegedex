<?php

namespace App\Models;

use App\Enums\Salutation;
use App\Support\Concerns\HasUuidV7;
use Database\Factories\ResidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Resident extends Model implements Auditable
{
    /** @use HasFactory<ResidentFactory> */
    use AuditableTrait;
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'pseudonym',
        'salutation',
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
            'salutation' => Salutation::class,
            'birth_date' => 'date',
            'care_level' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Erzeugt das naechste Pseudonym im Schema "P-YYYY-####".
     *
     * Sollte innerhalb einer Transaktion aufgerufen werden, damit zwei
     * parallele Anlagevorgaenge nicht dasselbe Pseudonym vergeben.
     */
    public static function generatePseudonym(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = 'P-'.$year.'-';

        $lastNumber = static::query()
            ->where('pseudonym', 'like', $prefix.'%')
            ->orderByDesc('pseudonym')
            ->lockForUpdate()
            ->value('pseudonym');

        $nextNumber = $lastNumber === null
            ? 1
            : ((int) substr((string) $lastNumber, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
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
     * Formelle Anrede mit Nachnamen, z.B. "Herr Müller" / "Frau Schmidt".
     *
     * @return Attribute<string, never>
     */
    protected function formalName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->salutation->label().' '.$this->last_name));
    }

    /** @return HasOne<Sis, $this> */
    public function sis(): HasOne
    {
        return $this->hasOne(Sis::class);
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
    public function scopeForLocation(Builder $query, Location|string $location): Builder
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        return $query->where('location_id', $locationId);
    }
}
