<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ClaimSubmissionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create();

        // Create an insurer with realistic constraints
        $this->insurer = Insurer::factory()->create([
            'name' => 'Test Insurer',
            'code' => 'TEST',
            'daily_capacity' => 50,
            'min_batch_size' => 5,
            'max_batch_size' => 20,
            'date_preference' => 'encounter_date',
            'specialty_costs' => [
                'Cardiology' => 100,
                'Orthopedics' => 150,
                'Pediatrics' => 80,
            ],
            'priority_multipliers' => [
                1 => 0.8,
                2 => 0.9,
                3 => 1.0,
                4 => 1.2,
                5 => 1.5,
            ],
            'claim_value_threshold' => 5000,
            'claim_value_multiplier' => 1.2,
        ]);
    }

    /** @test */
    public function user_can_submit_a_claim()
    {
        $claimData = [
            'insurer_id' => $this->insurer->id,
            'provider_name' => 'Test Provider',
            'encounter_date' => now()->subDays(5)->format('Y-m-d'),
            'submission_date' => now()->format('Y-m-d'),
            'priority_level' => 3,
            'specialty' => 'Cardiology',
            'items' => [
                [
                    'name' => 'Consultation',
                    'unit_price' => 250,
                    'quantity' => 1,
                ],
                [
                    'name' => 'ECG',
                    'unit_price' => 500,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->post(route('claims.submit'), $claimData);

        $response->assertRedirect();
        $this->assertDatabaseHas('claims', [
            'insurer_id' => $this->insurer->id,
            'provider_name' => 'Test Provider',
            'specialty' => 'Cardiology',
            'total_amount' => 750,
            'is_batched' => false,
            'status' => 'pending',
        ]);

        // Check that claim items were created
        $claim = Claim::where('provider_name', 'Test Provider')->first();
        $this->assertCount(2, $claim->items);
    }

    /** @test */
    public function claim_validation_prevents_invalid_data()
    {
        $invalidClaimData = [
            'insurer_id' => '', // Missing required field
            'provider_name' => 'Test Provider',
            'encounter_date' => now()->addDays(5)->format('Y-m-d'), // Future date (invalid)
            'submission_date' => now()->format('Y-m-d'),
            'priority_level' => 8, // Invalid priority level (out of range)
            'specialty' => 'Unknown Specialty', // Invalid specialty
            'items' => [
                [
                    'name' => '',
                    'unit_price' => -50,
                    'quantity' => 0,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->post(route('claims.submit'), $invalidClaimData);

        $response->assertSessionHasErrors(['insurer_id', 'encounter_date', 'priority_level', 'specialty', 'items.0.name', 'items.0.unit_price', 'items.0.quantity']);
    }

    /** @test */
    public function total_amount_is_calculated_correctly()
    {
        $claimData = [
            'insurer_id' => $this->insurer->id,
            'provider_name' => 'Test Provider',
            'encounter_date' => now()->subDays(5)->format('Y-m-d'),
            'submission_date' => now()->format('Y-m-d'),
            'priority_level' => 3,
            'specialty' => 'Cardiology',
            'items' => [
                [
                    'name' => 'Consultation',
                    'unit_price' => 250.50,
                    'quantity' => 2,
                ],
                [
                    'name' => 'ECG',
                    'unit_price' => 500.25,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->post(route('claims.submit'), $claimData);

        $response->assertRedirect();

        // Expected total: (250.50 * 2) + (500.25 * 1) = 501.00 + 500.25 = 1001.25
        $this->assertDatabaseHas('claims', [
            'provider_name' => 'Test Provider',
            'total_amount' => 1001.25,
        ]);
    }
}
