<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SisRiskKind;
use App\Enums\SisTopic;
use App\Models\Location;
use App\Models\Resident;
use App\Models\Sis;
use App\Models\SisRisk;
use App\Models\SisTopicEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sis>
 */
class SisFactory extends Factory
{
    protected $model = Sis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resident = Resident::factory()->create();

        return [
            'resident_id' => $resident->id,
            'location_id' => $resident->location_id ?? Location::factory(),
            'opening_question' => $this->faker->sentence(8),
            'started_at' => today()->subDays(7),
            'completed_at' => null,
            'evaluated_at' => null,
            'next_evaluation_due' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Vollstaendige SIS mit allen 6 Themenfeldern und 6 Risiken anlegen
     * - praktisch fuer Tests, die eine "echte" SIS brauchen.
     */
    public function withTopicsAndRisks(): static
    {
        return $this->afterCreating(function (Sis $sis): void {
            foreach (SisTopic::numbers() as $number) {
                SisTopicEntry::factory()->create([
                    'sis_id' => $sis->id,
                    'topic_number' => $number,
                ]);
            }

            foreach (SisRiskKind::values() as $kind) {
                SisRisk::factory()->create([
                    'sis_id' => $sis->id,
                    'risk_kind' => $kind,
                ]);
            }
        });
    }

    public function completed(): static
    {
        return $this->state(fn(array $attributes): array => [
            'completed_at' => today()->subDays(2),
            'next_evaluation_due' => today()->addWeeks(8),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn(array $attributes): array => [
            'completed_at' => today()->subWeeks(12),
            'evaluated_at' => today()->subWeeks(10),
            'next_evaluation_due' => today()->subWeeks(2),
        ]);
    }
}
