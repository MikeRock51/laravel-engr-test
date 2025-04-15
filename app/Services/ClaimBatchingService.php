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
                        $batchId = $this->generateBatchId($providerName, $date, $batchIndex);
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

        // Prepare all claims with metadata for batch assignment in a single pass
        $claimsWithMetadata = $this->prepareClaimsForBatching($claims, $insurer, $optimalDates);

        // Process the prepared claims for batching
        foreach ($claimsWithMetadata as $claimData) {
            $this->assignClaimToBatch(
                $claimData['claim'],
                $claimData['provider_name'],
                $claimData['date_pool'],
                $dailyBatches,
                $dailyClaimCounts,
                $insurer
            );
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
     * Prepare claims for batching by adding necessary metadata
     */
    private function prepareClaimsForBatching(Collection $claims, Insurer $insurer, array $optimalDates): array
    {
        $preparedClaims = [];

        // Group by provider first
        $providerGroups = $claims->groupBy('provider_name');

        foreach ($providerGroups as $providerName => $providerClaims) {
            // Get claims grouped by specialty and sorted by processing cost
            $specialtyGroups = $this->groupAndSortBySpecialty($providerClaims, $insurer);

            foreach ($specialtyGroups as $specialty => $specialtyClaims) {
                // Group claims by priority level and sort (highest first)
                $priorityGroups = $specialtyClaims->groupBy('priority_level')->sortKeysDesc();

                foreach ($priorityGroups as $priority => $priorityClaims) {
                    // Determine date pool based on priority
                    $datePool = $this->getDatePoolForPriority($priority, $optimalDates);

                    // Add each claim with its metadata
                    foreach ($priorityClaims as $claim) {
                        $preparedClaims[] = [
                            'claim' => $claim,
                            'provider_name' => $providerName,
                            'specialty' => $specialty,
                            'priority' => $priority,
                            'date_pool' => $datePool
                        ];
                    }
                }
            }
        }

        return $preparedClaims;
    }

    /**
     * Group claims by specialty and sort by processing cost
     */
    private function groupAndSortBySpecialty(Collection $claims, Insurer $insurer): Collection
    {
        $specialtyGroups = $claims->groupBy('specialty');

        // Get processing costs for each specialty
        $specialtyCosts = [];
        foreach ($specialtyGroups as $specialty => $claims) {
            $specialtyCosts[$specialty] = $this->getSpecialtyCost($insurer, $specialty);
        }

        // Sort specialties by cost (lowest first)
        asort($specialtyCosts);

        // Create a new collection with specialties in the correct order
        $sortedGroups = new Collection();
        foreach (array_keys($specialtyCosts) as $specialty) {
            if (isset($specialtyGroups[$specialty])) {
                $sortedGroups[$specialty] = $specialtyGroups[$specialty];
            }
        }

        return $sortedGroups;
    }

    /**
     * Get appropriate date pool based on claim priority
     */
    private function getDatePoolForPriority(int $priority, array $optimalDates): array
    {
        if ($priority >= 4) { // High priority (4-5)
            // Use the earliest possible dates for highest priority claims
            return array_slice($optimalDates, 0, 5, true);
        } elseif ($priority >= 2) { // Medium priority (2-3)
            // Use mid-range dates
            return array_slice($optimalDates, 5, 10, true);
        } else { // Low priority (1)
            // Use dates with lowest cost factors (likely early next month)
            return array_slice($optimalDates, 15, 20, true);
        }
    }

    /**
     * Assign a claim to an appropriate batch
     */
    private function assignClaimToBatch(
        Claim $claim,
        string $providerName,
        array $datePool,
        array &$dailyBatches,
        array &$dailyClaimCounts,
        Insurer $insurer
    ): void {
        $availableDates = array_keys($datePool);
        $dateIndex = 0;

        // Find a suitable date that hasn't reached capacity
        $currentDate = $this->findAvailableDate($availableDates, $dailyClaimCounts, $insurer, $dateIndex);

        // Initialize counter for the selected date
        $dailyClaimCounts[$currentDate] = ($dailyClaimCounts[$currentDate] ?? 0) + 1;

        // Create composite key for provider+date and initialize if needed
        $providerDateKey = $providerName . '|' . $currentDate;
        if (!isset($dailyBatches[$providerDateKey])) {
            $dailyBatches[$providerDateKey] = [];
        }

        // Find an existing batch or create a new one
        $this->addClaimToBatch($claim, $providerDateKey, $dailyBatches, $insurer);
    }

    /**
     * Find a date that hasn't reached capacity
     */
    private function findAvailableDate(array $availableDates, array $dailyClaimCounts, Insurer $insurer, int &$dateIndex): string
    {
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

        return $currentDate;
    }

    /**
     * Add a claim to an appropriate batch
     */
    private function addClaimToBatch(Claim $claim, string $providerDateKey, array &$dailyBatches, Insurer $insurer): void
    {
        $batchAssigned = false;

        // Try to find an existing batch with room
        foreach ($dailyBatches[$providerDateKey] as $batchIndex => $batchClaims) {
            if (count($batchClaims) < $insurer->max_batch_size) {
                // Check value threshold if applicable
                if ($this->canAddToExistingBatch($claim, $batchClaims, $insurer)) {
                    $dailyBatches[$providerDateKey][$batchIndex][] = $claim;
                    $batchAssigned = true;
                    break;
                }
            }
        }

        // Create a new batch if needed
        if (!$batchAssigned) {
            $dailyBatches[$providerDateKey][] = [$claim];
        }
    }

    /**
     * Check if a claim can be added to an existing batch based on value threshold
     */
    private function canAddToExistingBatch(Claim $claim, array $batchClaims, Insurer $insurer): bool
    {
        // If insurer has no threshold, any batch with space is fine
        if ($insurer->claim_value_threshold <= 0) {
            return true;
        }

        // Calculate current batch value
        $currentBatchValue = array_reduce($batchClaims, function ($sum, $claim) {
            return $sum + $claim->total_amount;
        }, 0);

        // If adding claim would cross threshold and batch isn't small, avoid this batch
        if (
            $currentBatchValue < $insurer->claim_value_threshold &&
            ($currentBatchValue + $claim->total_amount) > $insurer->claim_value_threshold &&
            count($batchClaims) >= $insurer->min_batch_size / 2
        ) {
            return false;
        }

        return true;
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
    private function generateBatchId(string $providerName, string $date, int $batchIndex): string
    {
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

        $dateField = $insurer->date_preference;

        // Custom sorting function that considers specialty cost, priority, and date
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
