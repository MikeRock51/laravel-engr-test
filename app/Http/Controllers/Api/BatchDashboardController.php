<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\Insurer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchDashboardController extends Controller
{
    /**
     * Get summary data for the batch dashboard
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSummary(Request $request)
    {
        // Apply filters
        $query = $this->applyFilters(Claim::query()->where('is_batched', true), $request);

        // Get total batches
        $totalBatches = $query->distinct('batch_id')->count('batch_id');

        // Get total claims
        $totalClaims = $query->count();

        // Get average claims per batch
        $avgClaimsPerBatch = $totalBatches > 0 ? $totalClaims / $totalBatches : 0;

        // Get insurer count
        $insurerCount = $query->distinct('insurer_id')->count('insurer_id');

        // Instead of the complex query, use a simpler approach to calculate costs
        $claims = $query->with('insurer')->get();
        $totalValue = 0;
        $actualCost = 0;
        $worstCaseCost = 0;

        foreach ($claims as $claim) {
            $totalValue += $claim->total_amount;

            // Calculate actual cost
            $specialtyCost = isset($claim->insurer->specialty_costs[$claim->specialty])
                ? $claim->insurer->specialty_costs[$claim->specialty]
                : 100.0;

            $priorityMultiplier = 1.0;
            switch ($claim->priority_level) {
                case 5:
                    $priorityMultiplier = 1.5;
                    break;
                case 4:
                    $priorityMultiplier = 1.2;
                    break;
                case 3:
                    $priorityMultiplier = 1.0;
                    break;
                case 2:
                    $priorityMultiplier = 0.9;
                    break;
                case 1:
                    $priorityMultiplier = 0.8;
                    break;
            }

            $dayFactor = $claim->batch_date
                ? 0.2 + ((Carbon::parse($claim->batch_date)->day - 1) * 0.3 / 29)
                : 0.5;

            $valueMultiplier = 1.0;
            if (
                $claim->insurer->claim_value_threshold > 0 &&
                $claim->total_amount > $claim->insurer->claim_value_threshold
            ) {
                $valueMultiplier = $claim->insurer->claim_value_multiplier;
            }

            $actualCost += $specialtyCost * $priorityMultiplier * $dayFactor * $valueMultiplier;

            // Worst case: highest priority, end of month, highest value multiplier
            $worstCaseCost += $claim->total_amount * 1.5;
        }

        $costSavings = $worstCaseCost - $actualCost;
        $savingsPercentage = $worstCaseCost > 0 ? round(($costSavings / $worstCaseCost) * 100) : 0;

        return response()->json([
            'totalBatches' => $totalBatches,
            'totalClaims' => $totalClaims,
            'avgClaimsPerBatch' => round($avgClaimsPerBatch, 1),
            'costSavings' => round($costSavings, 2),
            'savingsPercentage' => $savingsPercentage,
            'insurerCount' => $insurerCount
        ]);
    }

    /**
     * Get chart data for the batch dashboard
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getChartData(Request $request)
    {
        // Apply filters
        $query = $this->applyFilters(Claim::query()->where('is_batched', true), $request);

        // Get day factor data
        $dayFactorData = $this->getDayFactorData($query);

        // Get specialty data
        $specialtyData = $this->getSpecialtyData($query);

        return response()->json([
            'dayFactorData' => $dayFactorData,
            'specialtyData' => $specialtyData
        ]);
    }

    /**
     * Get batches for the batch dashboard
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBatches(Request $request)
    {
        // Apply filters
        $query = $this->applyFilters(Claim::query()->where('is_batched', true), $request);

        // Use a simpler approach by first getting the batches with basic information
        $batchGroups = $query->select(
            'batch_id',
            'batch_date',
            'insurer_id',
            DB::raw('COUNT(*) as claim_count'),
            DB::raw('SUM(total_amount) as total_value')
        )
            ->groupBy('batch_id', 'batch_date', 'insurer_id')
            ->with('insurer:id,name,code')
            ->orderBy('batch_date', 'desc')
            ->paginate(10);

        // Add insurer name and calculate costs for each batch
        $batchGroups->getCollection()->transform(function ($batch) {
            $batch->insurer_name = $batch->insurer->name;

            // Get all claims in this batch
            $claims = Claim::where('batch_id', $batch->batch_id)
                ->with('insurer')
                ->get();

            $processingCost = 0;
            $estimatedSavings = 0;
            $worstCaseCost = $batch->total_value * 1.5;

            foreach ($claims as $claim) {
                // Calculate processing cost
                $specialtyCost = isset($claim->insurer->specialty_costs[$claim->specialty])
                    ? $claim->insurer->specialty_costs[$claim->specialty]
                    : 100.0;

                $priorityMultiplier = 1.0;
                switch ($claim->priority_level) {
                    case 5:
                        $priorityMultiplier = 1.5;
                        break;
                    case 4:
                        $priorityMultiplier = 1.2;
                        break;
                    case 3:
                        $priorityMultiplier = 1.0;
                        break;
                    case 2:
                        $priorityMultiplier = 0.9;
                        break;
                    case 1:
                        $priorityMultiplier = 0.8;
                        break;
                }

                $dayFactor = $claim->batch_date
                    ? 0.2 + ((Carbon::parse($claim->batch_date)->day - 1) * 0.3 / 29)
                    : 0.5;

                $valueMultiplier = 1.0;
                if (
                    $claim->insurer->claim_value_threshold > 0 &&
                    $claim->total_amount > $claim->insurer->claim_value_threshold
                ) {
                    $valueMultiplier = $claim->insurer->claim_value_multiplier;
                }

                $claimCost = $specialtyCost * $priorityMultiplier * $dayFactor * $valueMultiplier;
                $processingCost += $claimCost;
            }

            $estimatedSavings = $worstCaseCost - $processingCost;

            $batch->processing_cost = $processingCost;
            $batch->estimated_savings = $estimatedSavings;

            return $batch;
        });

        return response()->json($batchGroups);
    }

    /**
     * Apply filters to query
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Request $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyFilters($query, Request $request)
    {
        // Insurer filter
        if ($request->has('insurer_id') && $request->insurer_id) {
            $query->where('insurer_id', $request->insurer_id);
        }

        // Date range filter
        if ($request->has('date_range') && $request->date_range !== 'all') {
            $days = (int)$request->date_range;
            $query->where('batch_date', '>=', Carbon::now()->subDays($days));
        }

        // Specialty filter
        if ($request->has('specialty') && $request->specialty) {
            $query->where('specialty', $request->specialty);
        }

        // Min priority filter
        if ($request->has('min_priority') && (int)$request->min_priority > 0) {
            $query->where('priority_level', '>=', (int)$request->min_priority);
        }

        return $query;
    }

    /**
     * Get day factor data for chart
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return array
     */
    private function getDayFactorData($query)
    {
        // Use a clone of the query to avoid modifying the original
        $clonedQuery = clone $query;
        $claimsByDay = $clonedQuery->get()->groupBy(function ($claim) {
            return Carbon::parse($claim->batch_date)->day;
        });

        $labels = [];
        $claimCounts = [];
        $costFactors = [];

        foreach ($claimsByDay as $day => $claims) {
            $labels[] = 'Day ' . $day;
            $claimCounts[] = $claims->count();

            // Calculate cost factor for this day (20% on day 1, 50% on day 30)
            $costFactors[] = round((0.2 + (($day - 1) * 0.3 / 29)) * 100);
        }

        return [
            'labels' => $labels,
            'claimCounts' => $claimCounts,
            'costFactors' => $costFactors
        ];
    }

    /**
     * Get specialty data for chart
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return array
     */
    private function getSpecialtyData($query)
    {
        // Use a clone of the query to avoid modifying the original
        $clonedQuery = clone $query;
        $claimsBySpecialty = $clonedQuery->with('insurer')->get()->groupBy('specialty');

        $labels = [];
        $costs = [];

        foreach ($claimsBySpecialty as $specialty => $claims) {
            $labels[] = $specialty;

            $specialtyCost = 0;
            foreach ($claims as $claim) {
                // Calculate cost
                $baseCost = isset($claim->insurer->specialty_costs[$specialty])
                    ? $claim->insurer->specialty_costs[$specialty]
                    : 100.0;

                $priorityMultiplier = 1.0;
                switch ($claim->priority_level) {
                    case 5:
                        $priorityMultiplier = 1.5;
                        break;
                    case 4:
                        $priorityMultiplier = 1.2;
                        break;
                    case 3:
                        $priorityMultiplier = 1.0;
                        break;
                    case 2:
                        $priorityMultiplier = 0.9;
                        break;
                    case 1:
                        $priorityMultiplier = 0.8;
                        break;
                }

                $dayFactor = $claim->batch_date
                    ? 0.2 + ((Carbon::parse($claim->batch_date)->day - 1) * 0.3 / 29)
                    : 0.5;

                $valueMultiplier = 1.0;
                if (
                    $claim->insurer->claim_value_threshold > 0 &&
                    $claim->total_amount > $claim->insurer->claim_value_threshold
                ) {
                    $valueMultiplier = $claim->insurer->claim_value_multiplier;
                }

                $specialtyCost += $baseCost * $priorityMultiplier * $dayFactor * $valueMultiplier;
            }

            $costs[] = round($specialtyCost, 2);
        }

        return [
            'labels' => $labels,
            'costs' => $costs
        ];
    }
}
