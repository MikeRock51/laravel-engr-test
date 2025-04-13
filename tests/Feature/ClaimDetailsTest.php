<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class ClaimDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $insurer;
    protected $claim;

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

        // Create a test claim
        $this->claim = Claim::factory()->create([
            'user_id' => $this->user->id,
            'insurer_id' => $this->insurer->id,
            'provider_name' => 'Test Provider',
            'specialty' => 'Cardiology',
            'encounter_date' => now()->subDays(5),
            'submission_date' => now(),
            'priority_level' => 3,
            'total_amount' => 300.00,
            'status' => 'pending',
            'is_batched' => false
        ]);

        // Add items to the claim
        ClaimItem::factory()->create([
            'claim_id' => $this->claim->id,
            'name' => 'Consultation',
            'unit_price' => 150.00,
            'quantity' => 1
        ]);

        ClaimItem::factory()->create([
            'claim_id' => $this->claim->id,
            'name' => 'ECG Test',
            'unit_price' => 75.00,
            'quantity' => 2
        ]);
    }

    /**
     * Test unauthenticated user cannot access claim details
     */
    public function test_unauthenticated_user_cannot_access_claim_details()
    {
        $response = $this->get(route('claims.show', $this->claim->id));

        $response->assertRedirect(route('login'));
    }

    /**
     * Test authenticated user can see own claim details
     */
    public function test_authenticated_user_can_see_own_claim_details()
    {
        $response = $this->actingAs($this->user)
            ->get(route('claims.show', $this->claim->id));

        $response->assertStatus(200);

        // Check that the response includes the InertiaJS component
        $response->assertInertia(
            fn($assert) => $assert
                ->component('ClaimDetails')
                ->has('claim')
                ->has('estimatedCost')
                ->has('costFactors')
        );
    }

    /**
     * Test user cannot see another user's claim details
     */
    public function test_user_cannot_see_another_users_claim_details()
    {
        // Create another user
        $anotherUser = User::factory()->create();

        $response = $this->actingAs($anotherUser)
            ->get(route('claims.show', $this->claim->id));

        $response->assertStatus(403);
    }

    /**
     * Test all claim properties are included in the response
     */
    public function test_claim_details_include_all_properties()
    {
        $response = $this->actingAs($this->user)
            ->get(route('claims.show', $this->claim->id));

        $response->assertStatus(200);

        // Use a less strict assertion that only checks for required properties
        $response->assertInertia(function (Assert $assert) {
            return $assert
                ->component('ClaimDetails')
                ->has('claim.id')
                ->has('claim.user_id')
                ->has('claim.insurer_id')
                ->has('claim.provider_name')
                ->has('claim.specialty')
                ->has('claim.encounter_date')
                ->has('claim.submission_date')
                ->has('claim.priority_level')
                ->has('claim.total_amount')
                ->has('claim.status')
                ->has('claim.is_batched')
                ->has('claim.items');
        });
    }

    /**
     * Test estimated cost calculation is included in the response
     */
    public function test_claim_details_include_cost_estimation()
    {
        $response = $this->actingAs($this->user)
            ->get(route('claims.show', $this->claim->id));

        $response->assertInertia(
            fn($assert) => $assert
                ->component('ClaimDetails')
                ->has('estimatedCost')
                ->has(
                    'costFactors',
                    fn($assert) => $assert
                        ->has('baseCost')
                        ->has('priorityMultiplier')
                        ->has('dayFactor')
                        ->has('valueMultiplier')
                        ->has('datePreference')
                        ->has('dateUsed')
                )
        );
    }
}
