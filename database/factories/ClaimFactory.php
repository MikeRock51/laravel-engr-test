<?php

namespace Database\Factories;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClaimFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Claim::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'insurer_id' => Insurer::factory(),
            'provider_name' => $this->faker->company,
            'encounter_date' => $this->faker->dateTimeBetween('-3 months', '-1 day')->format('Y-m-d'),
            'submission_date' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'priority_level' => $this->faker->numberBetween(1, 5),
            'specialty' => $this->faker->randomElement(['Cardiology', 'Dermatology', 'Neurology', 'Orthopedics', 'Pediatrics']),
            'total_amount' => $this->faker->randomFloat(2, 50, 5000),
            'status' => $this->faker->randomElement(['pending', 'approved', 'denied', 'batched']),
            'is_batched' => $this->faker->boolean(30),
            'batch_id' => function (array $attributes) {
                return $attributes['is_batched'] ? $this->faker->regexify('[A-Z]{3}-\d{6}') : null;
            },
            'batch_date' => function (array $attributes) {
                return $attributes['is_batched'] ? $this->faker->date() : null;
            },
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the claim is pending.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function pending()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'pending',
                'is_batched' => false,
                'batch_id' => null,
                'batch_date' => null,
            ];
        });
    }

    /**
     * Indicate that the claim is batched.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function batched()
    {
        return $this->state(function (array $attributes) {
            $batchId = 'BATCH-' . now()->format('Ymd');
            return [
                'status' => 'batched',
                'is_batched' => true,
                'batch_id' => $batchId,
                'batch_date' => now()->format('Y-m-d'),
            ];
        });
    }
}
