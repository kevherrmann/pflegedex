<?php

namespace Database\Factories;

use App\Enums\CareReportCategory;
use App\Models\CareReport;
use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CareReport>
 */
class CareReportFactory extends Factory
{
    protected $model = CareReport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resident = Resident::factory()->create();

        return [
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id,
            'author_id' => User::factory(),
            'occurred_at' => $this->faker->dateTimeBetween('-1 week'),
            'category' => $this->faker->randomElement(CareReportCategory::values()),
            'body' => $this->faker->sentence(12),
        ];
    }
}
