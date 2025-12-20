<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you may register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use App\Http\Controllers\Api\AgentConfigController;

// Protected routes for API tokens (users) and agent communication
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

// Agent-only routes: run with an explicit tenant context derived from the authenticated ScanAgent
Route::middleware([
    'auth:sanctum',
    \App\Http\Middleware\SetTenantContextFromScanAgent::class,
])->group(function () {
    // Agent configuration polling endpoint
    Route::get('/v1/agent/config', [AgentConfigController::class, 'index']);

    // Agent discovery results ingestion endpoint
    Route::post('/v1/discovery/results', [App\Http\Controllers\Api\DiscoveryIngestController::class, 'store']);

    // Agent logs ingestion endpoint
    Route::post('/v1/agent/logs', [App\Http\Controllers\Api\AgentLogController::class, 'store']);

    // Agent jobs endpoints (polling and result submission)
    Route::get('/v1/agent/jobs', [App\Http\Controllers\Api\AgentJobController::class, 'index']);
    Route::post('/v1/agent/jobs/{job}/result', [App\Http\Controllers\Api\AgentJobController::class, 'storeResult']);
});

// Public health/ping endpoint (optional)
Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});
