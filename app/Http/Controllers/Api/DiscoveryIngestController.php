<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\Api\DiscoveryResultsRequest;
use App\Jobs\ProcessDiscoveryIngestJob;
use App\Models\DiscoveryEvent;
use App\Models\ScanAgent;

class DiscoveryIngestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(DiscoveryResultsRequest $request)
    {
        $agent = $request->user();

        if (! $agent instanceof ScanAgent) {
            return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
        }

        // Health check: Update last_seen timestamp
        $agent->update(['last_seen' => now()]);

        $results = $request->validated()['results'];

        // Dispatch the async job to process the ingest. Job will handle persisting/staging and diffing.
        ProcessDiscoveryIngestJob::dispatch($results, $agent->getKey());

        return response()->json(['message' => 'Ingest accepted, queued for processing', 'count' => count($results)], 202);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
