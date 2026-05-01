<?php

namespace App\Models;

use Database\Factories\CareReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareReport extends Model
{
    /** @use HasFactory<CareReportFactory> */
    use HasFactory;

    protected $fillable = [
        'resident_id',
        'location_id',
        'author_id',
        'occurred_at',
        'category',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resident_id' => 'integer',
            'location_id' => 'integer',
            'author_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Resident, CareReport> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /** @return BelongsTo<Location, CareReport> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, CareReport> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
