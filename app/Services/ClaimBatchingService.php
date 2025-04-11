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

        foreach ($dailyBatches as $date => $batches) {
            foreach ($batches as $batchIndex => $claims) {
                $batchId = $this->generateBatchId($insurer, $date, $batchIndex);
                $batchResults = $this->createBatch($claims, $batchId, $date);
                $results[] = [
                    'batch_id' => $batchId,
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

        // Group claims by specialty for better costing efficiency
        $specialtyGroups = $claims->groupBy('specialty');

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

                // Initialize daily batches array if not set
                if (!isset($dailyBatches[$currentDate])) {
                    $dailyBatches[$currentDate] = [];
                }

                // Find an existing batch that has room or create a new one
                $batchAssigned = false;
                foreach ($dailyBatches[$currentDate] as $batchIndex => $batchClaims) {
                    if (count($batchClaims) < $insurer->max_batch_size) {
                        $dailyBatches[$currentDate][$batchIndex][] = $claim;
                        $batchAssigned = true;
                        break;
                    }
                }

                // If no existing batch had room, create a new one
                if (!$batchAssigned) {
                    $dailyBatches[$currentDate][] = [$claim];
                }

                // Increment daily claim count
                $dailyClaimCounts[$currentDate]++;
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

        foreach ($dailyBatches as $date => $batches) {
            $optimizedBatches[$date] = [];
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
                    $optimizedBatches[$date][] = $batch;
                }
            }

            // Second pass: redistribute pending claims to existing batches
            if (!empty($pendingClaims)) {
                foreach ($pendingClaims as $index => $claim) {
                    $added = false;

                    // Try to add to existing batches that have room
                    foreach ($optimizedBatches[$date] as $batchIndex => $batch) {
                        if (count($batch) < $insurer->max_batch_size) {
                            $optimizedBatches[$date][$batchIndex][] = $claim;
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
                            $optimizedBatches[$date][] = $newBatch;
                            $newBatch = [];
                        }
                    }

                    // If we have a partial batch left, move to next day
                    if (!empty($newBatch)) {
                        $nextDate = Carbon::parse($date)->addDay()->format('Y-m-d');
                        if (!isset($optimizedBatches[$nextDate])) {
                            $optimizedBatches[$nextDate] = [];
                        }
                        $optimizedBatches[$nextDate][] = $newBatch;
                    }
                }
            }
        }

        return $optimizedBatches;
    }

    /**
     * Generate a unique batch ID
     */
    private function generateBatchId(Insurer $insurer, string $date, int $batchIndex): string
    {
        $dateStr = str_replace('-', '', $date);
        return $insurer->code . '-' . $dateStr . '-' . ($batchIndex + 1) . '-' . Str::random(4);
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
