<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Models\AgentJob;
use Illuminate\Support\Facades\Log;
use App\Actions\Agent\IngestMikrotikInventoryAction;
use App\Actions\Agent\IngestMikrotikTopologyAction;
use Illuminate\Support\Facades\Notification;
use App\Notifications\AgentJobCompletedNotification;

class ProcessAgentJobResultAction
{
    public function execute(AgentJob $job): void
    {
        Log::info('ProcessAgentJobResultAction: job processed', ['job_id' => $job->id, 'type' => $job->type, 'status' => $job->status]);

        $device = $job->device;

        switch ($job->type) {
            case 'check_connectivity':
                // Update device auth status and last seen (router wins semantics)
                if ($device) {
                    if ($job->status === 'completed') {
                        $device->last_seen_at = now();
                        $device->auth_status = 'valid';
                    } else {
                        // Failed: interpret known error codes
                        $err = $job->result['error'] ?? $job->result['error_code'] ?? null;
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
                    app(IngestMikrotikInventoryAction::class)->execute($device, $job->result ?? []);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikInventoryAction failed: ' . $e->getMessage());
                }

                break;

            case 'discovery_fast':
                try {
                    app(IngestMikrotikArpAction::class)->execute($device, $job->result ?? []);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikArpAction failed: ' . $e->getMessage());
                }

                break;

            case 'discovery_deep':
                // Deep discovery: ingest topology first so ports/VLANs exist before ARP/IP enrichment
                try {
                    app(IngestMikrotikTopologyAction::class)->execute($device, $job->result ?? []);
                } catch (\Throwable $e) {
                    Log::warning('IngestMikrotikTopologyAction failed: ' . $e->getMessage(), [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }

                try {
                    app(IngestMikrotikArpAction::class)->execute($device, $job->result ?? []);
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
}
