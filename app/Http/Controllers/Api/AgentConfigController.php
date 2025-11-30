<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subnet;
use App\Models\ScanAgent;

class AgentConfigController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // The authenticated token is expected to be tied to a ScanAgent (Sanctum)
        $agent = $request->user();

        if (! $agent instanceof ScanAgent) {
            return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
        }

        $subnets = Subnet::query()
            ->where('scan_agent_id', $agent->id)
            ->get(['id', 'subnet', 'cidr', 'vlan_id']);

        return response()->json(['subnets' => $subnets]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
