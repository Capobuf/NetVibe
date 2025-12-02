<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SystemHealthCheckService
{
    /**
     * Run the full set of system checks and return an array of result objects.
     *
     * @return array<int,array>
     */
    public function checkAll(): array
    {
        $results = [
            'Extensions' => [],
            'Permissions' => [],
            'Services' => [],
        ];

        // Extensions
        $extensions = ['bcmath', 'intl', 'pdo_mysql', 'redis', 'ctype', 'json'];
        foreach ($extensions as $ext) {
            $ok = extension_loaded($ext);
            $results['Extensions'][] = [
                'id' => "extension:{$ext}",
                'name' => "PHP extension: {$ext}",
                'status' => $ok ? 'ok' : 'error',
                'message' => $ok ? 'available' : 'missing',
            ];
        }

        // Permissions
        $paths = [
            'storage' => storage_path(),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        foreach ($paths as $key => $path) {
            $writable = is_writable($path);
            $results['Permissions'][] = [
                'id' => "permission:{$key}",
                'name' => "Writable: {$path}",
                'status' => $writable ? 'ok' : 'error',
                'message' => $writable ? 'writable' : 'not writable',
            ];
        }

        // Database connectivity
        try {
            DB::connection()->getPdo();
            $results['Services'][] = [
                'id' => 'database:connection',
                'name' => 'Database connection',
                'status' => 'ok',
                'message' => 'connected',
            ];
        } catch (Throwable $e) {
            $results['Services'][] = [
                'id' => 'database:connection',
                'name' => 'Database connection',
                'status' => 'error',
                'message' => 'cannot connect',
                'details' => $e->getMessage(),
            ];
        }

        // Redis connectivity
        try {
            $ping = Redis::connection()->ping();
            // Some drivers return true/1, others return PONG
            $pingString = strtoupper((string) $ping);
            $ok = in_array($pingString, ['PONG', '+PONG', '1'], true) || $ping === true;
            
            $results['Services'][] = [
                'id' => 'redis:connection',
                'name' => 'Redis connection',
                'status' => $ok ? 'ok' : 'error',
                'message' => $ok ? 'reachable' : 'cannot reach',
                'details' => $ok ? 'Connected (PONG/1)' : "Unexpected response: {$ping}",
            ];
        } catch (Throwable $e) {
            $results['Services'][] = [
                'id' => 'redis:connection',
                'name' => 'Redis connection',
                'status' => 'warning',
                'message' => 'not configured or unreachable',
                'details' => $e->getMessage(),
            ];
        }

        // Internet connectivity
        try {
            $response = Http::timeout(2)->get('https://google.com');
            $ok = $response->successful();
            $results['Services'][] = [
                'id' => 'internet:connectivity',
                'name' => 'Internet connectivity',
                'status' => $ok ? 'ok' : 'warning',
                'message' => $ok ? 'reachable' : 'unreachable (warning)',
                'details' => $ok ? null : 'timeout or non-2xx response',
            ];
        } catch (Throwable $e) {
            $results['Services'][] = [
                'id' => 'internet:connectivity',
                'name' => 'Internet connectivity',
                'status' => 'warning',
                'message' => 'unreachable (warning)',
                'details' => $e->getMessage(),
            ];
        }

        return $results;
    }
}
