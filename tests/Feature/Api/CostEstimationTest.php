<?php

namespace Tests\Feature\Api;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\User;
use App\Services\ClaimBatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CostEstimationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $insurer;
    protected $batchingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create();

        // Create a test insurer with specific cost configuration
        $this->insurer = Insurer::factory()->create([
            'specialty_costs' => [
                'Cardiology' => 150.00,
                'Dermatology' => 100.00,
                'Orthopedics' => 200.00
            ],
            'priority_multipliers' => [
                1 => 1.0,
                2 => 1.2,
                3 => 1.5,
                4 => 1.8,
                5 => 2.0
            ],
            'claim_value_threshold' => 1000.00,
            'claim_value_multiplier' => 1.5,
            'date_preference' => 'encounter_date'
        ]);

        $this->batchingService = app(ClaimBatchingService::class);
    }

    /**
     * Test that unauthenticated users can access the cost estimation endpoint
     * (since we moved it to public routes)
     */
    public function test_cost_estimation_endpoint_is_accessible_without_auth()
    {
        $payload = [
            'insurer_id' => $this->insurer->id,
            'specialty' => 'Cardiology',
            'priority_level' => 3,
            'total_amount' => 500.00,
            'encounter_date' => now()->format('Y-m-d'),
            'submission_date' => now()->format('Y-m-d')
        ];

        $response = $this->postJson('/api/claims/estimate-cost', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'baseCost',
                'priorityMultiplier',
                'dayFactor',
                'valueMultiplier',
                'totalCost',
                'batchingTips'
            ]);
    }

    /**
     * Test that the cost estimation endpoint calculates costs correctly
     */
    public function test_cost_estimation_returns_correct_calculations()
    {
        // We'll use a fixed date for consistent day factor calculation in tests
        $testDate = '2025-04-05'; // April 5, 2025

        $payload = [
            'insurer_id' => $this->insurer->id,
            'specialty' => 'Cardiology',
            'priority_level' => 3,
            'total_amount' => 500.00,
            'encounter_date' => $testDate,
            'submission_date' => $testDate
        ];

        $response = $this->postJson('/api/claims/estimate-cost', $payload);

        $response->assertStatus(200);

        $data = $response->json();

        // Verify base cost matches insurer specialty cost
        $this->assertEquals(150.00, $data['baseCost']);

        // Verify priority multiplier
        $this->assertEquals(1.5, $data['priorityMultiplier']);

        // Verify the value multiplier (should be 1.0 since amount is below threshold)
        $this->assertEquals(1.0, $data['valueMultiplier']);

        // For high value claims, the multiplier should change
        $highValuePayload = $payload;
        $highValuePayload['total_amount'] = 1500.00;

        $highValueResponse = $this->postJson('/api/claims/estimate-cost', $highValuePayload);
        $highValueData = $highValueResponse->json();

        // Verify the value multiplier is applied for high-value claims
        $this->assertEquals(1.5, $highValueData['valueMultiplier']);
    }

    /**
     * Test that the cost estimation endpoint returns appropriate batching tips
     */
    public function test_cost_estimation_returns_appropriate_batching_tips()
    {
        // High priority claim
        $highPriorityPayload = [
            'insurer_id' => $this->insurer->id,
            'specialty' => 'Cardiology',
            'priority_level' => 5,
            'total_amount' => 500.00,
            'encounter_date' => now()->format('Y-m-d'),
            'submission_date' => now()->format('Y-m-d')
        ];

        $response = $this->postJson('/api/claims/estimate-cost', $highPriorityPayload);
        $data = $response->json();

        $this->assertNotEmpty($data['batchingTips']);

        // At least one tip should mention priority
        $priorityTipFound = false;
        foreach ($data['batchingTips'] as $tip) {
            if (stripos($tip, 'priority') !== false) {
                $priorityTipFound = true;
                break;
            }
        }

        $this->assertTrue($priorityTipFound, "Expected to find a tip about priority level");

        // High value claim
        $highValuePayload = [
            'insurer_id' => $this->insurer->id,
            'specialty' => 'Cardiology',
            'priority_level' => 3,
            'total_amount' => 1200.00,
            'encounter_date' => now()->format('Y-m-d'),
            'submission_date' => now()->format('Y-m-d')
        ];

        $response = $this->postJson('/api/claims/estimate-cost', $highValuePayload);
        $data = $response->json();

        // At least one tip should mention value threshold
        $thresholdTipFound = false;
        foreach ($data['batchingTips'] as $tip) {
            if (stripos($tip, 'threshold') !== false) {
                $thresholdTipFound = true;
                break;
            }
        }

        $this->assertTrue($thresholdTipFound, "Expected to find a tip about value threshold");
    }

    /**
     * Test validation errors are returned for invalid input
     */
    public function test_cost_estimation_validates_input()
    {
        $invalidPayload = [
            'insurer_id' => 9999, // Non-existent insurer
            'specialty' => 'InvalidSpecialty',
            'priority_level' => 10, // Invalid priority (over 5)
            'total_amount' => -100 // Negative amount
        ];

        $response = $this->postJson('/api/claims/estimate-cost', $invalidPayload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'insurer_id',
                'priority_level',
                'total_amount'
            ]);
    }

    /**
     * Test getting insurer details
     */
    public function test_can_get_insurer_details()
    {
        $response = $this->getJson('/api/claims/insurers/details');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'code',
                    'date_preference',
                    'specialty_costs',
                    'priority_multipliers',
                    'claim_value_threshold',
                    'claim_value_multiplier'
                ]
            ]);
    }
}
