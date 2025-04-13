<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Api\ClaimController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Redirect the root URL to the dashboard
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Group routes that require authentication
Route::middleware(['auth'])->group(function () {
    // Submit Claim Page (now protected)
    Route::get('/submit-claim', function () {
        return Inertia::render('SubmitClaim');
    })->name('submit-claim');

    // Add a web route for claim submission that works with Inertia forms
    Route::post('/claims/submit', [ClaimController::class, 'submitClaim'])->name('claims.submit');

    // Add web routes for batch operations
    Route::post('/claims/process-batches', [ClaimController::class, 'processBatches'])->name('claims.process-batches');
    Route::get('/claims/batch-summary', [ClaimController::class, 'getBatchSummary'])->name('claims.batch-summary');
    Route::get('/claims/list', [ClaimController::class, 'getClaims'])->name('claims.list');

    // Claim details page - updated to use ClaimController instead of ClaimDetailsController
    Route::get('/claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');

    // Claim Batches Page (now protected)
    Route::get('/batches', function () {
        return Inertia::render('ClaimBatches');
    })->name('batches');

    // Dashboard Page (requires email verification)
    Route::get('/dashboard', function () {
        $claims = auth()->user()->claims()
            ->with('insurer')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Dashboard', [
            'claims' => $claims
        ]);
    })->middleware(['verified'])->name('dashboard');

    // Add a route for the batch dashboard visualization
    Route::get('/batch-dashboard', function () {
        return Inertia::render('BatchDashboard');
    })->middleware(['verified'])->name('batch.dashboard');

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
