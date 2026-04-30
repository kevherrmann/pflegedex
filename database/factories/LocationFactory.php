<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Wohnbereich '.$this->faker->unique()->bothify('??');

        return [
            'name' => $name,
            'short_name' => str($name)->after('Wohnbereich ')->upper()->limit(12, '')->toString(),
            'description' => $this->faker->optional()->sentence(),
            'active' => true,
        ];
    }
}
