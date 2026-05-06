<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SisRiskKind;
use App\Models\Sis;
use App\Models\SisRisk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SisRisk>
 */
class SisRiskFactory extends Factory
{
    protected $model = SisRisk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sis_id' => Sis::factory(),
            'risk_kind' => $this->faker->randomElement(SisRiskKind::values()),
            'is_at_risk' => $this->faker->boolean(40),
            'needs_further_assessment' => $this->faker->boolean(20),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
