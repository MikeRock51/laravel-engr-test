<?php

namespace App\Notifications;

use App\Models\Claim;
use App\Services\ClaimBatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClaimCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $claim;
    protected $estimatedCost;

    /**
     * Create a new notification instance.
     */
    public function __construct(Claim $claim)
    {
        $this->claim = $claim;
        $this->estimatedCost = $this->calculateEstimatedCost($claim);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('New Claim Submitted: #' . $this->claim->id)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A new claim has been submitted that requires your attention.')
            ->line('Claim Details:')
            ->line('- Provider: ' . $this->claim->provider_name)
            ->line('- Specialty: ' . $this->claim->specialty)
            ->line('- Encounter Date: ' . $this->claim->encounter_date->format('Y-m-d'))
            ->line('- Submission Date: ' . $this->claim->submission_date->format('Y-m-d'))
            ->line('- Priority Level: ' . $this->claim->priority_level)
            ->line('- Total Amount: $' . number_format($this->claim->total_amount, 2));

        // Add estimated processing cost if available
        if ($this->estimatedCost > 0) {
            $message->line('- Estimated Processing Cost: $' . number_format($this->estimatedCost, 2));
        }

        return $message
            ->action('View Claim Details', url('/claims/' . $this->claim->id))
            ->line('Thank you for using our claims processing system.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'claim_id' => $this->claim->id,
            'provider_name' => $this->claim->provider_name,
            'specialty' => $this->claim->specialty,
            'total_amount' => $this->claim->total_amount,
            'estimated_cost' => $this->estimatedCost,
        ];
    }

    /**
     * Get the claim instance.
     *
     * @return \App\Models\Claim
     */
    public function getClaim()
    {
        return $this->claim;
    }

    /**
     * Calculate the estimated processing cost for the claim
     *
     * @param Claim $claim
     * @return float
     */
    private function calculateEstimatedCost(Claim $claim): float
    {
        try {
            $insurer = $claim->insurer;
            $batchingService = app(ClaimBatchingService::class);

            // Get specialty cost
            $specialtyCost = $insurer->specialty_costs[$claim->specialty] ?? 100.0;

            // Get priority multiplier
            $priorityLevel = min(max((int)$claim->priority_level, 1), 5);
            $priorityMultiplier = (float)($insurer->priority_multipliers[$priorityLevel] ?? 1.0);

            // Get day factor based on preferred date
            $dateToUse = $insurer->date_preference === 'encounter_date'
                ? $claim->encounter_date
                : $claim->submission_date;
            $dayFactor = $batchingService->calculateDayFactor($dateToUse);

            // Get value multiplier
            $valueMultiplier = 1.0;
            if ($insurer->claim_value_threshold > 0 && $claim->total_amount > $insurer->claim_value_threshold) {
                $valueMultiplier = $insurer->claim_value_multiplier;
            }

            // Calculate total cost
            return $specialtyCost * $priorityMultiplier * $dayFactor * $valueMultiplier;
        } catch (\Exception $e) {
            // Log the error but don't prevent notification from being sent
            \Log::error('Error calculating estimated cost: ' . $e->getMessage());
            return 0;
        }
    }
}
