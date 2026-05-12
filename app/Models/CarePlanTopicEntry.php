<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarePlanTopicEntry extends Model
{
    use HasFactory;
    use HasUuidV7;

    protected $table = 'care_plan_topics';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'care_plan_id',
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

    /** @return BelongsTo<CarePlan, $this> */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }
}
