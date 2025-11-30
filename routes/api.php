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

Route::middleware('auth:sanctum')->group(function () {
    // Protected routes for API tokens / agent communication will live here
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Agent configuration polling endpoint (protected by sanctum)
    Route::get('/v1/agent/config', [AgentConfigController::class, 'index']);

    // Agent discovery results ingestion endpoint (protected by sanctum)
    Route::post('/v1/discovery/results', [App\Http\Controllers\Api\DiscoveryIngestController::class, 'store']);
});

// Public health/ping endpoint (optional)
Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});
