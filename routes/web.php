<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Api\ClaimController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Redirect the root URL to the login page for unauthenticated users
// or to the submit claim page for authenticated users
Route::get('/', function () {
    return redirect()->route('submit-claim');
});

// Group routes that require authentication
Route::middleware(['auth'])->group(function () {
    // Submit Claim Page (now protected)
    Route::get('/submit-claim', function () {
        return Inertia::render('SubmitClaim');
    })->name('submit-claim');

    // Add a web route for claim submission that works with Inertia forms
    Route::post('/claims/submit', [ClaimController::class, 'submitClaim'])->name('claims.submit');

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

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
