<?php

namespace App\Console\Commands;

use App\Models\Insurer;
use App\Notifications\DailyClaimBatchNotification;
use App\Services\ClaimBatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessDailyClaimBatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'claims:process-daily-batch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all pending claims into batches and notify insurers';

    /**
     * The batching service.
     */
    protected $batchingService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(ClaimBatchingService $batchingService)
    {
        parent::__construct();
        $this->batchingService = $batchingService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting daily claim batching process...');

        try {
            // Process all pending claims into batches
            $results = $this->batchingService->processPendingClaims();

            if (empty($results)) {
                $this->info('No pending claims to process.');
                return 0;
            }

            $this->info('Successfully batched claims. Sending notifications to insurers...');

            // Group batches by insurer for notifications
            $batchesByInsurer = [];

            // The results are already grouped by insurer code
            foreach ($results as $insurerCode => $batches) {
                // Find the insurer by code
                $insurer = Insurer::where('code', $insurerCode)->first();
                if ($insurer) {
                    // Send notification
                    $insurer->notify(new DailyClaimBatchNotification($batches));
                    $this->info("Notification sent to {$insurer->name}");
                }
            }

            $this->info('Daily claim batching completed successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to process daily claim batches: ' . $e->getMessage());
            Log::error('Daily claim batching error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
