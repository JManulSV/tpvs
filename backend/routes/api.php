<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\RecordFromImageController;
use App\Http\Controllers\Api\RecordImageController;
use App\Http\Controllers\Api\RetryAiProcessingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'status' => 'ok',
    'service' => 'backend',
]));

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::post('/companies', [CompanyController::class, 'store']);

    Route::get('/records', [RecordController::class, 'index']);
    Route::post('/records', [RecordController::class, 'store']);
    Route::post('/records/from-image', [RecordFromImageController::class, 'store'])->middleware('editor');
    Route::post('/records/{record}/ai-processing/retry', [RetryAiProcessingController::class, 'store'])->middleware('editor');
    Route::get('/records/{record}', [RecordController::class, 'show']);
    Route::put('/records/{record}', [RecordController::class, 'update']);
    Route::post('/records/{record}/confirm', [RecordController::class, 'confirm']);
    Route::post('/records/{record}/cancel', [RecordController::class, 'cancel']);
    Route::post('/records/{record}/image', [RecordImageController::class, 'store']);
    Route::get('/records/{record}/image', [RecordImageController::class, 'show']);
});
