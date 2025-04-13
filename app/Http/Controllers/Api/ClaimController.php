<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;
use App\Notifications\ClaimCreatedNotification;
use App\Services\ClaimBatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class ClaimController extends Controller
{
    protected $batchingService;

    public function __construct(ClaimBatchingService $batchingService)
    {
        $this->batchingService = $batchingService;
    }

    /**
     * Get all insurers for dropdown selection
     */
    public function getInsurers()
    {
        try {
            // Check if we have cached data
            $insurers = Cache::get('insurers_dropdown');

            // If cache is empty or doesn't exist, force a refresh
            if (!$insurers || count($insurers) === 0) {
                Cache::forget('insurers_dropdown');
                $insurers = Insurer::select('id', 'name', 'code')->get();

                // Only cache if we have data
                if (count($insurers) > 0) {
                    Cache::put('insurers_dropdown', $insurers, now()->addMinutes(30));
                }
            }

            return response()->json($insurers);
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Error fetching insurers: ' . $e->getMessage());

            // Try to get fresh data without caching in case of an error
            $insurers = Insurer::select('id', 'name', 'code')->get();
            return response()->json($insurers);
        }
    }

    /**
     * Submit a new claim with items
     */
    public function submitClaim(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'insurer_id' => 'required|exists:insurers,id',
            'provider_name' => 'required|string|max:255',
            'encounter_date' => 'required|date|before_or_equal:today',
            'submission_date' => 'required|date',
            'priority_level' => 'required|integer|min:1|max:5',
            'specialty' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        try {
            DB::beginTransaction();

            // Create the claim
            $claim = new Claim();
            $claim->user_id = auth()->id(); // Associate claim with current user
            $claim->insurer_id = $request->insurer_id;
            $claim->provider_name = $request->provider_name;
            $claim->encounter_date = $request->encounter_date;
            $claim->submission_date = $request->submission_date;
            $claim->priority_level = $request->priority_level;
            $claim->specialty = $request->specialty;
            $claim->total_amount = 0; // Will be updated after items are added
            $claim->status = 'pending';
            $claim->save();

            // Create claim items
            $totalAmount = 0;
            foreach ($request->items as $itemData) {
                $item = new ClaimItem();
                $item->claim_id = $claim->id;
                $item->name = $itemData['name'];
                $item->unit_price = $itemData['unit_price'];
                $item->quantity = $itemData['quantity'];
                $item->save();

                $totalAmount += $item->subtotal;
            }

            // Update claim with total amount
            $claim->total_amount = $totalAmount;
            $claim->save();

            // Notify the insurer about the new claim
            $insurer = Insurer::find($claim->insurer_id);
            $insurer->notify(new ClaimCreatedNotification($claim));

            DB::commit();

            // Check if this is an Inertia request or an API request
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Claim submitted successfully',
                    'claim' => $claim->load('items')
                ]);
            }

            // For Inertia requests, redirect to dashboard with a success flash message
            return redirect()->route('dashboard')->with('success', 'Claim submitted successfully!');
        } catch (\Exception $e) {
            DB::rollBack();

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to submit claim',
                    'error' => $e->getMessage()
                ], 500);
            }

            return back()->with('error', 'Failed to submit claim: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Get claims with optional filtering
     * Optimized for better query performance
     */
    public function getClaims(Request $request)
    {
        // Start with a query builder and only eager load necessary relationships
        $query = Claim::query();

        // Only select the fields we need for the list display
        $query->select([
            'id',
            'insurer_id',
            'provider_name',
            'encounter_date',
            'submission_date',
            'priority_level',
            'specialty',
            'total_amount',
            'batch_id',
            'status',
            'is_batched',
            'batch_date',
            'created_at'
        ]);

        // Apply filters if provided
        if ($request->has('insurer_id')) {
            $query->where('insurer_id', $request->insurer_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('submission_date', [$request->from_date, $request->to_date]);
        }

        // User-specific filter
        if ($request->has('user_filter') && $request->user_filter) {
            $query->where('user_id', auth()->id());
        }

        // Add eager loading only when necessary
        if ($request->has('with_items') && $request->with_items) {
            $query->with('items');
        }

        // Always load the insurer but only select needed fields
        $query->with(['insurer:id,name,code']);

        // Implement proper caching if the same query is run frequently
        $cacheKey = 'claims_' . md5(json_encode($request->all()) . '_' . auth()->id());
        $cacheDuration = 5; // Cache for 5 minutes

        // Return from cache if available
        if (\Illuminate\Support\Facades\Cache::has($cacheKey) && !$request->has('no_cache')) {
            return response()->json(\Illuminate\Support\Facades\Cache::get($cacheKey));
        }

        // Optimize ordering for better performance (ensure indexes exist on these columns)
        $orderBy = $request->input('order_by', 'created_at');
        $orderDir = $request->input('order_dir', 'desc');

        // Validate order by column to prevent SQL injection
        $allowedColumns = ['created_at', 'submission_date', 'total_amount', 'priority_level', 'provider_name'];
        if (!in_array($orderBy, $allowedColumns)) {
            $orderBy = 'created_at';
        }

        $query->orderBy($orderBy, $orderDir);

        // Use cursor pagination for better performance with large datasets
        $perPage = min((int)$request->input('per_page', 15), 50); // Limit max per page
        $claims = $query->paginate($perPage);

        // Store in cache
        \Illuminate\Support\Facades\Cache::put($cacheKey, $claims, $cacheDuration * 60);

        return response()->json($claims);
    }

    /**
     * Process pending claims into batches
     */
    public function processBatches()
    {
        try {
            // Get the current user's ID
            $userId = auth()->id();

            // Pass the user ID to filter claims by this user
            $results = $this->batchingService->processPendingClaims($userId);

            return response()->json([
                'success' => true,
                'message' => 'Claims processed successfully',
                'batches' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process claims',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a summary of batches
     */
    public function getBatchSummary(Request $request)
    {
        $query = Claim::where('is_batched', true)
            ->where('user_id', auth()->id());

        // Apply filters if provided
        if ($request->has('insurer_id') && $request->insurer_id) {
            $query->where('insurer_id', $request->insurer_id);
        }

        if ($request->has('from_date') && $request->from_date) {
            $fromDate = $request->from_date;
            if ($request->has('to_date') && $request->to_date) {
                $toDate = $request->to_date;
                $query->whereBetween('batch_date', [$fromDate, $toDate]);
            } else {
                $query->where('batch_date', '>=', $fromDate);
            }
        } else if ($request->has('to_date') && $request->to_date) {
            $query->where('batch_date', '<=', $request->to_date);
        }

        $batches = $query->select('batch_id', 'batch_date', 'insurer_id', 'provider_name')
            ->selectRaw('COUNT(*) as claim_count')
            ->selectRaw('SUM(total_amount) as total_value')
            ->groupBy('batch_id', 'batch_date', 'insurer_id', 'provider_name')
            ->with('insurer:id,name,code')
            ->orderBy('batch_date', 'desc')
            ->get();

        // Format the batch data to match the provider name + date format
        $batches->each(function ($batch) {
            // The batch_id already contains the provider name + date format generated by our service
            // Add a formatted_date field for better display
            $batch->formatted_date = \Carbon\Carbon::parse($batch->batch_date)->format('M j Y');
        });

        return response()->json($batches);
    }

    /**
     * Manually trigger the daily claim batching process
     */
    public function triggerDailyBatch()
    {
        try {
            // Call Artisan command directly
            \Artisan::call('claims:process-daily-batch');

            return response()->json([
                'success' => true,
                'message' => 'Daily claim batching process triggered successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger daily claim batching',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed insurer information for cost estimation
     * This endpoint provides extended information about insurers for frontend use
     */
    public function getInsurerDetails()
    {
        try {
            // Check if we have cached data
            $insurerDetails = Cache::get('insurers_details');

            // If cache is empty or doesn't exist, force a refresh
            if (!$insurerDetails) {
                // Only fetch necessary fields to optimize the payload
                $insurers = Insurer::select([
                    'id',
                    'name',
                    'code',
                    'date_preference',
                    'specialty_costs',
                    'priority_multipliers',
                    'claim_value_threshold',
                    'claim_value_multiplier',
                    'daily_capacity',
                    'min_batch_size',
                    'max_batch_size'
                ])->get();

                // Index by ID for easier frontend access
                $insurerDetails = [];
                foreach ($insurers as $insurer) {
                    $insurerDetails[$insurer->id] = $insurer;
                }

                // Cache for 30 minutes
                Cache::put('insurers_details', $insurerDetails, now()->addMinutes(30));
            }

            return response()->json($insurerDetails);
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Error fetching insurer details: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch insurer details'], 500);
        }
    }

    /**
     * Estimate claim processing cost based on provided parameters
     * This helps users understand how different factors affect processing costs
     */
    public function estimateClaimCost(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'insurer_id' => 'required|exists:insurers,id',
            'specialty' => 'required|string',
            'priority_level' => 'required|integer|min:1|max:5',
            'total_amount' => 'required|numeric|min:0',
            'encounter_date' => 'nullable|date',
            'submission_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $insurer = Insurer::findOrFail($request->insurer_id);

            // Calculate all cost factors
            $baseCost = $this->getSpecialtyCost($insurer, $request->specialty);

            // Priority level multiplier
            $priorityLevel = min(max((int)$request->priority_level, 1), 5);
            $priorityMultiplier = (float)($insurer->priority_multipliers[$priorityLevel] ?? 1.0);

            // Day factor based on preferred date
            $dateToUse = $insurer->date_preference === 'encounter_date' && $request->encounter_date
                ? $request->encounter_date
                : $request->submission_date;

            $dayFactor = $this->batchingService->calculateDayFactor($dateToUse);

            // Value multiplier for high-value claims
            $valueMultiplier = 1.0;
            if ($insurer->claim_value_threshold > 0 && $request->total_amount > $insurer->claim_value_threshold) {
                $valueMultiplier = $insurer->claim_value_multiplier;
            }

            // Calculate total cost
            $totalCost = $baseCost * $priorityMultiplier * (1 + $dayFactor) * $valueMultiplier;

            // Generate batching tips based on claim characteristics
            $batchingTips = $this->generateBatchingTips(
                $insurer,
                $request->specialty,
                $priorityLevel,
                $request->total_amount,
                $dateToUse
            );

            return response()->json([
                'baseCost' => $baseCost,
                'priorityMultiplier' => $priorityMultiplier,
                'dayFactor' => $dayFactor,
                'valueMultiplier' => $valueMultiplier,
                'totalCost' => $totalCost,
                'batchingTips' => $batchingTips
            ]);
        } catch (\Exception $e) {
            \Log::error('Error calculating claim cost estimate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to calculate cost estimate'], 500);
        }
    }

    /**
     * Generate batching tips based on claim characteristics
     */
    private function generateBatchingTips(Insurer $insurer, string $specialty, int $priority, float $amount, ?string $date)
    {
        $tips = [];

        // Specialty cost tip
        $specialtyCost = $this->getSpecialtyCost($insurer, $specialty);
        $allSpecialtyCosts = collect($insurer->specialty_costs);
        $cheapestSpecialty = $allSpecialtyCosts->sortKeys()->keys()->first();
        $mostExpensiveSpecialty = $allSpecialtyCosts->sortKeysDesc()->keys()->first();

        if ($specialty === $mostExpensiveSpecialty) {
            $tips[] = "This claim's specialty ($specialty) has a high processing cost with this insurer. Consider using an insurer with better rates for this specialty.";
        }

        // Priority level tip
        if ($priority >= 4) {
            $tips[] = "High priority (level $priority) significantly increases processing costs. Only use high priority for urgent claims.";
        }

        // Value threshold tip
        if ($insurer->claim_value_threshold > 0) {
            if ($amount > $insurer->claim_value_threshold) {
                $tips[] = "This claim exceeds the insurer's value threshold (\${$insurer->claim_value_threshold}), incurring a {$insurer->claim_value_multiplier}x multiplier.";
            } elseif ($amount > $insurer->claim_value_threshold * 0.9) {
                $tips[] = "This claim is approaching the insurer's value threshold (\${$insurer->claim_value_threshold}). Consider splitting into multiple claims if possible.";
            }
        }

        // Date-related tip
        if ($date) {
            $dayFactor = $this->batchingService->calculateDayFactor($date);
            if ($dayFactor > 0.35) { // If in the later part of the month
                $tips[] = "Processing claims at this time of month incurs higher costs. Early month processing is more economical.";
            }
        }

        return $tips;
    }

    /**
     * Get the specialty cost for an insurer
     */
    private function getSpecialtyCost(Insurer $insurer, string $specialty): float
    {
        return $insurer->specialty_costs[$specialty] ?? 100.0; // Default to 100 if not specified
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
