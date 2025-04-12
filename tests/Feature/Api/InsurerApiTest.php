<?php

namespace Tests\Feature\Api;

use App\Models\Insurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InsurerApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test getting the list of insurers (public endpoint)
     */
    public function test_get_insurers_endpoint_returns_insurers_list()
    {
        // Create some test insurers
        Insurer::factory()->count(3)->create();

        // Call the API endpoint
        $response = $this->getJson('/api/claims/insurers');

        // Assert response is successful and has the correct structure
        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'code']
            ]);

        // Assert that the correct number of insurers is returned
        $this->assertCount(3, $response->json());
    }

    /**
     * Test that the insurers list is cached
     */
    public function test_insurers_list_is_cached()
    {
        // Clear the cache first
        Cache::forget('insurers_dropdown');

        // Create insurers
        Insurer::factory()->count(3)->create();

        // First call should cache the results
        $this->getJson('/api/claims/insurers');

        // Verify that the cache has the insurers list
        $this->assertTrue(Cache::has('insurers_dropdown'));

        // Create an additional insurer
        Insurer::factory()->create([
            'name' => 'New Test Insurer',
            'code' => 'NTI'
        ]);

        // Second call should return cached data, not including the new insurer
        $response = $this->getJson('/api/claims/insurers');

        // Should still have only 3 insurers (from cache)
        $this->assertCount(3, $response->json());
    }
}
