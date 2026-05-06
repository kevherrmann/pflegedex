<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SisTopicEntry extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $table = 'sis_topic_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sis_id',
        'topic_number',
        'content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topic_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Sis, $this> */
    public function sis(): BelongsTo
    {
        return $this->belongsTo(Sis::class, 'sis_id');
    }
}
