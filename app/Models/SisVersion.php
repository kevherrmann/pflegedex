<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\AppendOnly;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SisVersion extends Model
{
    use AppendOnly;
    use HasFactory;
    use HasUuidV7;

    protected $table = 'sis_versions';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sis_id',
        'content_snapshot',
        'snapshot_reason',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content_snapshot' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sis, $this> */
    public function sis(): BelongsTo
    {
        return $this->belongsTo(Sis::class, 'sis_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
