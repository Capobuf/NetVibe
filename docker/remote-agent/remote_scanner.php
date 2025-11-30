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

echo "[INFO] Modern IPAM Remote Agent starting...\n";

// --- Configuration ---
$apiUrl = getenv('IPAM_AGENT_API_URL');
$apiKey = getenv('IPAM_AGENT_API_KEY');
$pollIntervalSeconds = 300; // 5 minutes

if (!$apiUrl || !$apiKey) {
    error_log("[CRITICAL] IPAM_AGENT_API_URL or IPAM_AGENT_API_KEY not set. Exiting.");
    exit(1);
}

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

    // 3. Remote Push (Try/Catch)
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
pushLog('INFO', 'Modern IPAM Agent Starting...');

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
        
        if ($response['status'] === 401 || $response['status'] === 403) {
            pushLog('CRITICAL', "API Key rejected (401/403). Exiting loop.");
            exit(1);
        }

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $data = json_decode($response['body'], true);
            
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
                foreach ($subnetsToScan as $subnetData) {
                    $cidr = $subnetData['cidr'] ?? 'unknown';
                    echo "   -> Scanning target: $cidr\n";
                    
                    // Esecuzione REALE di fping
                    // -a: alive, -A: address only, -q: quiet, -g: generate (subnet)
                    // -r 1: retry 1 time, -t 500: timeout 500ms
                    $cmd = "fping -aAqg -r 1 -t 500 " . escapeshellarg($cidr);
                    $output = [];
                    $returnVar = 0;
                    
                    exec($cmd, $output, $returnVar);
                    
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
        } else {
            pushLog('ERROR', "Server returned status " . $response['status']);
        }

    } catch (\Throwable $e) {
        pushLog('ERROR', "Runtime Exception: " . $e->getMessage());
    }

    if ($runOnce) {
        pushLog('INFO', "Run once mode enabled. Exiting.");
        break;
    }

    echo "[WAIT] Sleeping for {$pollIntervalSeconds}s...\n";
    sleep($pollIntervalSeconds);
}
