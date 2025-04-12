<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class DailyClaimBatchNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The batches to notify about.
     */
    protected $batches;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(array $batches)
    {
        $this->batches = $batches;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $totalClaims = 0;
        $totalValue = 0;

        foreach ($this->batches as $batch) {
            $totalClaims += $batch['claim_count'] ?? 0;
            $totalValue += $batch['total_value'] ?? 0;
        }

        $mailMessage = (new MailMessage)
            ->subject('Daily Claim Batches - ' . Carbon::now()->format('Y-m-d'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('The following claim batches have been processed today:')
            ->line('Total Batches: ' . count($this->batches))
            ->line('Total Claims: ' . $totalClaims)
            ->line('Total Value: $' . number_format($totalValue, 2));

        foreach ($this->batches as $batch) {
            $mailMessage->line('');
            $mailMessage->line('Batch ID: ' . $batch['batch_id']);
            $mailMessage->line('Claims: ' . ($batch['claim_count'] ?? 0));
            $mailMessage->line('Total: $' . number_format($batch['total_value'] ?? 0, 2));
        }

        return $mailMessage
            ->action('View Batches', url('/batches'))
            ->line('Thank you for using our claims processing system.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'batches' => $this->batches,
        ];
    }
}
