<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AgentLogRequest;
use App\Models\AgentLog;
use App\Models\ScanAgent;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Filament\Actions\Action;

class AgentLogController extends Controller
{
    private const SEVERITY_LEVELS = [
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4,
    ];

    public function store(AgentLogRequest $request)
    {
        $agent = $request->user();

        if (! $agent instanceof ScanAgent) {
            return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
        }

        // Health check: Update last_seen timestamp
        $agent->update(['last_seen' => now()]);

        $validated = $request->validated();

        // Save log to database
        $log = AgentLog::create([
            'scan_agent_id' => $agent->id,
            'level' => $validated['level'],
            'message' => $validated['message'],
            'context' => $validated['context'] ?? null,
        ]);

        // Alert trigger logic
        $this->triggerAlertIfNeeded($agent, $validated['level'], $validated['message']);

        return response()->json([
            'message' => 'Log received',
            'log_id' => $log->id,
        ], 201);
    }

    private function triggerAlertIfNeeded(ScanAgent $agent, string $logLevel, string $message): void
    {
        $settings = app(GeneralSettings::class);
        $threshold = $settings->agent_alert_level;

        // If alerts are disabled, return early
        if ($threshold === 'off') {
            return;
        }

        // Compare severity levels
        $logSeverity = self::SEVERITY_LEVELS[$logLevel] ?? 0;
        $thresholdSeverity = self::SEVERITY_LEVELS[$threshold] ?? 999;

        // Trigger alert if log severity >= threshold
        if ($logSeverity >= $thresholdSeverity) {
            Notification::make()
                ->title("Critical Agent Alert: {$agent->name}")
                ->body($message)
                ->danger()
                ->actions([
                    Action::make('view')
                        ->label('View Agent')
                        ->url(route('filament.admin.discovery.resources.scan-agents.edit', ['record' => $agent->id]))
                        ->button(),
                ])
                ->sendToDatabase(User::all());
        }
    }
}
