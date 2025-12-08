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
        try {
            // The authenticated token is expected to be tied to a ScanAgent (Sanctum)
            $agent = $request->user();

            if (! $agent instanceof ScanAgent) {
                return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
            }

            // Health check: Update last_seen timestamp
            $agent->update(['last_seen' => now()]);

        // Query subnets with customer inheritance logic
        $query = Subnet::query()
            ->where('discover_subnet', true)
            ->where(function ($q) use ($agent) {
                // Include subnets directly assigned to this agent
                $q->where('scan_agent_id', $agent->id);
                
                // OR include subnets belonging to the agent's customer
                if ($agent->customer_id) {
                    $q->orWhere('customer_id', $agent->customer_id);
                }
            });
        
        $subnets = $query->get();

        // Explicitly map to force accessor evaluation
        $data = $subnets->map(function ($subnet) {
            return [
                'id' => $subnet->id,
                'cidr' => $subnet->cidr, // Forces getCidrAttribute() accessor call
                'vlan_id' => $subnet->vlan_id,
                'description' => $subnet->description,
            ];
        });

            $requestId = $request->header('X-Request-Id');

            return response()->json([
            'subnets' => $data,
            'log_level' => $agent->log_level ?? 'ERROR',
            ])->header('X-Request-Id', $requestId ?: '');
        } catch (\Throwable $e) {
            // Log details to help identify why the endpoint returned 500
            try {
                $authHeader = $request->headers->get('Authorization');
                $tokenMask = null;
                if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                    $plain = substr($authHeader, 7);
                    $tokenMask = str_contains($plain, '|') ? explode('|', $plain, 2)[0] . '|****' : substr($plain, 0, 4) . '...' . substr($plain, -4);
                }

                \Log::error('AgentConfigController::index failed', [
                    'exception' => (string) $e,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'token_mask' => $tokenMask,
                    'request_id' => $request->header('X-Request-Id'),
                ]);
            } catch (\Throwable $_) {
                // best-effort logging
            }

            return response()->json(['message' => 'Server error while preparing config'], 500);
        }
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
