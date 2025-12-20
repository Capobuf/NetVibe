<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\AgentJob;
use Illuminate\Support\Facades\Log;
use App\Actions\Agent\IngestMikrotikInventoryAction;
use App\Actions\Agent\IngestMikrotikTopologyAction;
use App\Actions\Agent\IngestMikrotikArpAction;
use Illuminate\Support\Facades\Notification;
use App\Notifications\AgentJobCompletedNotification;

class ProcessAgentJobResultAction
{
    public function execute(AgentJob $job): void
    {
        Log::info('ProcessAgentJobResultAction: job processed', ['job_id' => $job->id, 'type' => $job->type, 'status' => $job->status]);

        $device = $job->device;
        $result = $job->result ?? [];

        switch ($job->type) {
            case 'check_connectivity':
                // Update device auth status and last seen (REST channel)
                if ($device) {
                    if ($job->status === 'completed') {
                        $now = now();
                        // Manteniamo last_seen_at per compatibilità, ma usiamo last_seen_rest
                        $device->last_seen_at = $now;
                        $device->last_seen_rest = $now;
                        $device->auth_status = 'valid';
                        $device->markSeen($now);
                    } else {
                        // Failed: interpret known error codes
                        $err = $result['error'] ?? $result['error_code'] ?? null;
                        if ($err === 'AUTH_ERROR') {
                            $device->auth_status = 'invalid';
                        } elseif ($err === 'NET_TIMEOUT') {
                            $device->auth_status = 'unreachable';
                        } else {
                            $device->auth_status = 'invalid';
                        }
                    }

                    $device->save();
                }

                // Try to ingest inventory details returned by Mikrotik
                try {
                    app(IngestMikrotikInventoryAction::class)->execute($device, $result);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikInventoryAction failed: ' . $e->getMessage());
                }

                break;

            case 'discovery_fast':
                // Successful discovery jobs imply REST reachability
                if ($device && $job->status === 'completed') {
                    $now = now();
                    $device->last_seen_at = $now;
                    $device->last_seen_rest = $now;
                    $device->markSeen($now);
                    $device->save();
                }

                try {
                    $this->ingestArpDhcp($device, $result);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikArpAction failed: ' . $e->getMessage());
                }

                break;

            case 'discovery_deep':
                // Successful discovery jobs imply REST reachability
                if ($device && $job->status === 'completed') {
                    $now = now();
                    $device->last_seen_at = $now;
                    $device->last_seen_rest = $now;
                    $device->markSeen($now);
                    $device->save();
                }

                // Deep discovery: ingest topology first so ports/VLANs exist before ARP/IP enrichment
                try {
                    $this->ingestTopology($device, $result);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikTopologyAction failed: ' . $e->getMessage(), [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }

                try {
                    $this->ingestArpDhcp($device, $result);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikArpAction (deep) failed: ' . $e->getMessage());
                }
                break;

            default:
                Log::warning('Unknown agent job type: ' . $job->type);
        }

        // Notify the initiating user if present
        if (! empty($job->initiated_by_user_id)) {
            try {
                $user = \App\Models\User::find($job->initiated_by_user_id);
                if ($user) {
                    Notification::send($user, new AgentJobCompletedNotification($job));
                }
            } catch (\Throwable $e) {
                Log::warning('Notification sending failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Ingest ARP/DHCP part of a result, preferendo il device_result
     * come sorgente canonica e usando il payload legacy solo come fallback
     * per job non provenienti dall'agent.
     */
    private function ingestArpDhcp(?\App\Models\Device $device, array $result): void
    {
        if (! $device) {
            return;
        }

        $deviceResult = $result['device_result'] ?? null;
        if (is_array($deviceResult)) {
            // Passiamo direttamente il device_result all'azione di ingest
            app(IngestMikrotikArpAction::class)->execute($device, $deviceResult);
            return;
        }

        // Fallback legacy per test/unit o agent vecchi
        app(IngestMikrotikArpAction::class)->execute($device, $result ?? []);
    }

    /**
     * Ingest della topologia (interfaces, neighbors, bridge_hosts) a partire
     * da device_result, con fallback legacy per i test esistenti.
     */
    private function ingestTopology(?\App\Models\Device $device, array $result): void
    {
        if (! $device) {
            return;
        }

        $deviceResult = $result['device_result'] ?? null;
        if (is_array($deviceResult)) {
            // Passiamo direttamente il device_result all'azione di ingest
            app(IngestMikrotikTopologyAction::class)->execute($device, $deviceResult);
            return;
        }

        // Fallback legacy
        app(IngestMikrotikTopologyAction::class)->execute($device, $result ?? []);
    }
}
