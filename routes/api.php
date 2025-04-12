<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ClaimController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Public API Endpoints
Route::prefix('claims')->group(function () {
    Route::get('/insurers', [ClaimController::class, 'getInsurers']);
});

// Protected API Endpoints
Route::prefix('claims')->middleware('auth:sanctum')->group(function () {
    Route::post('/submit', [ClaimController::class, 'submitClaim']);
    Route::get('/list', [ClaimController::class, 'getClaims']);
    Route::post('/process-batches', [ClaimController::class, 'processBatches']);
    Route::get('/batch-summary', [ClaimController::class, 'getBatchSummary']);
    Route::post('/trigger-daily-batch', [ClaimController::class, 'triggerDailyBatch']);
});
