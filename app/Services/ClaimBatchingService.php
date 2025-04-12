<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\Insurer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ClaimBatchingService
{
    /**
     * Process all unbatched claims and create appropriate batches
     */
    public function processPendingClaims(): array
    {
        $results = [];
        $insurers = Insurer::all();

        foreach ($insurers as $insurer) {
            $batchResults = $this->processInsurerClaims($insurer);
            if (!empty($batchResults)) {
                $results[$insurer->code] = $batchResults;
            }
        }

        return $results;
    }

    /**
     * Process claims for a specific insurer
     */
    public function processInsurerClaims(Insurer $insurer): array
    {
        $pendingClaims = $this->getPendingClaims($insurer);
        if ($pendingClaims->isEmpty()) {
            return [];
        }

        // Sort claims by priority and date
        $sortedClaims = $this->sortClaimsByPriority($pendingClaims, $insurer);

        $results = [];
        $dailyBatches = $this->createOptimizedDailyBatches($sortedClaims, $insurer);

        foreach ($dailyBatches as $providerDateKey => $batches) {
            // Extract the date portion from the provider|date key for storing in claim record
            list($providerName, $date) = explode('|', $providerDateKey);

            foreach ($batches as $batchIndex => $claims) {
                $batchId = $this->generateBatchId($insurer, $providerDateKey, $batchIndex);
                $batchResults = $this->createBatch($claims, $batchId, $date);
                $results[] = [
                    'batch_id' => $batchId,
                    'provider_name' => $providerName,
                    'date' => $date,
                    'claim_count' => count($claims),
                    'total_value' => $batchResults['total_value'],
                    'processing_cost' => $batchResults['processing_cost']
                ];
            }
        }

        return $results;
    }

    /**
     * Get all pending claims for an insurer
     */
    private function getPendingClaims(Insurer $insurer): Collection
    {
        return Claim::where('insurer_id', $insurer->id)
            ->where('is_batched', false)
            ->where('status', 'pending')
            ->get();
    }

    /**
     * Sort claims by priority and preferred date (encounter or submission)
     */
    private function sortClaimsByPriority(Collection $claims, Insurer $insurer): Collection
    {
        $dateField = $insurer->date_preference;

        return $claims->sortByDesc(function ($claim) {
            return $claim->priority_level;
        })->sortBy(function ($claim) use ($dateField) {
            return $claim->$dateField;
        });
    }

    /**
     * Create optimized daily batches of claims
     */
    private function createOptimizedDailyBatches(Collection $claims, Insurer $insurer): array
    {
        $dailyBatches = [];
        $dailyClaimCounts = [];
        $today = Carbon::now()->format('Y-m-d');

        // First group claims by provider_name, then by specialty for better organization
        $providerGroups = $claims->groupBy('provider_name');

        foreach ($providerGroups as $providerName => $providerClaims) {
            // Group claims by specialty for better costing efficiency
            $specialtyGroups = $providerClaims->groupBy('specialty');

            foreach ($specialtyGroups as $specialty => $specialtyClaims) {
                $currentDate = $today;

                foreach ($specialtyClaims as $claim) {
                    // Check if we've reached daily capacity for the current date
                    if (
                        isset($dailyClaimCounts[$currentDate]) &&
                        $dailyClaimCounts[$currentDate] >= $insurer->daily_capacity
                    ) {
                        // Move to next day
                        $currentDate = Carbon::parse($currentDate)->addDay()->format('Y-m-d');
                    }

                    // Initialize daily claim count if not set
                    if (!isset($dailyClaimCounts[$currentDate])) {
                        $dailyClaimCounts[$currentDate] = 0;
                    }

                    // Create a composite key for provider+date
                    $providerDateKey = $providerName . '|' . $currentDate;

                    // Initialize provider+date batches array if not set
                    if (!isset($dailyBatches[$providerDateKey])) {
                        $dailyBatches[$providerDateKey] = [];
                    }

                    // Find an existing batch that has room or create a new one
                    $batchAssigned = false;
                    foreach ($dailyBatches[$providerDateKey] as $batchIndex => $batchClaims) {
                        if (count($batchClaims) < $insurer->max_batch_size) {
                            $dailyBatches[$providerDateKey][$batchIndex][] = $claim;
                            $batchAssigned = true;
                            break;
                        }
                    }

                    // If no existing batch had room, create a new one
                    if (!$batchAssigned) {
                        $dailyBatches[$providerDateKey][] = [$claim];
                    }

                    // Increment daily claim count
                    $dailyClaimCounts[$currentDate]++;
                }
            }
        }

        // Optimize batches to respect min_batch_size constraint
        return $this->optimizeBatchSizes($dailyBatches, $insurer);
    }

    /**
     * Optimize batch sizes to respect min_batch_size and max_batch_size constraints
     */
    private function optimizeBatchSizes(array $dailyBatches, Insurer $insurer): array
    {
        $optimizedBatches = [];

        foreach ($dailyBatches as $providerDateKey => $batches) {
            $optimizedBatches[$providerDateKey] = [];
            $pendingClaims = [];

            // First pass: identify batches below min_batch_size
            foreach ($batches as $batch) {
                if (count($batch) < $insurer->min_batch_size) {
                    // Add claims to pending pool
                    foreach ($batch as $claim) {
                        $pendingClaims[] = $claim;
                    }
                } else {
                    // Batch meets minimum size requirement
                    $optimizedBatches[$providerDateKey][] = $batch;
                }
            }

            // Second pass: redistribute pending claims to existing batches
            if (!empty($pendingClaims)) {
                foreach ($pendingClaims as $index => $claim) {
                    $added = false;

                    // Try to add to existing batches that have room
                    foreach ($optimizedBatches[$providerDateKey] as $batchIndex => $batch) {
                        if (count($batch) < $insurer->max_batch_size) {
                            $optimizedBatches[$providerDateKey][$batchIndex][] = $claim;
                            $added = true;
                            unset($pendingClaims[$index]);
                            break;
                        }
                    }

                    // If couldn't add to existing batches, leave it for now
                    if (!$added) {
                        continue;
                    }
                }

                // Third pass: if we still have pending claims, create new batches
                // as long as they meet the minimum size
                if (!empty($pendingClaims)) {
                    $newBatch = [];
                    foreach ($pendingClaims as $claim) {
                        $newBatch[] = $claim;

                        // If batch reaches min size, add it to optimized batches
                        if (count($newBatch) >= $insurer->min_batch_size) {
                            $optimizedBatches[$providerDateKey][] = $newBatch;
                            $newBatch = [];
                        }
                    }

                    // If we have a partial batch left, move to next day
                    if (!empty($newBatch)) {
                        // Extract provider name and date from the key
                        list($providerName, $date) = explode('|', $providerDateKey);
                        $nextDate = Carbon::parse($date)->addDay()->format('Y-m-d');
                        $nextProviderDateKey = $providerName . '|' . $nextDate;

                        if (!isset($optimizedBatches[$nextProviderDateKey])) {
                            $optimizedBatches[$nextProviderDateKey] = [];
                        }
                        $optimizedBatches[$nextProviderDateKey][] = $newBatch;
                    }
                }
            }
        }

        return $optimizedBatches;
    }

    /**
     * Generate a unique batch ID
     */
    private function generateBatchId(Insurer $insurer, string $providerDateKey, int $batchIndex): string
    {
        // Extract provider name and date from the key
        list($providerName, $date) = explode('|', $providerDateKey);

        // Format date as specified (e.g., "Jan 5 2021")
        $formattedDate = Carbon::parse($date)->format('M j Y');

        // Create batch ID in format: Provider Name + Date (+ sequence if multiple batches for same provider/date)
        $batchId = $providerName . ' ' . $formattedDate;

        // If there are multiple batches for the same provider and date, add a sequence number
        if ($batchIndex > 0) {
            $batchId .= ' (' . ($batchIndex + 1) . ')';
        }

        return $batchId;
    }

    /**
     * Create a batch by updating the claims with batch information
     */
    private function createBatch(array $claims, string $batchId, string $batchDate): array
    {
        $totalValue = 0;
        $processingCost = 0;

        foreach ($claims as $claim) {
            $claim->batch_id = $batchId;
            $claim->is_batched = true;
            $claim->batch_date = $batchDate;
            $claim->status = 'batched';
            $claim->save();

            $totalValue += $claim->total_amount;
            $processingCost += $claim->getProcessingCost();
        }

        return [
            'total_value' => $totalValue,
            'processing_cost' => $processingCost
        ];
    }
}
