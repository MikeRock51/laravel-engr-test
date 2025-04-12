<?php

namespace Database\Factories;

use App\Models\Insurer;
use Illuminate\Database\Eloquent\Factories\Factory;

class InsurerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Insurer::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->company,
            'code' => $this->faker->unique()->regexify('[A-Z]{3}'),
            'daily_capacity' => $this->faker->numberBetween(50, 200),
            'min_batch_size' => $this->faker->numberBetween(3, 10),
            'max_batch_size' => $this->faker->numberBetween(30, 100),
            'date_preference' => $this->faker->randomElement(['encounter_date', 'submission_date']),
            'specialty_costs' => json_encode([
                'Cardiology' => $this->faker->randomFloat(2, 80, 150),
                'Dermatology' => $this->faker->randomFloat(2, 70, 140),
                'Neurology' => $this->faker->randomFloat(2, 100, 200),
                'Orthopedics' => $this->faker->randomFloat(2, 90, 180),
                'Pediatrics' => $this->faker->randomFloat(2, 75, 130)
            ]),
            'priority_multipliers' => json_encode([
                1 => 1.0,
                2 => 1.1,
                3 => 1.2,
                4 => 1.3,
                5 => 1.5
            ]),
            'claim_value_threshold' => $this->faker->randomFloat(2, 500, 2000),
            'claim_value_multiplier' => $this->faker->randomFloat(2, 1.1, 1.5),
            'email' => $this->faker->companyEmail,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
