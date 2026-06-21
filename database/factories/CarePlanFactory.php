<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CarePlanTopic as CarePlanTopicEnum;
use App\Models\CarePlan;
use App\Models\CarePlanTopicEntry;
use App\Models\Location;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarePlan>
 */
class CarePlanFactory extends Factory
{
    protected $model = CarePlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resident = Resident::factory()->create();

        return [
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id ?? Location::factory(),
            'grundbotschaft' => $this->faker->sentence(10),
            'started_at' => today(),
            'evaluated_at' => null,
            'next_evaluation_due' => today()->addWeeks(8),
            'created_by' => User::factory(),
        ];
    }

    /**
     * Erzeugt einen MP mit ein paar typischen Themen-Eintraegen
     * (Mobilitaet, Ernaehrung, Koerperpflege).
     */
    public function withSampleTopics(): static
    {
        return $this->afterCreating(function (CarePlan $cp): void {
            foreach ([
                CarePlanTopicEnum::Mobilitaet,
                CarePlanTopicEnum::Ernaehrung,
                CarePlanTopicEnum::Koerperpflege,
            ] as $t) {
                CarePlanTopicEntry::factory()->create([
                    'care_plan_id' => $cp->id,
                    'topic_number' => $t->value,
                ]);
            }
        });
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'evaluated_at' => today()->subWeeks(10),
            'next_evaluation_due' => today()->subWeeks(2),
        ]);
    }
}
