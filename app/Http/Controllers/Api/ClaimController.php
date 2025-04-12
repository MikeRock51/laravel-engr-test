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
    public function getBatchSummary()
    {
        $batches = Claim::where('is_batched', true)
            ->where('user_id', auth()->id())
            ->select('batch_id', 'batch_date', 'insurer_id', 'provider_name')
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
}
