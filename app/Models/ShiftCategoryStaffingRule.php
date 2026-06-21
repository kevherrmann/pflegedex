<?php

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Besetzung pro Schicht-Kategorie (Früh/Spät/Nacht) je Wohnbereich. Pro Tag wird
 * die Summe aller eingeplanten Personen über alle Schichten dieser Kategorie
 * gegen diese Zahlen geprüft – die einzelnen Schichten addieren ihre Zahlen NICHT.
 */
class ShiftCategoryStaffingRule extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'location_id',
        'category',
        'weekday',
        'required_total_staff',
        'target_total_staff',
        'required_specialists',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'required_total_staff' => 'integer',
            'target_total_staff' => 'integer',
            'required_specialists' => 'integer',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
