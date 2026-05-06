<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SisTopic;
use App\Models\Sis;
use App\Models\SisTopicEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SisTopicEntry>
 */
class SisTopicEntryFactory extends Factory
{
    protected $model = SisTopicEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sis_id' => Sis::factory(),
            'topic_number' => $this->faker->randomElement(SisTopic::numbers()),
            'content' => $this->faker->paragraph(3),
        ];
    }
}
