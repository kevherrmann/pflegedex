<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CarePlanTopic as CarePlanTopicEnum;
use App\Models\CarePlan;
use App\Models\CarePlanTopicEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarePlanTopicEntry>
 */
class CarePlanTopicEntryFactory extends Factory
{
    protected $model = CarePlanTopicEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'care_plan_id' => CarePlan::factory(),
            'topic_number' => $this->faker->randomElement(CarePlanTopicEnum::numbers()),
            'content' => $this->faker->paragraph(3),
        ];
    }
}
