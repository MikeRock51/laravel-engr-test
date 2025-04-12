<?php

namespace Tests\Feature\Api;

use App\Models\Claim;
use App\Models\Insurer;
use App\Models\User;
use App\Notifications\ClaimCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClaimSubmissionTest extends TestCase
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
     * Test that unauthenticated users cannot submit claims
     */
    public function test_unauthenticated_users_cannot_submit_claims()
    {
        $claimData = $this->getValidClaimData();

        $response = $this->postJson('/api/claims/submit', $claimData);

        $response->assertStatus(401);
    }

    /**
     * Test successful claim submission
     */
    public function test_authenticated_user_can_submit_valid_claim()
    {
        Notification::fake();

        // Authenticate user
        Sanctum::actingAs($this->user);

        $claimData = $this->getValidClaimData();

        $response = $this->postJson('/api/claims/submit', $claimData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'claim' => [
                    'id',
                    'insurer_id',
                    'provider_name',
                    'encounter_date',
                    'submission_date',
                    'priority_level',
                    'specialty',
                    'total_amount',
                    'status',
                    'items'
                ]
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Claim submitted successfully',
            ]);

        // Check that the claim was created in the database
        $this->assertDatabaseHas('claims', [
            'user_id' => $this->user->id,
            'insurer_id' => $this->insurer->id,
            'provider_name' => $claimData['provider_name'],
            'specialty' => $claimData['specialty'],
        ]);

        // Verify claim items were created
        $claim = Claim::latest()->first();
        $this->assertEquals(2, $claim->items->count());

        // Verify total amount calculation
        $expectedTotal =
            $claimData['items'][0]['unit_price'] * $claimData['items'][0]['quantity'] +
            $claimData['items'][1]['unit_price'] * $claimData['items'][1]['quantity'];
        $this->assertEquals($expectedTotal, $claim->total_amount);

        // Verify notification was sent
        Notification::assertSentTo(
            $this->insurer,
            ClaimCreatedNotification::class,
            function ($notification, $channels) use ($claim) {
                return $notification->getClaim()->id === $claim->id;
            }
        );
    }

    /**
     * Test validation errors when submitting a claim
     */
    public function test_claim_submission_validates_required_fields()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Submit an empty claim
        $response = $this->postJson('/api/claims/submit', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'insurer_id',
                'provider_name',
                'encounter_date',
                'submission_date',
                'priority_level',
                'specialty',
                'items'
            ]);
    }

    /**
     * Test validation of encounter date (cannot be in the future)
     */
    public function test_claim_validates_encounter_date_not_in_future()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        $claimData = $this->getValidClaimData();
        $claimData['encounter_date'] = now()->addDays(1)->format('Y-m-d'); // Future date

        $response = $this->postJson('/api/claims/submit', $claimData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['encounter_date']);
    }

    /**
     * Test validation of claim items
     */
    public function test_claim_validates_items_data()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        $claimData = $this->getValidClaimData();
        $claimData['items'] = [
            [
                // Missing name
                'unit_price' => 100,
                'quantity' => 2
            ],
            [
                'name' => 'Valid Item',
                'unit_price' => -50, // Invalid negative price
                'quantity' => 1
            ]
        ];

        $response = $this->postJson('/api/claims/submit', $claimData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'items.0.name',
                'items.1.unit_price'
            ]);
    }

    /**
     * Test validation of priority level (must be 1-5)
     */
    public function test_claim_validates_priority_level_range()
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        $claimData = $this->getValidClaimData();
        $claimData['priority_level'] = 6; // Outside valid range of 1-5

        $response = $this->postJson('/api/claims/submit', $claimData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['priority_level']);
    }

    /**
     * Helper method to generate valid claim data for tests
     */
    private function getValidClaimData()
    {
        return [
            'insurer_id' => $this->insurer->id,
            'provider_name' => 'Test Provider',
            'encounter_date' => now()->subDays(5)->format('Y-m-d'),
            'submission_date' => now()->format('Y-m-d'),
            'priority_level' => 3,
            'specialty' => 'Cardiology',
            'items' => [
                [
                    'name' => 'Consultation',
                    'unit_price' => 150.00,
                    'quantity' => 1
                ],
                [
                    'name' => 'ECG Test',
                    'unit_price' => 85.50,
                    'quantity' => 2
                ]
            ]
        ];
    }
}
