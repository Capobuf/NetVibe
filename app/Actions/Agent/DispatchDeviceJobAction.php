<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\AgentJob;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

class DispatchDeviceJobAction
{
    public function execute(Device $device, ?string $type = 'check_connectivity', ?int $scanAgentId = null, ?int $initiatedByUserId = null): AgentJob
    {
        // Resolve agent: prefer explicit, then device->scan_agent_id, else first active agent for same customer, else global
        $resolvedAgentId = $scanAgentId;
        if (! $resolvedAgentId && $device->scan_agent_id) {
            $resolvedAgentId = $device->scan_agent_id;
        }
        if (! $resolvedAgentId && $device->customer_id) {
            $resolvedAgentId = \App\Models\ScanAgent::query()
                ->where('active', true)
                ->where('customer_id', $device->customer_id)
                ->orderByDesc('last_seen')
                ->value('id');
        }
        if (! $resolvedAgentId) {
            $resolvedAgentId = \App\Models\ScanAgent::query()
                ->where('active', true)
                ->whereNull('customer_id')
                ->orderByDesc('last_seen')
                ->value('id');
        }

        // Resolve credentials: prefer credential profile, fallback to device-local
        $credentials = [];
        if (! empty($device->credential_profile_id) && $device->credentialProfile) {
            $profile = $device->credentialProfile;
            // auth_password and auth_credentials are encrypted casts and will be decrypted automatically
            $credentials = $profile->auth_credentials ?: [
                'username' => $profile->username ?? null,
                'password' => $profile->auth_password ?? null,
            ];
        } else {
            $credentials = [
                'username' => $device->auth_username ?? null,
                'password' => $device->auth_password ?? null,
                'extra' => $device->auth_extra ?? null,
            ];
        }

        // Respect discovery settings for timeouts if available
        $settings = app(\App\Settings\DiscoverySettings::class);
        // Connectivity can use ICMP timeout; deep discovery needs more time for REST pulls
        $timeoutMs = ($type === 'discovery_deep')
            ? max(8000, $settings->icmp_timeout_ms ?? 200)
            : ($settings->icmp_timeout_ms ?? 200);

        $job = AgentJob::create([
            'device_id' => $device->id,
            'scan_agent_id' => $resolvedAgentId,
            'type' => $type,
            'status' => 'pending',
            'payload' => [
                'device_id' => $device->id,
                'hostname' => $device->hostname,
                'target_ip' => $device->ip_addr ?? null,
                'username' => $credentials['username'] ?? null,
                'password' => $credentials['password'] ?? null,
                'extra' => $credentials['extra'] ?? null,
                'timeout_ms' => $timeoutMs,
            ],
            'initiated_by_user_id' => $initiatedByUserId,
        ]);

        Log::info('DispatchDeviceJobAction: job created', ['job_id' => $job->id, 'device_id' => $device->id]);

        return $job;
    }
}
