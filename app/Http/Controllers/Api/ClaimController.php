<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Models\ClaimItem;
use App\Models\Insurer;
use App\Services\ClaimBatchingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        $insurers = Insurer::select('id', 'name', 'code')->get();
        return response()->json($insurers);
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
            'encounter_date' => 'required|date',
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
     */
    public function getClaims(Request $request)
    {
        $query = Claim::with(['insurer', 'items']);

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

        $claims = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json($claims);
    }

    /**
     * Process pending claims into batches
     */
    public function processBatches()
    {
        try {
            $results = $this->batchingService->processPendingClaims();

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
            ->select('batch_id', 'batch_date', 'insurer_id')
            ->selectRaw('COUNT(*) as claim_count')
            ->selectRaw('SUM(total_amount) as total_value')
            ->groupBy('batch_id', 'batch_date', 'insurer_id')
            ->with('insurer:id,name,code')
            ->orderBy('batch_date', 'desc')
            ->get();

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
