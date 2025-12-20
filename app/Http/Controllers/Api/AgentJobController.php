<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AgentJob;
use Illuminate\Support\Facades\Log;
use App\Settings\DiscoverySettings;
use App\Settings\SecuritySettings;

class AgentJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\ScanAgent $agent */
        $agent = $request->user();

        if (! $agent) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        try {
            $requestId = $request->header('X-Request-Id');
            // Only return pending jobs assigned to this agent
            $jobs = AgentJob::with('device.credentialProfile')
            ->where('scan_agent_id', $agent->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        // Enhance returned payload with any credentials required by the agent
        $payload = $jobs->map(function (AgentJob $job) use ($agent) {
            try {
                $data = $job->toArray();
            } catch (\Throwable $e) {
                // Log the failing job and continue: a malformed payload or broken relation
                // could otherwise bubble up and turn into a 500.
                Log::error('AgentJobController index: failed to toArray() job=' . ($job->id ?? '(no-id)') . ' error=' . $e->getMessage());
                return null; // filter out later
            }

            // Merge credentials if missing in payload
            $p = $job->payload ?? [];

            if (empty($p['credentials'])) {
                $credentials = [];

                // Prefer device credential_profile if set
                if ($job->device && ! empty($job->device->credential_profile_id) && $job->device->credentialProfile) {
                    $profile = $job->device->credentialProfile;
                    $credentials = $profile->auth_credentials ?: [
                        'username' => $profile->username ?? null,
                        'password' => $profile->auth_password ?? null,
                    ];
                } else {
                    // Fallback to device-local auth fields
                    if ($job->device) {
                        $credentials = [
                            'username' => $job->device->auth_username ?? null,
                            'password' => $job->device->auth_password ?? null,
                            'extra' => $job->device->auth_extra ?? null,
                        ];
                    }
                }

                if (! empty($credentials)) {
                    $p['credentials'] = $credentials;
                }
            }

            $data['payload'] = $p;

            return $data;
        });

        // Filter out any null mapping results caused by errors above
        $payload = $payload->filter(fn ($i) => ! is_null($i))->values();

        // Include optional agent settings so agent can configure retry/timeouts
        $discoverySettings = app(DiscoverySettings::class);

            // Filter out any null mapping results caused by errors above
            $payload = $payload->filter(fn ($i) => ! is_null($i))->values();

            // Include optional agent settings so agent can configure retry/timeouts
            $discoverySettings = app(DiscoverySettings::class);

            return response()->json([
            'agent_config' => $agent->settings ?? [],
            'discovery_config' => [
                'auto_discovery_enabled' => $discoverySettings->auto_discovery_enabled,
                'default_scan_interval_minutes' => $discoverySettings->default_scan_interval_minutes,
                'agent_polling_interval_minutes' => $discoverySettings->agent_polling_interval_minutes ?? 5,
                'icmp_timeout_ms' => $discoverySettings->icmp_timeout_ms,
                'icmp_retries' => $discoverySettings->icmp_retries,
                'prune_logs_days' => $discoverySettings->prune_logs_days,
            ],
                'jobs' => $payload,
            ])->header('X-Request-Id', $requestId ?: '');
        } catch (\Throwable $e) {
            // Log full diagnostics to help trace the source of the 500 when agents poll
            try {
                $authHeader = $request->headers->get('Authorization');
                $tokenMask = null;
                if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                    $plain = substr($authHeader, 7);
                    $tokenMask = str_contains($plain, '|') ? explode('|', $plain, 2)[0] . '|****' : substr($plain, 0, 4) . '...' . substr($plain, -4);
                }

                \Log::error('AgentJobController::index failed', [
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

            return response()->json(['message' => 'Server error while preparing jobs'], 500);
        }
    }

    public function storeResult(Request $request, AgentJob $job): JsonResponse
    {
        /** @var \App\Models\ScanAgent $agent */
        $agent = $request->user();

        if (! $agent) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Security: ensure the job is assigned to this agent
        if ($job->scan_agent_id !== $agent->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Backwards compatible: agent may still send 'success' — map both to 'completed'
        $status = $request->input('status', 'completed');
        $output = $request->input('output');
        $result = $request->input('result');

        // Schema validation: ensure device_result.schema_version is compatible with server settings.
        // Fase 1: se lo schema ha major version inferiore a min_supported_schema_version, rifiutiamo con 426.
        if (is_array($result) && isset($result['device_result']) && is_array($result['device_result'])) {
            $deviceResult = $result['device_result'];
            $schemaVersion = $deviceResult['schema_version'] ?? null;

            if (! is_string($schemaVersion) || $schemaVersion === '') {
                return response()->json([
                    'error' => 'INVALID_RESPONSE_FORMAT',
                    'message' => 'Missing or invalid device_result.schema_version',
                ], 422);
            }

            /** @var SecuritySettings $security */
            $security = app(SecuritySettings::class);
            $minSupported = $security->min_supported_schema_version;

            $major = (int) explode('.', $schemaVersion)[0];
            $minMajor = (int) explode('.', $minSupported)[0];

            if ($major < $minMajor) {
                return response()->json([
                    'error' => 'API_VERSION_TOO_LOW',
                    'message' => 'device_result.schema_version ' . $schemaVersion . ' is lower than min_supported_schema_version ' . $minSupported,
                    'min_supported_schema_version' => $minSupported,
                ], 426);
            }
        }

        if ($status === 'failed') {
            $job->status = 'failed';
        } elseif ($status === 'timeout') {
            $job->status = 'timeout';
        } elseif ($status === 'success') {
            // backward compatibility
            $job->status = 'completed';
        } else {
            $job->status = ($status === 'completed') ? 'completed' : $status;
        }
        $job->output = (string) ($output ?? '');
        $job->result = $result ?? null;
        $job->finished_at = now();
        $job->attempts = ($job->attempts ?? 0) + 1;
        $job->save();

        // Call processing action (placeholder) — keeps business logic on server
        try {
            app(\App\Actions\Agent\ProcessAgentJobResultAction::class)->execute($job);
        } catch (\Throwable $e) {
            Log::error('ProcessAgentJobResultAction failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
