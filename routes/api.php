<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ClaimController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Claim Processing API Endpoints
Route::prefix('claims')->group(function () {
    Route::get('/insurers', [ClaimController::class, 'getInsurers']);
    Route::post('/submit', [ClaimController::class, 'submitClaim']);
    Route::get('/list', [ClaimController::class, 'getClaims']);
    Route::post('/process-batches', [ClaimController::class, 'processBatches']);
    Route::get('/batch-summary', [ClaimController::class, 'getBatchSummary']);
});
