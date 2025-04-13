# Claim Batching Algorithm Documentation

## Overview

This document explains the claim batching algorithm implemented in the healthcare claims processing platform. The algorithm is designed to minimize processing costs for insurers while respecting various constraints such as batch sizes, daily capacities, and specialty preferences.

## Key Objectives

1. **Cost Minimization**: Reduce total processing costs for insurers
2. **Constraint Satisfaction**: Honor all insurer-specific constraints
3. **Efficiency**: Process claims in a computationally efficient manner
4. **Adaptability**: Handle varying claim patterns and insurer requirements

## Cost Factors

Processing costs are influenced by several factors:

1. **Time of Month**: Costs increase linearly from 20% on the 1st to 50% on the 30th of each month
2. **Specialty**: Different insurers have varying costs for processing different medical specialties
3. **Priority Level**: Higher priority claims (1-5 scale) incur higher processing costs
4. **Claim Value**: Claims exceeding a value threshold incur additional processing costs

## Cost Calculation Formula

The processing cost for each claim is calculated using the following formula:

```
ProcessingCost = BaseCost × PriorityMultiplier × DayFactor × ValueMultiplier
```

Where:
- **BaseCost**: The specialty-specific cost defined by the insurer
- **PriorityMultiplier**: Factor based on claim priority level (1-5)
  - Priority 5: 1.5× multiplier
  - Priority 4: 1.2× multiplier
  - Priority 3: 1.0× multiplier (standard)
  - Priority 2: 0.9× multiplier
  - Priority 1: 0.8× multiplier
- **DayFactor**: Increases linearly from 0.2 (20%) on the 1st to 0.5 (50%) on the last day of month
- **ValueMultiplier**: Additional multiplier for claims exceeding the insurer's value threshold

## Batching Process

### 1. Initial Grouping

Claims are first grouped by:
- Provider name
- Medical specialty
- Priority level

### 2. Date Selection Strategy

The algorithm uses a date-selection strategy based on priority levels:
- **High Priority (4-5)**: Assigned to early month dates (lowest cost factor)
- **Medium Priority (2-3)**: Assigned to mid-month dates
- **Low Priority (1)**: Assigned to late month or early next month dates

### 3. Batch Size Optimization

Batches are optimized to:
- Meet minimum batch size requirements
- Not exceed maximum batch size limits
- Respect daily processing capacity constraints

### 4. Value Threshold Handling

The algorithm uses a bin-packing approach to handle claim value thresholds:
- Claims above the threshold are processed individually
- Claims below the threshold are grouped strategically to minimize threshold penalties

## Detailed Algorithm Steps

### Step 1: Claim Retrieval and Initial Processing

1. Identify insurers with pending claims to process
2. For each insurer, retrieve all pending, unbatched claims
3. Apply any user-specific filtering if required

### Step 2: Specialty-Based Sorting

1. Group claims by provider name
2. Within each provider group, sort claims by:
   - Specialty processing cost (ascending)
   - Priority level (descending)
   - Preferred date (based on insurer's date preference)

### Step 3: Optimal Date Selection

1. Calculate cost factors for all days in the current month
2. Calculate cost factors for the first 10 days of the next month
3. Sort dates by cost factor (lowest first)
4. Create date pools for different priority levels:
   - High priority (4-5): First 5 days (lowest cost factor)
   - Medium priority (2-3): Days 6-15
   - Low priority (1): Days 16-30 or early next month

### Step 4: Claim Distribution and Batch Creation

1. For each provider and specialty combination:
   - Distribute claims across selected dates based on priority
   - Respect insurer's daily processing capacity
   - Create initial batches ensuring no batch exceeds maximum size
   - Track batch values to optimize for threshold constraints

### Step 5: Value Threshold Optimization

1. For each batch, identify claims that exceed the value threshold
2. Process large-value claims individually to avoid multiplier penalties
3. For remaining claims, use bin-packing to create batches that stay below threshold:
   - Sort claims by descending value
   - Add claims to batches ensuring total batch value stays below threshold
   - Start new batches when adding a claim would exceed the threshold

### Step 6: Batch Size Consolidation

1. Identify batches that are below the minimum batch size
2. Move claims from small batches into a pending claims pool
3. Redistribute pending claims to existing batches where possible
4. Create new batches from remaining claims, ensuring they meet minimum size
5. If small batches still exist, consider moving them to a different date

### Step 7: Batch Finalization and Persistence

1. Generate unique batch IDs for each batch (Provider Name + Date + Optional Sequence)
2. Calculate total batch value and processing cost
3. Update claims with batch information in a transaction for data integrity
4. Prepare batch summary data for notification and reporting

## Optimization Techniques

### Specialty Cost Optimization

Claims are sorted by specialty cost (lowest first) to prioritize specialties with the lowest processing costs for each insurer.

```php
// Sort specialties by processing cost
$specialtyCosts = [];
foreach ($specialtyGroups as $specialty => $claims) {
    $specialtyCosts[$specialty] = $this->getSpecialtyCost($insurer, $specialty);
}
asort($specialtyCosts);
```

### Priority-Based Scheduling

Higher priority claims are scheduled on dates with lower cost factors to minimize total processing costs.

```php
// Date pool selection based on priority
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
```

### Batch Consolidation

Small batches that don't meet minimum size requirements are:
1. Consolidated with other batches where possible
2. Moved to alternative dates if consolidation isn't possible
3. Processed as-is only when no other options are available

### Value Threshold Management

The algorithm minimizes the impact of value threshold multipliers by:
- Isolating high-value claims
- Grouping lower-value claims to stay below thresholds where possible

```php
// First pass: handle claims larger than the threshold individually
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
```

## Daily Cost Factor Calculation

The day factor increases linearly from 20% on the 1st of the month to 50% on the last day:

```php
public function calculateDayFactor(string $date): float
{
    $day = (int)Carbon::parse($date)->format('j');
    $maxDay = (int)Carbon::parse($date)->endOfMonth()->format('j');

    // Linear scale from 0.2 (20%) on day 1 to 0.5 (50%) on the last day
    return 0.2 + (0.3 * ($day - 1) / ($maxDay - 1));
}
```

## Error Handling and Edge Cases

The algorithm includes robust error handling for various edge cases:

1. **Empty Claims**: Returns an empty result without error when no pending claims exist
2. **Insufficient Batch Size**: Claims are redistributed to other batches or dates
3. **Capacity Constraints**: Claims are scheduled across multiple days when daily capacity is exceeded
4. **Singleton Batches**: High-value claims that must be processed individually are handled properly
5. **Transaction Management**: All database operations are wrapped in a transaction to ensure data integrity

## Performance Considerations

### Time Complexity

- **Overall Complexity**: O(n log n) where n is the number of claims
- **Sorting Operations**: O(n log n) for specialty and priority sorting
- **Batch Creation**: O(n) for distributing claims into batches
- **Value Optimization**: O(n log n) for bin packing algorithm

### Space Complexity

- **Overall Space**: O(n) for storing claim groups and batches
- **Temporary Data Structures**: O(n) for date pools and priority groups
- **Batch Tracking**: O(n) for storing batch information

### Optimization Strategies

1. **Eager Loading**: Claims are eager-loaded with related items to prevent N+1 query issues
2. **Bulk Updates**: Batch updates use efficient bulk database operations
3. **Transaction Management**: Database operations are wrapped in transactions for atomicity
4. **Caching**: Cost factors and optimal dates are cached for reuse
5. **Efficient Sorting**: Multi-key sorting leverages database and collection operations

## Integration with Laravel Framework

The algorithm is implemented in the `ClaimBatchingService` class and integrates with Laravel's:

1. **Eloquent ORM**: For efficient database operations
2. **Queue System**: For processing large batches asynchronously
3. **Notification System**: For sending batch results to insurers
4. **Scheduling**: For automatic daily batch processing
5. **Transaction Management**: For ensuring data integrity

## Batch Notification System

After batches are created, insurers are notified using Laravel's notification system:

```php
$insurer->notify(new DailyClaimBatchNotification($batches));
```

The notification includes:
- Batch summaries
- Claim counts
- Total values
- Processing costs
- Estimated savings

## Example Scenario

Consider the following scenario:
- 100 claims across 3 specialties
- Insurer with daily capacity of 50 claims
- Min batch size: 10, Max batch size: 20
- Value threshold: $5,000 with 1.2x multiplier for claims above threshold

The algorithm will:
1. Group claims by provider and specialty
2. Sort by specialty cost and priority
3. Assign high-priority claims to early-month dates
4. Create batches that respect size constraints
5. Isolate claims above the $5,000 threshold
6. Consolidate small batches where possible

The result is optimized batches that minimize total processing costs while respecting all constraints.

## Implementation Details

The algorithm is implemented in the `ClaimBatchingService` class with the following key methods:

- `processPendingClaims()`: Entry point for batch processing
- `processInsurerClaims()`: Processes claims for a specific insurer
- `getPendingClaims()`: Retrieves unbatched pending claims
- `sortClaimsBySpecialtyCost()`: Sorts claims by specialty cost and priority
- `createOptimizedDailyBatches()`: Creates optimized batches by date
- `findOptimalProcessingDates()`: Identifies dates with lowest cost factors
- `optimizeBatchSizes()`: Ensures batches meet size constraints
- `optimizeForValueThresholds()`: Minimizes the impact of value threshold multipliers
- `calculateEstimatedCost()`: Calculates the processing cost for a claim
- `calculateDayFactor()`: Determines the day-of-month cost factor
- `createBatch()`: Updates claims with batch information

## Command Line Interface

The algorithm can be triggered via an Artisan command:

```bash
php artisan claims:process-daily-batch
```

This command:
1. Processes all pending claims
2. Creates optimized batches
3. Sends notifications to insurers
4. Logs results for auditing

## Dashboard Integration

The batching results are accessible through the API endpoints:

- `/api/claims/batch-summary`: Returns a summary of batches
- `/api/batch-dashboard/summary`: Provides processing cost and savings statistics
- `/api/batch-dashboard/batches`: Lists all batches with detailed information

## Cost Savings Calculation

The algorithm calculates cost savings by comparing the optimized processing cost to a worst-case scenario:

```
Savings = WorstCaseCost - ActualCost
```

Where:
- **WorstCaseCost**: Processing all claims at highest priority, end of month
- **ActualCost**: The sum of optimized processing costs for all claims

## Testing and Validation

The algorithm has comprehensive test coverage:

1. **Unit Tests**: Test individual components and calculations
2. **Feature Tests**: Verify end-to-end functionality
3. **Performance Tests**: Ensure efficiency with large claim volumes
4. **Edge Case Tests**: Validate behavior with unusual inputs
5. **Integration Tests**: Confirm proper interaction with other system components

## Future Improvements

Potential areas for algorithm enhancement:

- **Machine Learning**: Predict optimal batching patterns based on historical data
- **Dynamic Adaptation**: Adjust parameters based on processing feedback
- **Real-time Optimization**: Update batches as new claims arrive
- **Parallel Processing**: Distribute batch creation across multiple workers
- **Advanced Forecasting**: Predict claim volumes to optimize resource allocation
- **Insurer-specific Workflows**: Customize batching logic for individual insurers
- **Neural Network Optimization**: Use deep learning to discover optimal batching patterns