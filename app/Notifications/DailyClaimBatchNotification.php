<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

// Remove ShouldQueue interface to make this notification send immediately instead of being queued
class DailyClaimBatchNotification extends Notification
{
    use Queueable;

    /**
     * The batches to notify about.
     */
    protected $batches;

    /**
     * Maximum number of tries for the job
     */
    public $tries = 3;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(array $batches)
    {
        $this->batches = $batches;

        // Remove queueing configuration to ensure notifications are sent immediately
        // without relying on a queue worker
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
        // Precalculate totals once
        $totalClaims = array_sum(array_column($this->batches, 'claim_count') ?: [0]);
        $totalValue = array_sum(array_column($this->batches, 'total_value') ?: [0]);
        $batchCount = count($this->batches);

        $mailMessage = (new MailMessage)
            ->subject('Daily Claim Batches - ' . Carbon::now()->format('Y-m-d'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('The following claim batches have been processed today:')
            ->line('Total Batches: ' . $batchCount)
            ->line('Total Claims: ' . $totalClaims)
            ->line('Total Value: $' . number_format($totalValue, 2));

        // Limit the number of batches shown in the email to prevent oversized emails
        $displayBatches = array_slice($this->batches, 0, 20);

        foreach ($displayBatches as $batch) {
            $mailMessage->line('');
            $mailMessage->line('Batch ID: ' . $batch['batch_id']);
            $mailMessage->line('Claims: ' . ($batch['claim_count'] ?? 0));
            $mailMessage->line('Total: $' . number_format($batch['total_value'] ?? 0, 2));
        }

        // If there are more batches than we're displaying
        if (count($this->batches) > count($displayBatches)) {
            $mailMessage->line('');
            $mailMessage->line('... and ' . (count($this->batches) - count($displayBatches)) . ' more batches.');
        }

        return $mailMessage
            ->action('View All Batches', url('/batches'))
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
            'batch_count' => count($this->batches),
            'total_claims' => array_sum(array_column($this->batches, 'claim_count') ?: [0]),
            'total_value' => array_sum(array_column($this->batches, 'total_value') ?: [0]),
        ];
    }
}
