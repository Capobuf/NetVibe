<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScanAgent;
use App\Settings\DiscoverySettings;
use App\Settings\FeatureFlagSettings;
use App\Settings\SecuritySettings;
use Illuminate\Http\Request;

class AgentConfigController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $agent = $request->user();

        if (! $agent instanceof ScanAgent) {
            return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
        }

        // Health check: update last_seen timestamp
        $agent->forceFill(['last_seen' => now()])->save();

        $discovery = app(DiscoverySettings::class);
        $security = app(SecuritySettings::class);
        $features = app(FeatureFlagSettings::class);

        $agentSettings = $agent->settings ?? [];

        $config = [
            'agent_id' => (string) $agent->getKey(),
            'min_agent_version' => $security->min_agent_version,
            'min_supported_schema_version' => $security->min_supported_schema_version,

            'log_level' => $agent->log_level
                ?? ($agentSettings['log_level'] ?? 'info'),

            'timeout_rest_ms' => $agentSettings['timeout_rest_ms']
                ?? $discovery->timeout_rest_ms,
            'timeout_snmp_ms' => $agentSettings['timeout_snmp_ms']
                ?? $discovery->timeout_snmp_ms,
            'tls_verify' => $agentSettings['tls_verify']
                ?? $security->agent_tls_verify,

            'enable_rest' => $agentSettings['enable_rest'] ?? true,
            'enable_snmp' => $agentSettings['enable_snmp'] ?? true,

            'max_records_per_dataset' => $agentSettings['max_records_per_dataset']
                ?? $discovery->max_records_per_dataset,

            'features' => [
                'snmp_fase1' => $features->snmp_fase1,
                'chunking' => $features->chunking,
                'gzip' => $features->gzip,
            ],
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';

        if ($request->header('If-None-Match') === $etag) {
            return response()
                ->noContent(304)
                ->setEtag($etag);
        }

        $requestId = $request->header('X-Request-Id') ?: '';

        return response()
            ->json($config)
            ->setEtag($etag)
            ->header('X-Request-Id', $requestId);
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
