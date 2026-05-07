<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $salutation = fake()->randomElement(['herr', 'frau']);
        $firstName = $salutation === 'herr' ? fake()->firstNameMale() : fake()->firstNameFemale();

        return [
            // Eindeutiges Pseudonym fuer Tests; der Generator macht einen
            // DB-Lookup pro Insert, fuer Tests reicht eine Zufallsziffernfolge.
            'pseudonym' => 'P-'.now()->format('Y').'-'.fake()->unique()->numerify('####'),
            'salutation' => $salutation,
            'location_id' => Location::factory(),
            'first_name' => $firstName,
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-100 years', '-60 years')->format('Y-m-d'),
            'room_number' => fake()->bothify('##?'),
            'care_level' => fake()->numberBetween(1, 5),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
