<?php

namespace Tests\Feature\Notifications;

use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;
use App\Models\User;
use App\Notifications\ClaimCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClaimNotificationTest extends TestCase
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

        // Create a test insurer
        $this->insurer = Insurer::factory()->create([
            'specialty_costs' => [
                'Cardiology' => 150.00,
                'Dermatology' => 100.00
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
     * Test that the notification includes estimated processing cost
     */
    public function test_notification_includes_estimated_processing_cost()
    {
        // Create a fresh notification instance
        $notification = new ClaimCreatedNotification($this->claim);

        // We'll test that the array representation includes the estimated cost
        $arrayData = $notification->toArray($this->insurer);

        $this->assertArrayHasKey('estimated_cost', $arrayData);
        $this->assertGreaterThan(0, $arrayData['estimated_cost']);

        // Get the mail content as a string to test for the presence of cost information
        $mailMessage = $notification->toMail($this->insurer);

        // Convert the mail message to a string for testing
        // This is a simplistic approach, but works for our testing purposes
        $mailString = json_encode($mailMessage);

        // Check if the mail contains cost-related text
        $this->assertStringContainsString('Estimated Processing Cost', $mailString);
    }

    /**
     * Test that the notification includes all required claim data
     */
    public function test_notification_includes_required_claim_data()
    {
        // Create a notification instance
        $notification = new ClaimCreatedNotification($this->claim);

        // Check notification properties
        $this->assertEquals($this->claim->id, $notification->getClaim()->id);

        // Check array representation includes estimated cost
        $arrayData = $notification->toArray($this->insurer);
        $this->assertArrayHasKey('estimated_cost', $arrayData);

        // The cost should be calculated, not zero
        $this->assertGreaterThan(0, $arrayData['estimated_cost']);
    }

    /**
     * Test that the notification calculates costs correctly
     */
    public function test_notification_calculates_costs_correctly()
    {
        // Create notifications for claims with different priority levels and verify costs differ
        $lowPriorityClaim = clone $this->claim;
        $lowPriorityClaim->priority_level = 1;
        $lowPriorityNotification = new ClaimCreatedNotification($lowPriorityClaim);

        $highPriorityClaim = clone $this->claim;
        $highPriorityClaim->priority_level = 5;
        $highPriorityNotification = new ClaimCreatedNotification($highPriorityClaim);

        $lowPriorityCost = $lowPriorityNotification->toArray($this->insurer)['estimated_cost'];
        $highPriorityCost = $highPriorityNotification->toArray($this->insurer)['estimated_cost'];

        // High priority should be more expensive
        $this->assertGreaterThan($lowPriorityCost, $highPriorityCost);
    }
}
