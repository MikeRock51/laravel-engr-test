<?php

namespace Database\Factories;

use App\Models\Claim;
use App\Models\ClaimItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClaimItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ClaimItem::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $unitPrice = $this->faker->randomFloat(2, 10, 1000);
        $quantity = $this->faker->numberBetween(1, 10);

        return [
            'claim_id' => Claim::factory(),
            'name' => $this->faker->randomElement([
                'Consultation',
                'X-Ray',
                'MRI Scan',
                'Blood Test',
                'Physical Therapy',
                'Vaccination',
                'ECG Test',
                'Ultrasound',
                'Medication',
                'Surgery'
            ]),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
