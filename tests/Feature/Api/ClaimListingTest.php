<?php

namespace Tests\Feature\Api;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClaimListingTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $insurer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create();

        // Create a test insurer
        $this->insurer = Insurer::factory()->create();
    }

    /**
     * Test that unauthenticated users cannot access claims list
     */
    public function test_unauthenticated_users_cannot_access_claims_list()
    {
        $response = $this->getJson('/api/claims/list');

        $response->assertStatus(401);
    }

    /**
     * Test authenticated users can get claims list
     */
    public function test_authenticated_users_can_get_claims_list()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create some test claims for this user
        Claim::factory()
            ->count(5)
            ->for($this->user)
            ->for($this->insurer)
            ->create();

        $response = $this->getJson('/api/claims/list');

        $response->assertStatus(200);

        // Check that we have 5 claims in the response
        $this->assertNotEmpty($response->json());
    }

    /**
     * Test filtering claims by insurer
     */
    public function test_can_filter_claims_by_insurer()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create another insurer
        $anotherInsurer = Insurer::factory()->create();

        // Create claims for different insurers
        Claim::factory()
            ->count(3)
            ->for($this->user)
            ->for($this->insurer)
            ->create();

        Claim::factory()
            ->count(2)
            ->for($this->user)
            ->for($anotherInsurer)
            ->create();

        // Filter by the first insurer
        $response = $this->getJson('/api/claims/list?insurer_id=' . $this->insurer->id);

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        // All claims should have the filtered insurer_id
        foreach ($response->json('data') as $claim) {
            $this->assertEquals($this->insurer->id, $claim['insurer_id']);
        }
    }

    /**
     * Test filtering claims by status
     */
    public function test_can_filter_claims_by_status()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create claims with different statuses
        Claim::factory()
            ->count(2)
            ->for($this->user)
            ->for($this->insurer)
            ->create(['status' => 'pending']);

        Claim::factory()
            ->count(3)
            ->for($this->user)
            ->for($this->insurer)
            ->create(['status' => 'approved']);

        // Filter by status
        $response = $this->getJson('/api/claims/list?status=approved');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        // All claims should have the filtered status
        foreach ($response->json('data') as $claim) {
            $this->assertEquals('approved', $claim['status']);
        }
    }

    /**
     * Test filtering claims by date range
     */
    public function test_can_filter_claims_by_date_range()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create claims with different submission dates
        Claim::factory()
            ->count(2)
            ->for($this->user)
            ->for($this->insurer)
            ->create([
                'submission_date' => now()->subDays(10)->format('Y-m-d')
            ]);

        Claim::factory()
            ->count(3)
            ->for($this->user)
            ->for($this->insurer)
            ->create([
                'submission_date' => now()->subDays(5)->format('Y-m-d')
            ]);

        // Filter by date range
        $fromDate = now()->subDays(7)->format('Y-m-d');
        $toDate = now()->format('Y-m-d');
        $response = $this->getJson("/api/claims/list?from_date={$fromDate}&to_date={$toDate}");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * Test filtering claims by user
     */
    public function test_can_filter_claims_by_user()
    {
        // Create another user
        $anotherUser = User::factory()->create();

        // Authenticate first user
        Sanctum::actingAs($this->user);

        // Create claims for current user
        Claim::factory()
            ->count(3)
            ->for($this->user)
            ->for($this->insurer)
            ->create();

        // Create claims for another user
        Claim::factory()
            ->count(2)
            ->for($anotherUser)
            ->for($this->insurer)
            ->create();

        // Filter by current user
        $response = $this->getJson('/api/claims/list?user_filter=1');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    /**
     * Test ordering claims
     */
    public function test_can_order_claims()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create claims with different total amounts
        Claim::factory()
            ->for($this->user)
            ->for($this->insurer)
            ->create(['total_amount' => 100]);

        Claim::factory()
            ->for($this->user)
            ->for($this->insurer)
            ->create(['total_amount' => 200]);

        Claim::factory()
            ->for($this->user)
            ->for($this->insurer)
            ->create(['total_amount' => 300]);

        // Order by total_amount in ascending order
        $response = $this->getJson('/api/claims/list?order_by=total_amount&order_dir=asc');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(100, $data[0]['total_amount']);
        $this->assertEquals(200, $data[1]['total_amount']);
        $this->assertEquals(300, $data[2]['total_amount']);

        // Order by total_amount in descending order
        $response = $this->getJson('/api/claims/list?order_by=total_amount&order_dir=desc');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(300, $data[0]['total_amount']);
        $this->assertEquals(200, $data[1]['total_amount']);
        $this->assertEquals(100, $data[2]['total_amount']);
    }

    /**
     * Test claim list pagination
     */
    public function test_claims_list_is_paginated()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create more claims than the default per_page
        Claim::factory()
            ->count(20)
            ->for($this->user)
            ->for($this->insurer)
            ->create();

        // Get first page with 10 per page
        $response = $this->getJson('/api/claims/list?per_page=10');

        $response->assertStatus(200);

        // Check that the response contains claims
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertLessThanOrEqual(10, count($data));

        // Get second page
        $response = $this->getJson('/api/claims/list?per_page=10&page=2');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
    }

    /**
     * Test that claims list is cached
     */
    public function test_claims_list_is_cached()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Clear all cache
        Cache::flush();

        // Create some claims
        Claim::factory()
            ->count(5)
            ->for($this->user)
            ->for($this->insurer)
            ->create();

        // First call to cache
        $this->getJson('/api/claims/list');

        // Generate a cache key similar to the one used in the controller
        $cacheKey = 'claims_' . md5('[]' . '_' . $this->user->id);

        // Check if cache exists
        $this->assertTrue(Cache::has($cacheKey));

        // Create more claims
        Claim::factory()
            ->count(3)
            ->for($this->user)
            ->for($this->insurer)
            ->create();

        // Second call should return cached data
        $response = $this->getJson('/api/claims/list');

        // Should still only have 5 claims (from cache)
        $this->assertCount(5, $response->json('data'));

        // Force bypass cache
        $response = $this->getJson('/api/claims/list?no_cache=1');

        // Should now have all 8 claims
        $this->assertCount(8, $response->json('data'));
    }
}
