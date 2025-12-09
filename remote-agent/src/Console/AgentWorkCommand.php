<?php

declare(strict_types=1);

namespace RemoteAgent\Console;

use RemoteAgent\Http\NetVibeHttpClient;
use RemoteAgent\Drivers\MikrotikApiDriver;
use RemoteAgent\Logging\Logger;
use GuzzleHttp\Exception\RequestException;
use RemoteAgent\Handlers\JobHandler;
use RemoteAgent\Support\DeviceResultBuilder;

class AgentWorkCommand
{
    private string $serverUrl;
    private string $token;
    private Logger $logger;
    private NetVibeHttpClient $netVibeClient;
    private MikrotikApiDriver $mikrotikDriver;
    private JobHandler $handler;
    private string $remoteLogLevel;
    private DeviceResultBuilder $deviceResultBuilder;
    private ?string $minSupportedSchemaVersion = null;
    private bool $schemaCompatible = true;

    public function __construct()
    {
        // Initialize logger first (read LOG_LEVEL from environment or .env file)
        $logLevel = $this->readLogLevelFromEnv();
        $this->remoteLogLevel = strtoupper($logLevel);
        $this->logger = new Logger($logLevel);

        $this->logger->info('Initializing Remote Agent...');

        // Accept several environment variable names for compatibility:
        // Prefer SERVER_URL, then AGENT_SERVER_URL, then IPAM_AGENT_API_URL (strip /api/v1 if present)
        $envCandidates = [
            getenv('SERVER_URL') ?: false,
            getenv('AGENT_SERVER_URL') ?: false,
            getenv('IPAM_AGENT_API_URL') ?: false,
        ];

        $candidate = false;
        foreach ($envCandidates as $c) {
            if ($c) {
                $candidate = $c;
                break;
            }
        }

        if ($candidate) {
            // Normalize candidate: remove trailing slash and strip known API path suffixes
            $candidate = rtrim($candidate, '/');
            // If the value contains a REST API base path such as /api or /api/v1
            // strip it so the client can safely append endpoints.
            $candidate = preg_replace('#/api(/v\d+)?$#i', '', $candidate);
        }

        $this->serverUrl = $candidate ?: (getenv('SERVER_URL') ?: 'http://host.docker.internal:9693');
        $this->logger->info('Using server URL: ' . $this->serverUrl);

        // Accept several token env var names for compatibility with older
        // deployments / examples (AGENT_API_TOKEN, IPAM_AGENT_API_KEY, etc.)
        $tokenCandidates = [
            getenv('AGENT_API_TOKEN') ?: false,
            getenv('IPAM_AGENT_API_KEY') ?: false,
            getenv('IPAM_AGENT_API_TOKEN') ?: false,
            getenv('AGENT_API_KEY') ?: false,
        ];

        $tokenCandidate = '';
        foreach ($tokenCandidates as $tc) {
            if ($tc) {
                $tokenCandidate = $tc;
                break;
            }
        }

        // Fallback: if no env var was set in the container, accept a
        // .env file placed into /app (the project root inside the image). This
        // helps when docker-compose uses env_file and variables are present in
        // the file but not exported to the container environment for some
        // reason. We try to read common token names from that file as a last
        // resort.
        if (empty($tokenCandidate)) {
            $envFile = __DIR__ . '/../../.env';
            if (is_readable($envFile)) {
                $contents = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($contents as $line) {
                    // Skip comments
                    if (str_starts_with(trim($line), '#')) {
                        continue;
                    }
                    if (!str_contains($line, '=')) {
                        continue;
                    }

                    [$k, $v] = explode('=', $line, 2);
                    $key = trim($k);
                    $val = trim($v);

                    if (in_array($key, ['AGENT_API_TOKEN', 'IPAM_AGENT_API_KEY', 'IPAM_AGENT_API_TOKEN', 'AGENT_API_KEY']) && !empty($val)) {
                        $tokenCandidate = $val;
                        break;
                    }
                }
            }
        }

        $this->token = $tokenCandidate ?: '';

        // Initialize clients:
        // 1. NetVibeHttpClient: For server communication (polling, results, discovery)
        // 2. MikrotikApiDriver: For device-specific REST calls
        $this->netVibeClient = new NetVibeHttpClient($this->serverUrl, $this->token);
        $this->mikrotikDriver = new MikrotikApiDriver();
        $this->deviceResultBuilder = new DeviceResultBuilder();

        // Attach remote sink so logs are shipped back to control plane when enabled
        $this->logger->setRemoteSink(function (string $level, array $record): void {
            try {
                $this->netVibeClient->postAgentLogs([
                    'level' => strtoupper($level),
                    'message' => $record['message'] ?? '',
                    'context' => $record,
                ]);
            } catch (\Throwable $e) {
                // Swallow errors to avoid recursion / noisy output
            }
        }, $this->remoteLogLevel);

        // Log whether a token is present but don't print the full token
        if (!empty($this->token)) {
            $masked = substr($this->token, 0, 4) . '...' . substr($this->token, -4);
            $this->logger->info('Using API token: ' . $masked);
        } else {
            $this->logger->warning('No API token found (AGENT_API_TOKEN or IPAM_AGENT_API_KEY) — requests will be unauthenticated');
        }

        $this->handler = new JobHandler($this->mikrotikDriver, $this->netVibeClient, $this->logger, $this->deviceResultBuilder);
        $this->logger->info('Initialization complete, ready to process jobs');
    }

    /**
     * Read LOG_LEVEL from environment variables or .env file
     *
     * @return string Log level (debug, info, warning, error, critical)
     */
    private function readLogLevelFromEnv(): string
    {
        // First try environment variable
        $level = getenv('LOG_LEVEL');
        if ($level) {
            return strtolower($level);
        }

        // Fallback to .env file
        $envFile = __DIR__ . '/../../.env';
        if (is_readable($envFile)) {
            $contents = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($contents as $line) {
                // Skip comments
                if (str_starts_with(trim($line), '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }

                [$k, $v] = explode('=', $line, 2);
                $key = trim($k);
                $val = trim($v);

                if ($key === 'LOG_LEVEL' && !empty($val)) {
                    return strtolower($val);
                }
            }
        }

        // Default to 'info'
        return 'info';
    }

    /**
     * Run main loop. This is a simple, synchronous polling loop.
     */
    public function run(): void
    {
        $this->logger->info('Agent main loop starting...');

        $lastConfigCheckAt = 0;

        while (true) {
            // Heartbeat/Config: low-frequency polling (every 60s)
            $now = time();
            if (($now - $lastConfigCheckAt) > 60) {
                try {
                    // generate a per-request id to help correlate agent <-> server logs
                    $reqId = bin2hex(random_bytes(8));
                    $cfg = $this->netVibeClient->getAgentConfig(['X-Request-Id' => $reqId]);
                    $this->logger->debug('getAgentConfig X-Request-Id=' . $reqId);
                    $this->applyRuntimeConfig($cfg);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to fetch agent config: ' . $e->getMessage());
                }
                $lastConfigCheckAt = $now;
            }

            // Se lo schema locale non è compatibile con il min_supported_schema_version del server,
            // l'agent continua a fare solo heartbeat/logs, ma non esegue discovery jobs.
            if (! $this->schemaCompatible) {
                $this->logger->warning('Schema_version not compatible with server min_supported_schema_version; skipping job polling');
                sleep(30);
                continue;
            }

            // Jobs: high-frequency polling (every 5s)
            try {
                $reqId = bin2hex(random_bytes(8));
                $jobsResp = $this->netVibeClient->pollJobs(['X-Request-Id' => $reqId]);
                $this->logger->debug('pollJobs X-Request-Id=' . $reqId);

                $jobs = $jobsResp['jobs'] ?? [];
                if (!empty($jobs)) {
                    $this->logger->info('Received ' . count($jobs) . ' job(s)');
                }
                foreach ($jobs as $job) {
                    $this->logger->info('Processing job: ' . ($job['id'] ?? '(no-id)') . ' type=' . ($job['type'] ?? 'unknown'));
                    $this->processJob($job);
                }
            } catch (\Throwable $e) {
                // If this is an HTTP error with a 5xx response, back off before re-polling
                $backoffSec = 5; // default short backoff
                if ($e instanceof RequestException && $e->hasResponse()) {
                    $code = $e->getResponse()->getStatusCode();
                    if ($code >= 500 && $code < 600) {
                        $backoffSec = 30; // server error: wait longer
                        $this->logger->warning('Server 5xx detected (' . $code . '), backing off for ' . $backoffSec . 's');
                    } elseif ($code === 401 || $code === 403) {
                        $this->logger->error('Authentication error (' . $code . '), check your API token');
                    }
                }

                $this->logger->error('Jobs polling failed: ' . $e->getMessage());
                // Sleep to avoid tight-loop hammering the server on repeated errors
                sleep($backoffSec);
                continue;
            }

            sleep(5);
        }
    }

    /**
     * Process a single job
     */
    protected function processJob(array $job): void
    {
        $type = $job['type'] ?? 'unknown';
        $payload = $job['payload'] ?? [];
        $jobId = $job['id'] ?? '(no-id)';

        $result = ['ok' => false];

        try {
            switch ($type) {
                case 'check_connectivity':
                    $this->logger->debug('Job ' . $jobId . ': Executing check_connectivity');
                    $result = $this->handler->handleCheckConnectivity($payload);
                    break;
                case 'full_discovery':
                case 'discovery_deep':
                    $this->logger->debug('Job ' . $jobId . ': Executing discovery_deep');
                    $result = $this->handler->handleDiscoveryDeep($payload);
                    break;
                default:
                    $this->logger->warning('Job ' . $jobId . ': Unknown job type: ' . $type);
                    $result = ['error' => 'unknown_type'];
            }

            // Send result back to server
            $this->netVibeClient->postJobResult($jobId, [
                'status' => $result['ok'] ? 'completed' : 'failed',
                'output' => json_encode($result),
                'result' => $result,
            ]);

            if ($result['ok']) {
                $this->logger->info('Job ' . $jobId . ': Completed successfully');
            } else {
                $this->logger->warning('Job ' . $jobId . ': Completed with errors');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Job ' . $jobId . ': Processing failed - ' . $e->getMessage());
            try {
                $this->netVibeClient->postJobResult($jobId, [
                    'status' => 'failed',
                    'output' => $e->getMessage(),
                    'result' => ['exception' => $e->getMessage()],
                ]);
            } catch (\Throwable $_) {
                $this->logger->critical('Job ' . $jobId . ': Failed to send error result to server - ' . $_->getMessage());
            }
        }
    }

    /**
     * Apply runtime configuration from server
     */
    protected function applyRuntimeConfig(array $cfg = null): void
    {
        if (! $cfg) {
            return;
        }

        // Example: respect agent_config.log_level from server if provided
        if (! empty($cfg['log_level'])) {
            $this->logger->debug('Server provided log_level: ' . $cfg['log_level']);
            $this->logger->setLevel($cfg['log_level']);
            $this->logger->setRemoteThreshold($cfg['log_level']);
        }

        if (! empty($cfg['max_records_per_dataset']) && $this->handler) {
            $max = (int) $cfg['max_records_per_dataset'];
            $this->logger->debug('Server provided max_records_per_dataset=' . $max);
            $this->handler->setMaxRecordsPerDataset($max);
        }

        // Version negotiation: confronta schema_version locale con min_supported_schema_version del server.
        if (! empty($cfg['min_supported_schema_version'])) {
            $this->minSupportedSchemaVersion = (string) $cfg['min_supported_schema_version'];

            // DeviceResultBuilder Fase 1 usa schema_version fisso 1.0.0
            $localSchemaVersion = '1.0.0';
            $localMajor = (int) explode('.', $localSchemaVersion)[0];
            $minMajor = (int) explode('.', $this->minSupportedSchemaVersion)[0];

            if ($localMajor < $minMajor) {
                $this->schemaCompatible = false;
                $this->logger->error(
                    'Local device_result.schema_version ' . $localSchemaVersion .
                    ' is lower than server min_supported_schema_version ' . $this->minSupportedSchemaVersion .
                    ' — discovery jobs will be skipped until upgraded'
                );
            } else {
                if (! $this->schemaCompatible) {
                    $this->logger->info('Schema_version is now compatible with server; re-enabling discovery jobs');
                }
                $this->schemaCompatible = true;
            }
        }

        if (! empty($cfg)) {
            $this->logger->debug('Applied runtime config from server');
        }
    }
}
