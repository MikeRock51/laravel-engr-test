<?php

namespace App\Services;

use App\Models\Claim;
use App\Models\Insurer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Exceptions\BatchingException;

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
     * @throws BatchingException If there's an error in the batching process
     */
    public function processInsurerClaims(Insurer $insurer, ?int $userId = null): array
    {
        try {
            $pendingClaims = $this->getPendingClaims($insurer, $userId);
            if ($pendingClaims->isEmpty()) {
                return [];
            }

            // Use our new specialty-cost optimized sorting instead of just priority-based
            $sortedClaims = $this->sortClaimsBySpecialtyCost($pendingClaims, $insurer);

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
                throw new BatchingException(
                    "Error processing batches: " . $e->getMessage(),
                    [
                        'insurer' => $insurer->code,
                        'claims_count' => $pendingClaims->count(),
                    ],
                    0,
                    $e
                );
            }

            return $results;
        } catch (\Exception $e) {
            if ($e instanceof BatchingException) {
                throw $e;
            }

            throw new BatchingException(
                "Unexpected error during claim batching: " . $e->getMessage(),
                [
                    'insurer_id' => $insurer->id,
                    'insurer_code' => $insurer->code
                ],
                0,
                $e
            );
        }
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

        // Get optimal processing dates based on cost factors
        $optimalDates = $this->findOptimalProcessingDates();

        // First group claims by provider_name
        $providerGroups = $claims->groupBy('provider_name');

        foreach ($providerGroups as $providerName => $providerClaims) {
            // Group claims by specialty for better costing efficiency
            $specialtyGroups = $providerClaims->groupBy('specialty');

            // Sort specialties by their processing cost (lowest cost first)
            $specialtyCosts = [];
            foreach ($specialtyGroups as $specialty => $claims) {
                $specialtyCosts[$specialty] = $this->getSpecialtyCost($insurer, $specialty);
            }
            asort($specialtyCosts);

            // Process claims by specialty, starting with lowest-cost specialties
            foreach (array_keys($specialtyCosts) as $specialty) {
                $specialtyClaims = $specialtyGroups[$specialty];

                // Optimize high-priority claims by assigning them to early month dates
                // and low-priority claims to later dates or early next month
                $priorityGroups = $specialtyClaims->groupBy('priority_level');

                // Sort priority groups by key (highest first)
                // Using Laravel's sortByDesc instead of PHP's krsort which only works on arrays
                $priorityGroups = $priorityGroups->sortKeysDesc();

                foreach ($priorityGroups as $priority => $priorityClaims) {
                    // For claims with different priorities, use different date selection strategies
                    if ($priority >= 4) { // High priority (4-5)
                        // Use the earliest possible dates for highest priority claims
                        $datePool = array_slice($optimalDates, 0, 5, true);
                    } else if ($priority >= 2) { // Medium priority (2-3)
                        // Use mid-range dates
                        $datePool = array_slice($optimalDates, 5, 10, true);
                    } else { // Low priority (1)
                        // Use dates with lowest cost factors (likely early next month)
                        $datePool = array_slice($optimalDates, 15, 20, true);
                    }

                    // Reset date index for each priority group
                    $dateIndex = 0;
                    $availableDates = array_keys($datePool);

                    foreach ($priorityClaims as $claim) {
                        // Select a date from the appropriate pool, respecting daily capacity
                        $currentDate = $availableDates[$dateIndex % count($availableDates)];

                        // Check if we've reached daily capacity for the current date
                        while (
                            isset($dailyClaimCounts[$currentDate]) &&
                            $dailyClaimCounts[$currentDate] >= $insurer->daily_capacity
                        ) {
                            // Move to next date in the pool
                            $dateIndex++;
                            $currentDate = $availableDates[$dateIndex % count($availableDates)];
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
                                // Before adding, check if adding this claim would push the batch over the value threshold
                                if ($insurer->claim_value_threshold > 0) {
                                    $currentBatchValue = array_reduce($batchClaims, function ($sum, $claim) {
                                        return $sum + $claim->total_amount;
                                    }, 0);

                                    // If adding this claim would cross the threshold and the batch isn't small,
                                    // don't add it to this batch
                                    if (
                                        $currentBatchValue < $insurer->claim_value_threshold &&
                                        ($currentBatchValue + $claim->total_amount) > $insurer->claim_value_threshold &&
                                        count($batchClaims) >= $insurer->min_batch_size / 2
                                    ) {
                                        continue; // Try next batch
                                    }
                                }

                                $dailyBatches[$providerDateKey][$batchIndex][] = $claim;
                                $batchAssigned = true;
                                break;
                            }
                        }

                        // If no existing batch had room or was suitable, create a new one
                        if (!$batchAssigned) {
                            $dailyBatches[$providerDateKey][] = [$claim];
                        }

                        // Increment daily claim count
                        $dailyClaimCounts[$currentDate]++;

                        // Move to next date for next claim to distribute load
                        $dateIndex++;
                    }
                }
            }
        }

        // Further optimize batches with value threshold consideration
        $optimizedValueBatches = [];
        foreach ($dailyBatches as $providerDateKey => $batches) {
            $optimizedValueBatches[$providerDateKey] = [];

            foreach ($batches as $batch) {
                // Apply value threshold optimization to each batch
                $valueBatches = $this->optimizeForValueThresholds($batch, $insurer);

                // Add the resulting optimized batches
                foreach ($valueBatches as $valueBatch) {
                    $optimizedValueBatches[$providerDateKey][] = $valueBatch;
                }
            }
        }

        // Optimize batches to respect min_batch_size constraint
        return $this->optimizeBatchSizes($optimizedValueBatches, $insurer);
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
     * Using an enhanced cost calculation approach
     */
    private function createBatch(array $claims, string $batchId, string $batchDate): array
    {
        $totalValue = 0;
        $processingCost = 0;
        $claimIds = [];

        // Calculate totals with optimized cost calculation
        foreach ($claims as $claim) {
            $claimIds[] = $claim->id;
            $totalValue += $claim->total_amount;

            // Use our enhanced cost calculation that considers the batch date
            // Instead of the claim's current processing cost calculation
            $insurer = $claim->insurer;
            $processingCost += $this->calculateEstimatedCost($claim, $insurer, $batchDate);
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

    /**
     * Calculate the cost factor based on day of month (20% on 1st, 50% on 30th)
     *
     * @param string $date The date to calculate the factor for
     * @return float The cost factor (between 0.2 and 0.5)
     */
    public function calculateDayFactor(string $date): float
    {
        $day = (int)Carbon::parse($date)->format('j');
        $maxDay = (int)Carbon::parse($date)->endOfMonth()->format('j');

        // Linear scale from 0.2 (20%) on day 1 to 0.5 (50%) on the last day
        return 0.2 + (0.3 * ($day - 1) / ($maxDay - 1));
    }

    /**
     * Find optimal processing dates with the lowest cost factors
     *
     * @param int $daysToLook Number of days to look ahead for scheduling
     * @return array Array of dates sorted by cost factor (lowest first)
     */
    private function findOptimalProcessingDates(int $daysToLook = 30): array
    {
        $today = Carbon::now();
        $dates = [];

        // Look at current month dates
        $currentMonth = clone $today;
        while ($currentMonth->month === $today->month) {
            $dateStr = $currentMonth->format('Y-m-d');
            $dates[$dateStr] = $this->calculateDayFactor($dateStr);
            $currentMonth->addDay();
        }

        // Look at early next month dates (which will have lower cost factors)
        $nextMonthStart = $today->copy()->startOfMonth()->addMonth();
        $nextMonthEnd = $nextMonthStart->copy()->addDays(10); // Look at first 10 days of next month

        $nextMonth = clone $nextMonthStart;
        while ($nextMonth < $nextMonthEnd) {
            $dateStr = $nextMonth->format('Y-m-d');
            $dates[$dateStr] = $this->calculateDayFactor($dateStr);
            $nextMonth->addDay();
        }

        // Sort dates by cost factor (lowest first)
        asort($dates);

        return $dates;
    }

    /**
     * Calculate the estimated processing cost for a claim on a specific date
     *
     * @param Claim $claim The claim to calculate cost for
     * @param Insurer $insurer The insurer processing the claim
     * @param string $date The processing date to calculate cost for
     * @return float The estimated processing cost
     */
    private function calculateEstimatedCost(Claim $claim, Insurer $insurer, string $date): float
    {
        $baseCost = $this->getSpecialtyCost($insurer, $claim->specialty);

        // Apply priority level multiplier
        $priorityLevel = min(max((int)$claim->priority_level, 1), 5);
        $priorityMultiplier = (float)($insurer->priority_multipliers[$priorityLevel] ?? 1.0);

        // Apply day of month cost adjustment
        $dayFactor = $this->calculateDayFactor($date);

        // Apply claim value multiplier if applicable
        $valueMultiplier = 1.0;
        if ($insurer->claim_value_threshold > 0 && $claim->total_amount > $insurer->claim_value_threshold) {
            $valueMultiplier = $insurer->claim_value_multiplier;
        }

        return $baseCost * $priorityMultiplier * $dayFactor * $valueMultiplier;
    }

    /**
     * Get the specialty cost for an insurer
     *
     * @param Insurer $insurer The insurer to get the specialty cost for
     * @param string $specialty The specialty to get the cost for
     * @return float The base cost for processing a claim of this specialty
     */
    private function getSpecialtyCost(Insurer $insurer, string $specialty): float
    {
        return $insurer->specialty_costs[$specialty] ?? 100.0; // Default to 100 if not specified
    }

    /**
     * Sort claims by specialty cost and priority
     * Prioritize specialties with lower processing costs
     *
     * @param Collection $claims The claims to sort
     * @param Insurer $insurer The insurer for cost calculation
     * @return Collection The sorted claims
     */
    private function sortClaimsBySpecialtyCost(Collection $claims, Insurer $insurer): Collection
    {
        // Create a mapping of specialty to cost for quick lookup
        $specialtyCosts = [];
        foreach ($claims->pluck('specialty')->unique() as $specialty) {
            $specialtyCosts[$specialty] = $this->getSpecialtyCost($insurer, $specialty);
        }

        // Custom sorting function that considers specialty cost, priority, and date
        $dateField = $insurer->date_preference;

        return $claims->sortBy(function ($claim) use ($specialtyCosts, $dateField) {
            // Lower specialty cost and higher priority should come first
            // Convert to string for proper sorting with multiple criteria
            $specialtyCost = $specialtyCosts[$claim->specialty] ?? 100.0;
            $priorityScore = 6 - $claim->priority_level; // Invert so higher priority is first
            $dateValue = strtotime($claim->$dateField);

            // Format: COST.PRIORITY.DATE
            // This creates a sortable string where lower values come first
            return sprintf('%08.2f.%d.%d', $specialtyCost, $priorityScore, $dateValue);
        });
    }

    /**
     * Group claims into value-optimized batches to minimize threshold penalties
     *
     * @param array $claims The claims to organize into batches
     * @param Insurer $insurer The insurer with threshold constraints
     * @return array The optimized batches
     */
    private function optimizeForValueThresholds(array $claims, Insurer $insurer): array
    {
        // If the insurer doesn't have a value threshold, no need for this optimization
        if ($insurer->claim_value_threshold <= 0 || $insurer->claim_value_multiplier <= 1.0) {
            return [$claims];
        }

        $threshold = $insurer->claim_value_threshold;
        $batches = [];
        $currentBatch = [];
        $currentTotal = 0;

        // Sort claims by value (highest first) for more efficient distribution
        usort($claims, function ($a, $b) {
            return $b->total_amount <=> $a->total_amount;
        });

        // First pass: handle claims larger than the threshold individually
        $largeValueClaims = [];
        $normalClaims = [];

        foreach ($claims as $claim) {
            if ($claim->total_amount >= $threshold) {
                $largeValueClaims[] = $claim;
            } else {
                $normalClaims[] = $claim;
            }
        }

        // Each large claim gets its own batch since it already exceeds the threshold
        foreach ($largeValueClaims as $claim) {
            $batches[] = [$claim];
        }

        // Use a bin packing approach for the rest
        // Sort normal claims by descending value
        usort($normalClaims, function ($a, $b) {
            return $b->total_amount <=> $a->total_amount;
        });

        foreach ($normalClaims as $claim) {
            // If adding this claim would exceed the threshold, start a new batch
            if ($currentTotal + $claim->total_amount > $threshold) {
                if (!empty($currentBatch)) {
                    $batches[] = $currentBatch;
                }
                $currentBatch = [$claim];
                $currentTotal = $claim->total_amount;
            } else {
                $currentBatch[] = $claim;
                $currentTotal += $claim->total_amount;
            }
        }

        // Add the last batch if it's not empty
        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        return $batches;
    }
}
