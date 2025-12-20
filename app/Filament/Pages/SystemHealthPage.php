<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ScanAgent;
use App\Settings\SystemStateSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class SystemHealthPage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'System Health';

    protected static ?string $title = 'System Health';

    protected static ?int $navigationSort = 90;

    protected static UnitEnum|string|null $navigationGroup = 'Administration';

    protected string $view = 'filament.pages.system-health';

    public function runHealthCheck(): void
    {
        /** @var SystemStateSettings $state */
        $state = app(SystemStateSettings::class);
        $state->last_health_check_at = now()->toIso8601String();
        $state->save();

        Notification::make()
            ->title('Health check completed')
            ->success()
            ->send();
    }

    public function getHealthData(): array
    {
        /** @var SystemStateSettings $state */
        $state = app(SystemStateSettings::class);

        $scheduler = $this->statusFromTimestamp($state->last_scheduler_run_at);
        $worker = $this->statusFromTimestamp($state->last_worker_heartbeat_at);

        $db = $this->checkDatabase();
        $redis = $this->checkRedis();
        $storage = $this->checkStorage();
        $agents = $this->agentsSummary();

        $lastHealthCheck = $state->last_health_check_at
            ? Carbon::parse($state->last_health_check_at)
            : null;

        return [
            'scheduler' => $scheduler,
            'worker' => $worker,
            'db' => $db,
            'redis' => $redis,
            'storage' => $storage,
            'agents' => $agents,
            'last_health_check_at' => $lastHealthCheck,
        ];
    }

    /**
     * @return array{status:string,label:string,last:?Carbon,message:string}
     */
    private function statusFromTimestamp(?string $isoTimestamp): array
    {
        if (! $isoTimestamp) {
            return [
                'status' => 'UNKNOWN',
                'label' => 'Unknown',
                'last' => null,
                'message' => 'No data recorded yet.',
            ];
        }

        $last = Carbon::parse($isoTimestamp);
        $diffMinutes = now()->diffInMinutes($last);

        if ($diffMinutes < 3) {
            $status = 'OK';
            $label = 'OK';
            $message = 'Last run less than 3 minutes ago.';
        } elseif ($diffMinutes <= 10) {
            $status = 'WARNING';
            $label = 'Warning';
            $message = 'Last run between 3 and 10 minutes ago.';
        } else {
            $status = 'CRITICAL';
            $label = 'Critical';
            $message = 'Last run more than 10 minutes ago.';
        }

        return [
            'status' => $status,
            'label' => $label,
            'last' => $last,
            'message' => $message,
        ];
    }

    /**
     * @return array{status:string,message:string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'status' => 'UP',
                'message' => 'Database connection OK.',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'DOWN',
                'message' => 'Database connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status:string,message:string}
     */
    private function checkRedis(): array
    {
        $redisConfig = config('database.redis.default');

        if (! $redisConfig) {
            return [
                'status' => 'N/A',
                'message' => 'Redis not configured (not required).',
            ];
        }

        try {
            Redis::connection()->ping();

            return [
                'status' => 'UP',
                'message' => 'Redis ping OK.',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'DOWN',
                'message' => 'Redis connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status:string,message:string}
     */
    private function checkStorage(): array
    {
        try {
            $path = 'healthcheck_'.uniqid().'.tmp';
            Storage::disk('local')->put($path, 'ok');
            Storage::disk('local')->delete($path);

            return [
                'status' => 'UP',
                'message' => 'Storage writable.',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'DOWN',
                'message' => 'Storage not writable: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{total:int,with_last_seen:int,last_seen_max:?Carbon}
     */
    private function agentsSummary(): array
    {
        $total = ScanAgent::count();
        $withLastSeen = ScanAgent::whereNotNull('last_seen')->count();
        $lastSeenMax = ScanAgent::whereNotNull('last_seen')->max('last_seen');

        return [
            'total' => $total,
            'with_last_seen' => $withLastSeen,
            'last_seen_max' => $lastSeenMax ? Carbon::parse($lastSeenMax) : null,
        ];
    }
}

