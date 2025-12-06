#!/usr/bin/env php
<?php

declare(strict_types=1);

// File: remote_scanner.php
// Location: docker/remote-agent/
// Logica: Polling -> Scan -> Push -> Log

// Configurazione base per logging su stderr (Docker friendly)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

// --- Globals & Constants ---
$currentLogLevel = 'INFO'; // Default
const LOG_LEVELS = ['INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4];

// Use composer autoload so we can rely on Symfony Process and IPLib
require_once __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;
use IPLib\Factory as IPLibFactory;

echo "[INFO] NetVibe Remote Agent starting...\n";

// --- Configuration ---
$apiUrl = getenv('IPAM_AGENT_API_URL');
$apiKey = getenv('IPAM_AGENT_API_KEY');
$pollIntervalSeconds = 300; // 5 minutes

if (!$apiUrl || !$apiKey) {
    error_log("[CRITICAL] IPAM_AGENT_API_URL or IPAM_AGENT_API_KEY not set. Exiting.");
    exit(1);
}

// Should we push logs to the remote API? We default to true except when explicitly disabled
$rawPush = getenv('IPAM_AGENT_PUSH_LOGS');
$pushLogs = $rawPush === null ? (getenv('APP_ENV') !== 'local') : (filter_var($rawPush, FILTER_VALIDATE_BOOLEAN));

// Helper: HTTP Request (using stream_context for zero-dependency)
function sendRequest(string $method, string $url, array $headers, ?string $body = null): array
{
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true, // Fetch body even on 4xx/5xx
            'timeout' => 30
        ]
    ];
    
    $context = stream_context_create($opts);
    
    // Suppress warnings to handle them manually via return status
    $responseBody = @file_get_contents($url, false, $context);
    
    // Parse response headers for status code
    if (isset($http_response_header)) {
        $statusLine = $http_response_header[0];
        preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
        $statusCode = (int)($match[1] ?? 500);
    } else {
        $statusCode = 0; // Connection failed (DNS, Timeout, etc)
    }

    return ['status' => $statusCode, 'body' => $responseBody];
}

// Helper: Push Log (Robust)
function pushLog(string $level, string $message) {
    global $apiUrl, $apiKey, $currentLogLevel;
    
    // 1. Local Log (Always)
    error_log("[$level] $message");

    // 2. Level Check
    $msgLevelVal = LOG_LEVELS[$level] ?? 1;
    $configLevelVal = LOG_LEVELS[$currentLogLevel] ?? 3; // Default to ERROR if unknown

    if ($msgLevelVal < $configLevelVal) {
        return;
    }

    // 3. Remote Push (Try/Catch) — controlled by env/IPAM_AGENT_PUSH_LOGS
    global $pushLogs;
    if (! $pushLogs) {
        return;
    }
    try {
        $logUrl = rtrim($apiUrl, '/') . '/v1/agent/logs';
        $authHeader = 'Authorization: Bearer ' . $apiKey;
        $headers = [
            $authHeader,
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        $payload = json_encode([
            'level' => $level,
            'message' => $message
        ]);

        $response = sendRequest('POST', $logUrl, $headers, $payload);
        
        if ($response['status'] === 0) {
            // Connection failed, log to stderr but don't crash
            error_log("Failed to push log to server: Connection failed (Status 0)");
        } elseif ($response['status'] >= 400) {
            // Server error, log to stderr
            error_log("Failed to push log to server: HTTP " . $response['status']);
        }
        
    } catch (\Throwable $e) {
        // Catch any unexpected runtime exceptions during push
        error_log("Failed to push log to server: " . $e->getMessage());
    }
}

// --- Startup Log ---
pushLog('INFO', 'NetVibe Agent Starting...');

$shouldStop = false;

// Setup signal handling for graceful shutdown in long-running agent
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGINT, function () use (&$shouldStop) { $shouldStop = true; });
}

// --- CLI Arguments ---
$runOnce = in_array('--once', $argv, true);

// --- Main Agent Loop ---
while (true) {
    pushLog('INFO', "Checking for tasks...");
    
    try {
        // 1. POLLING (PULL)
        $configUrl = rtrim($apiUrl, '/') . '/v1/agent/config';
        $authHeader = 'Authorization: Bearer ' . $apiKey;
        $headers = [
            $authHeader,
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        $response = sendRequest('GET', $configUrl, $headers);
        
        // Allow tests to inject a deterministic config payload (bypass HTTP polling)
        $shouldProcess = false;
        if (getenv('IPAM_AGENT_TEST_SUBNETS')) {
            $data = json_decode(getenv('IPAM_AGENT_TEST_SUBNETS'), true);
            $shouldProcess = true;
        } elseif ($response['status'] === 401 || $response['status'] === 403) {
            pushLog('CRITICAL', "API Key rejected (401/403). Exiting loop.");
            exit(1);
        } elseif ($response['status'] >= 200 && $response['status'] < 300) {
            $data = json_decode($response['body'], true);
            $shouldProcess = true;
        } else {
            pushLog('ERROR', "Server returned status " . $response['status']);
        }

        if ($shouldProcess) {
            // 2. Config Update
            if (isset($data['log_level'])) {
                $currentLogLevel = $data['log_level'];
            }

            $subnetsToScan = $data['subnets'] ?? []; 
            
            if (empty($subnetsToScan)) {
                pushLog('INFO', "No subnets assigned. Idle.");
            } else {
                pushLog('INFO', "Received " . count($subnetsToScan) . " subnets to scan.");
                
                $resultsPayload = [];

                // 3. SCANNING (EXEC STUB)
                // Use ExecutableFinder to locate fping if available
                $finder = new ExecutableFinder();
                // Allow tests to override fping location or explicitly disable it.
                $overrideFping = getenv('IPAM_AGENT_FPING_PATH');
                if ($overrideFping !== false) {
                    // Explicit empty or NONE disables fping
                    if ($overrideFping === '' || strtoupper($overrideFping) === 'NONE') {
                        $fpingPath = null;
                    } else {
                        $fpingPath = $overrideFping;
                    }
                } else {
                    $fpingPath = $finder->find('fping');
                }

                if (! $fpingPath) {
                    pushLog('ERROR', 'fping executable not found on PATH; scanning skipped.');
                }

                foreach ($subnetsToScan as $subnetData) {
                    $cidr = $subnetData['cidr'] ?? 'unknown';

                    // Validate CIDR properly using IPLib — robust for IPv4/IPv6
                    $range = IPLibFactory::parseRangeString($cidr);
                    if (! $range) {
                        pushLog('WARNING', "Skipping invalid CIDR: {$cidr}");
                        continue;
                    }
                    echo "   -> Scanning target: $cidr\n";
                    
                    // Esecuzione REALE di fping (via Symfony Process when available)
                    // -a: alive, -A: address only, -q: quiet, -g: generate (subnet)
                    // -r 1: retry 1 time, -t 500: timeout 500ms
                    $output = [];
                    $returnVar = 0;

                    if (! $fpingPath) {
                        // fping not present: record an error in logs and skip.
                        pushLog('ERROR', "fping not available; cannot scan {$cidr}");
                        continue;
                    }

                    try {
                        // Build process safely using argument array (no shell interpolation)
                        $process = new Process([$fpingPath, '-aAqg', '-r', '1', '-t', '500', $cidr]);
                        $process->setTimeout(60); // guard against long hangs
                        $process->run();

                        if (! $process->isSuccessful()) {
                            // collect stderr and log but continue
                            pushLog('ERROR', sprintf('fping failed for %s: %s', $cidr, $process->getErrorOutput()));
                        }

                        $rawOut = $process->getOutput();
                        $output = $rawOut === null ? [] : preg_split('/\r?\n/', trim($rawOut));
                    } catch (\Throwable $e) {
                        pushLog('ERROR', "Exception running fping for {$cidr}: " . $e->getMessage());
                        continue;
                    }
                    
                    // Parsing Output
                    // fping restituisce un array di IP (uno per riga)
                    foreach ($output as $ip) {
                        // Filtra IP non validi
                        if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;

                        $resultsPayload[] = [
                            'ip_addr' => $ip,
                            'mac_address' => null, // TODO: Implementare ARP lookup se necessario
                            'hostname' => gethostbyaddr($ip) ?: null, // Risoluzione DNS inversa base
                            'state' => 'host'
                        ];
                    }
                    
                    echo "      Found " . count($output) . " active hosts.\n";
                }

                // 4. PUSH RESULTS
                if (!empty($resultsPayload)) {
                    $ingestUrl = rtrim($apiUrl, '/') . '/v1/discovery/results';
                    $jsonBody = json_encode(['results' => $resultsPayload]);
                    
                    $pushResponse = sendRequest('POST', $ingestUrl, $headers, $jsonBody);
                    
                    if ($pushResponse['status'] >= 200 && $pushResponse['status'] < 300) {
                        pushLog('INFO', "Push successful. Status: " . $pushResponse['status']);
                    } else {
                        pushLog('ERROR', "Push failed. Status: " . $pushResponse['status']);
                    }
                }
            }
        }
        else {
            pushLog('ERROR', "Server returned status " . $response['status']);
        }

    } catch (\Throwable $e) {
        pushLog('ERROR', "Runtime Exception: " . $e->getMessage());
    }

    if ($runOnce) {
        pushLog('INFO', "Run once mode enabled. Exiting.");
        break;
    }

    if ($shouldStop) {
        pushLog('INFO', 'SIGTERM received — shutting down agent loop gracefully');
        break;
    }

    echo "[WAIT] Sleeping for {$pollIntervalSeconds}s...\n";
    // Use shorter sleep chunks so signals are noticed promptly
    $sleep = (int)$pollIntervalSeconds;
    $chunk = 5;
    while ($sleep > 0 && ! $shouldStop) {
        $step = min($chunk, $sleep);
        sleep($step);
        $sleep -= $step;
    }
}
