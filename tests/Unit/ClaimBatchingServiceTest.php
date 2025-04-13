<?php

namespace Tests\Unit;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\User;
use App\Services\ClaimBatchingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimBatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $batchingService;
    protected $insurer;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->batchingService = new ClaimBatchingService();

        $this->user = User::factory()->create();

        // Create an insurer with specific constraints for testing
        $this->insurer = Insurer::factory()->create([
            'name' => 'Test Insurer',
            'code' => 'TEST',
            'daily_capacity' => 10,
            'min_batch_size' => 3,
            'max_batch_size' => 5,
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
            'claim_value_threshold' => 2000,
            'claim_value_multiplier' => 1.2,
        ]);
    }

    /** @test */
    public function it_batches_claims_correctly()
    {
        // Create 10 pending claims for the same provider with varied properties
        $this->createTestClaims(10);

        // Process the claims
        $results = $this->batchingService->processPendingClaims();

        // Verify results
        $this->assertArrayHasKey($this->insurer->code, $results);

        // Check that all claims are now batched
        $this->assertEquals(0, Claim::where('insurer_id', $this->insurer->id)
            ->where('is_batched', false)
            ->count());

        // Verify claims are grouped into batches of appropriate size
        $batches = Claim::where('insurer_id', $this->insurer->id)
            ->where('is_batched', true)
            ->groupBy('batch_id')
            ->select('batch_id')
            ->selectRaw('count(*) as claim_count')
            ->get();

        foreach ($batches as $batch) {
            $this->assertGreaterThanOrEqual($this->insurer->min_batch_size, $batch->claim_count);
            $this->assertLessThanOrEqual($this->insurer->max_batch_size, $batch->claim_count);
        }
    }

    /** @test */
    public function it_respects_daily_capacity_constraints()
    {
        // Create more claims than the daily capacity
        $this->createTestClaims(20); // Daily capacity is 10

        // Process the claims
        $this->batchingService->processPendingClaims();

        // Group claims by batch date
        $claimsByDate = Claim::where('insurer_id', $this->insurer->id)
            ->where('is_batched', true)
            ->groupBy('batch_date')
            ->select('batch_date')
            ->selectRaw('count(*) as claim_count')
            ->get();

        // Verify no date exceeds daily capacity
        foreach ($claimsByDate as $date) {
            $this->assertLessThanOrEqual($this->insurer->daily_capacity, $date->claim_count);
        }
    }

    /** @test */
    public function it_prioritizes_claims_by_specialty_cost()
    {
        // Create claims with different specialties
        $this->createTestClaimsWithSpecialties();

        // Process the claims
        $this->batchingService->processPendingClaims();

        // Get all batched claims sorted by batch_date
        $batchedClaims = Claim::where('insurer_id', $this->insurer->id)
            ->where('is_batched', true)
            ->orderBy('batch_date')
            ->get();

        // Verify that lower-cost specialties are generally batched earlier
        $specialtyCosts = $this->insurer->specialty_costs;
        asort($specialtyCosts);
        $expectedOrder = array_keys($specialtyCosts);

        // Check if the first few claims match the expected specialty order
        $this->assertEquals($expectedOrder[0], $batchedClaims->first()->specialty);
    }

    /** @test */
    public function it_handles_value_thresholds_correctly()
    {
        // Create a mix of high-value and low-value claims
        $this->createMixedValueClaims();

        // Process the claims
        $this->batchingService->processPendingClaims();

        // Get all batched claims grouped by batch_id
        $batches = Claim::where('insurer_id', $this->insurer->id)
            ->where('is_batched', true)
            ->get()
            ->groupBy('batch_id');

        // Check that high-value claims (>2000) are in separate batches
        foreach ($batches as $batchId => $claims) {
            $highValueClaimsInBatch = $claims->where('total_amount', '>', $this->insurer->claim_value_threshold)->count();

            if ($highValueClaimsInBatch > 0) {
                // Either the batch contains only high-value claims or it meets min batch size
                $this->assertTrue(
                    $highValueClaimsInBatch === $claims->count() ||
                        $claims->count() >= $this->insurer->min_batch_size
                );
            }
        }
    }

    /** @test */
    public function it_handles_empty_claims_gracefully()
    {
        // Process with no claims
        $results = $this->batchingService->processPendingClaims();

        // Should return empty array without errors
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /** @test */
    public function it_calculates_day_factor_correctly()
    {
        // Test the day factor calculation directly
        $earlyMonthDate = '2025-05-01';
        $midMonthDate = '2025-05-15';
        $lateMonthDate = '2025-05-30';

        $earlyFactor = $this->batchingService->calculateDayFactor($earlyMonthDate);
        $midFactor = $this->batchingService->calculateDayFactor($midMonthDate);
        $lateFactor = $this->batchingService->calculateDayFactor($lateMonthDate);

        // Day 1 should be 0.2 (20%)
        $this->assertEquals(0.2, $earlyFactor);

        // Day 15 should be around 0.35 (35%)
        $this->assertEqualsWithDelta(0.35, $midFactor, 0.02);

        // Day 30 should be close to 0.5 (50%)
        $this->assertEqualsWithDelta(0.5, $lateFactor, 0.02);
    }

    /**
     * Create test claims with varied properties
     */
    private function createTestClaims(int $count)
    {
        $providers = ['Provider A', 'Provider B', 'Provider C'];
        $priorities = [1, 2, 3, 4, 5];

        for ($i = 0; $i < $count; $i++) {
            $claim = Claim::factory()->create([
                'user_id' => $this->user->id,
                'insurer_id' => $this->insurer->id,
                'provider_name' => $providers[$i % count($providers)],
                'priority_level' => $priorities[$i % count($priorities)],
                'specialty' => 'Cardiology',
                'encounter_date' => Carbon::now()->subDays(rand(1, 10))->format('Y-m-d'),
                'submission_date' => Carbon::now()->format('Y-m-d'),
                'is_batched' => false,
                'status' => 'pending',
                'total_amount' => rand(500, 1500)
            ]);

            // Create a couple of items for each claim
            $claim->items()->create([
                'name' => 'Consultation',
                'unit_price' => 250,
                'quantity' => 1,
                'subtotal' => 250
            ]);

            $claim->items()->create([
                'name' => 'Test',
                'unit_price' => 500,
                'quantity' => 1,
                'subtotal' => 500
            ]);
        }
    }

    /**
     * Create test claims with different specialties
     */
    private function createTestClaimsWithSpecialties()
    {
        $specialties = array_keys($this->insurer->specialty_costs);

        foreach ($specialties as $specialty) {
            // Create 5 claims for each specialty
            for ($i = 0; $i < 5; $i++) {
                Claim::factory()->create([
                    'user_id' => $this->user->id,
                    'insurer_id' => $this->insurer->id,
                    'provider_name' => 'Provider Test',
                    'priority_level' => 3,
                    'specialty' => $specialty,
                    'encounter_date' => Carbon::now()->subDays(rand(1, 10))->format('Y-m-d'),
                    'submission_date' => Carbon::now()->format('Y-m-d'),
                    'is_batched' => false,
                    'status' => 'pending',
                    'total_amount' => 1000
                ]);
            }
        }
    }

    /**
     * Create a mix of high-value and low-value claims
     */
    private function createMixedValueClaims()
    {
        $values = [
            500,
            1000,
            1500,
            2500,
            3000,
            3500
        ];

        foreach ($values as $value) {
            // Create 3 claims for each value point
            for ($i = 0; $i < 3; $i++) {
                Claim::factory()->create([
                    'user_id' => $this->user->id,
                    'insurer_id' => $this->insurer->id,
                    'provider_name' => 'Provider Value',
                    'priority_level' => 3,
                    'specialty' => 'Cardiology',
                    'encounter_date' => Carbon::now()->subDays(rand(1, 10))->format('Y-m-d'),
                    'submission_date' => Carbon::now()->format('Y-m-d'),
                    'is_batched' => false,
                    'status' => 'pending',
                    'total_amount' => $value
                ]);
            }
        }
    }
}
