<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Services\ClaimBatchingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClaimDetailsController extends Controller
{
    protected $batchingService;

    public function __construct(ClaimBatchingService $batchingService)
    {
        $this->batchingService = $batchingService;
    }

    /**
     * Display the claim details.
     *
     * @param Claim $claim
     * @return \Inertia\Response
     */
    public function show(Claim $claim)
    {
        // Check if the authenticated user owns this claim
        if ($claim->user_id !== auth()->id()) {
            abort(403, 'Unauthorized action.');
        }

        // Eager load the relationships we need
        $claim->load([
            'items',
            'insurer'
        ]);

        // Calculate the estimated processing cost
        $estimatedCost = $this->calculateEstimatedCost($claim);

        // Return the Inertia view with the claim details
        return Inertia::render('ClaimDetails', [
            'claim' => $claim,
            'estimatedCost' => $estimatedCost,
            'costFactors' => $this->getCostFactors($claim)
        ]);
    }

    /**
     * Calculate the estimated processing cost for the claim
     *
     * @param Claim $claim
     * @return float
     */
    private function calculateEstimatedCost(Claim $claim): float
    {
        try {
            $insurer = $claim->insurer;

            // Get specialty cost
            $specialtyCost = $insurer->specialty_costs[$claim->specialty] ?? 100.0;

            // Get priority multiplier
            $priorityLevel = min(max((int)$claim->priority_level, 1), 5);
            $priorityMultiplier = (float)($insurer->priority_multipliers[$priorityLevel] ?? 1.0);

            // Get day factor based on preferred date
            $dateToUse = $insurer->date_preference === 'encounter_date'
                ? $claim->encounter_date
                : $claim->submission_date;
            $dayFactor = $this->batchingService->calculateDayFactor($dateToUse);

            // Get value multiplier
            $valueMultiplier = 1.0;
            if ($insurer->claim_value_threshold > 0 && $claim->total_amount > $insurer->claim_value_threshold) {
                $valueMultiplier = $insurer->claim_value_multiplier;
            }

            // Calculate total cost
            return $specialtyCost * $priorityMultiplier * $dayFactor * $valueMultiplier;
        } catch (\Exception $e) {
            \Log::error('Error calculating estimated cost: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the cost factors for the claim to display in the UI
     *
     * @param Claim $claim
     * @return array
     */
    private function getCostFactors(Claim $claim): array
    {
        $insurer = $claim->insurer;

        // Get specialty cost
        $specialtyCost = $insurer->specialty_costs[$claim->specialty] ?? 100.0;

        // Get priority multiplier
        $priorityLevel = min(max((int)$claim->priority_level, 1), 5);
        $priorityMultiplier = (float)($insurer->priority_multipliers[$priorityLevel] ?? 1.0);

        // Get day factor based on preferred date
        $dateToUse = $insurer->date_preference === 'encounter_date'
            ? $claim->encounter_date
            : $claim->submission_date;
        $dayFactor = $this->batchingService->calculateDayFactor($dateToUse);

        // Get value multiplier
        $valueMultiplier = 1.0;
        if ($insurer->claim_value_threshold > 0 && $claim->total_amount > $insurer->claim_value_threshold) {
            $valueMultiplier = $insurer->claim_value_multiplier;
        }

        return [
            'baseCost' => $specialtyCost,
            'priorityMultiplier' => $priorityMultiplier,
            'dayFactor' => $dayFactor,
            'valueMultiplier' => $valueMultiplier,
            'datePreference' => $insurer->date_preference,
            'dateUsed' => $dateToUse
        ];
    }
}
