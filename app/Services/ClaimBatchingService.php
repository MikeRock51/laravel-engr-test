<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\Insurer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimBatchingService
{
    /**
     * Process all unbatched claims and create appropriate batches
     *
     * @param int|null $userId Optional user ID to filter claims by user
     * @return array
     */
    public function processPendingClaims(?int $userId = null): array
    {
        $results = [];

        // Only fetch insurers with pending claims (optimization to avoid processing insurers with no claims)
        $insurersQuery = Insurer::query();
        if ($userId !== null) {
            $insurersQuery->whereHas('claims', function ($query) use ($userId) {
                $query->where('is_batched', false)
                    ->where('status', 'pending')
                    ->where('user_id', $userId);
            });
        } else {
            $insurersQuery->whereHas('claims', function ($query) {
                $query->where('is_batched', false)
                    ->where('status', 'pending');
            });
        }

        $insurers = $insurersQuery->get();

        foreach ($insurers as $insurer) {
            $batchResults = $this->processInsurerClaims($insurer, $userId);
            if (!empty($batchResults)) {
                $results[$insurer->code] = $batchResults;
            }
        }

        return $results;
    }

    /**
     * Process claims for a specific insurer
     *
     * @param Insurer $insurer
     * @param int|null $userId Optional user ID to filter claims by user
     * @return array
     */
    public function processInsurerClaims(Insurer $insurer, ?int $userId = null): array
    {
        $pendingClaims = $this->getPendingClaims($insurer, $userId);
        if ($pendingClaims->isEmpty()) {
            return [];
        }

        // Sort claims by priority and date
        $sortedClaims = $this->sortClaimsByPriority($pendingClaims, $insurer);

        $results = [];
        $dailyBatches = $this->createOptimizedDailyBatches($sortedClaims, $insurer);

        // Process batches in a transaction for better performance and data integrity
        DB::beginTransaction();
        try {
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
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Get all pending claims for an insurer
     *
     * @param Insurer $insurer
     * @param int|null $userId Optional user ID to filter claims by user
     * @return Collection
     */
    private function getPendingClaims(Insurer $insurer, ?int $userId = null): Collection
    {
        $query = Claim::where('insurer_id', $insurer->id)
            ->where('is_batched', false)
            ->where('status', 'pending')
            ->with('items'); // Eager load items to prevent N+1 issues later

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

    /**
     * Sort claims by priority and preferred date (encounter or submission)
     */
    private function sortClaimsByPriority(Collection $claims, Insurer $insurer): Collection
    {
        $dateField = $insurer->date_preference;

        // Using a more efficient sorting approach
        $sortedClaims = $claims->sortBy([
            ['priority_level', 'desc'],
            [$dateField, 'asc']
        ]);

        return $sortedClaims;
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

                    // Initialize counters using null coalescing operator for better performance
                    $dailyClaimCounts[$currentDate] = $dailyClaimCounts[$currentDate] ?? 0;

                    // Create a composite key for provider+date
                    $providerDateKey = $providerName . '|' . $currentDate;

                    // Initialize provider+date batches array if not set
                    if (!isset($dailyBatches[$providerDateKey])) {
                        $dailyBatches[$providerDateKey] = [];
                    }

                    // Find an existing batch that has room
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
                    // Add claims to pending pool without using foreach for better performance
                    $pendingClaims = array_merge($pendingClaims, $batch);
                } else {
                    // Batch meets minimum size requirement
                    $optimizedBatches[$providerDateKey][] = $batch;
                }
            }

            // Second pass: redistribute pending claims to existing batches
            if (!empty($pendingClaims)) {
                // Sort pending claims by value to optimize batch distribution
                usort($pendingClaims, function ($a, $b) {
                    return $b->total_amount <=> $a->total_amount;
                });

                $remainingClaims = [];
                foreach ($pendingClaims as $claim) {
                    $added = false;

                    // Try to add to existing batches that have room
                    foreach ($optimizedBatches[$providerDateKey] as $batchIndex => $batch) {
                        if (count($batch) < $insurer->max_batch_size) {
                            $optimizedBatches[$providerDateKey][$batchIndex][] = $claim;
                            $added = true;
                            break;
                        }
                    }

                    // If couldn't add to existing batches, add to remaining claims
                    if (!$added) {
                        $remainingClaims[] = $claim;
                    }
                }

                // Third pass: if we still have pending claims, create new batches
                // Use a more efficient grouping approach for remaining claims
                if (!empty($remainingClaims)) {
                    $newBatches = array_chunk($remainingClaims, max($insurer->min_batch_size, (int)ceil(count($remainingClaims) / 2)));

                    foreach ($newBatches as $index => $newBatch) {
                        // Only if the batch meets minimum size or it's the last group of remaining claims
                        if (count($newBatch) >= $insurer->min_batch_size || $index === count($newBatches) - 1) {
                            if (count($newBatch) >= $insurer->min_batch_size) {
                                $optimizedBatches[$providerDateKey][] = $newBatch;
                            } else {
                                // For smaller last batch, move to next day
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
     * Using a more efficient bulk update approach
     */
    private function createBatch(array $claims, string $batchId, string $batchDate): array
    {
        $totalValue = 0;
        $processingCost = 0;
        $claimIds = [];

        // Calculate totals first
        foreach ($claims as $claim) {
            $claimIds[] = $claim->id;
            $totalValue += $claim->total_amount;
            $processingCost += $claim->getProcessingCost();
        }

        // Use bulk update instead of individual saves for better performance
        Claim::whereIn('id', $claimIds)->update([
            'batch_id' => $batchId,
            'is_batched' => true,
            'batch_date' => $batchDate,
            'status' => 'batched'
        ]);

        return [
            'total_value' => $totalValue,
            'processing_cost' => $processingCost
        ];
    }
}
