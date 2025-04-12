<?php

namespace App\Notifications;

use App\Models\Claim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClaimCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $claim;

    /**
     * Create a new notification instance.
     */
    public function __construct(Claim $claim)
    {
        $this->claim = $claim;
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
        return (new MailMessage)
            ->subject('New Claim Submitted: #' . $this->claim->id)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('A new claim has been submitted that requires your attention.')
            ->line('Claim Details:')
            ->line('- Provider: ' . $this->claim->provider_name)
            ->line('- Specialty: ' . $this->claim->specialty)
            ->line('- Encounter Date: ' . $this->claim->encounter_date->format('Y-m-d'))
            ->line('- Submission Date: ' . $this->claim->submission_date->format('Y-m-d'))
            ->line('- Priority Level: ' . $this->claim->priority_level)
            ->line('- Total Amount: $' . number_format($this->claim->total_amount, 2))
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
}
