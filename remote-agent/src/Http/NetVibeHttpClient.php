<?php

declare(strict_types=1);

namespace RemoteAgent\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;

/**
 * NetVibe HTTP Client
 *
 * Handles all communication with the NetVibe server.
 * This client is responsible for:
 * - Polling for jobs from the server
 * - Submitting job results back to the server
 * - Retrieving agent configuration
 * - Submitting discovery results
 *
 * This class should NOT be used for device-specific communication.
 * Use MikrotikApiDriver (or other device drivers) for device communication.
 */
class NetVibeHttpClient
{
    private string $serverUrl;
    private string $token;
    private ?GuzzleClient $http;
    /**
     * Guzzle/stream TLS verification option.
     * - bool: enable/disable system CA verification
     * - string: path to CA bundle
     */
    private bool|string $tlsVerify;

    public function __construct(string $serverUrl, string $token)
    {
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->token = $token;
        $this->tlsVerify = $this->resolveTlsVerify();

        if (class_exists(GuzzleClient::class)) {
            // Initialize Guzzle client for server communication
            // Server should be responsive, so standard timeout is acceptable
            $this->http = new GuzzleClient([
                'timeout' => 10,
                'verify' => $this->tlsVerify,
            ]);
        } else {
            $this->http = null;
        }
    }

    private function resolveTlsVerify(): bool|string
    {
        // Default: verify TLS certificates for the NetVibe server.
        $raw = getenv('NETVIBE_TLS_VERIFY');
        if ($raw === false || $raw === '') {
            $raw = 'true';
        }

        $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            $bool = true;
        }

        $caBundle = getenv('NETVIBE_TLS_CA_BUNDLE');
        if ($bool === true && is_string($caBundle) && trim($caBundle) !== '') {
            return trim($caBundle);
        }

        return $bool;
    }

    /**
     * Get agent configuration from the server
     */
    public function getAgentConfig(array $extraHeaders = []): array
    {
        return $this->get('/api/v1/agent/config', $extraHeaders);
    }

    /**
     * Poll the server for pending jobs assigned to this agent
     */
    public function pollJobs(array $extraHeaders = []): array
    {
        return $this->get('/api/v1/agent/jobs', $extraHeaders);
    }

    /**
     * Submit the result of a completed job to the server
     */
    public function postJobResult(string $jobId, array $body, array $extraHeaders = []): array
    {
        return $this->post('/api/v1/agent/jobs/' . urlencode($jobId) . '/result', $body, $extraHeaders);
    }

    /**
     * Submit discovery results to the server
     */
    public function postDiscoveryResults(array $results, array $extraHeaders = []): array
    {
        return $this->post('/api/v1/discovery/results', $results, $extraHeaders);
    }

    /**
     * Submit agent logs to the server
     */
    public function postAgentLogs(array $logs, array $extraHeaders = []): array
    {
        return $this->post('/api/v1/agent/logs', $logs, $extraHeaders);
    }

    /**
     * Perform a GET request to the server
     */
    private function get(string $path, array $extraHeaders = []): array
    {
        $url = $this->serverUrl . $path;

        try {
            if ($this->http instanceof GuzzleClient) {
                $headers = array_merge($this->headers(), $extraHeaders);

                $resp = $this->http->request('GET', $url, [
                    'headers' => $headers,
                ]);
                return json_decode((string) $resp->getBody(), true) ?? [];
            }

            // Fallback to file_get_contents
            $hdrs = array_merge($this->headers(), $extraHeaders);
            $headerLines = [];
            foreach ($hdrs as $key => $value) {
                $headerLines[] = $key . ': ' . $value;
            }
            $opts = ['http' => ['method' => 'GET', 'header' => implode("\r\n", $headerLines)]];

            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme === 'https') {
                $opts['ssl'] = [
                    'verify_peer' => $this->tlsVerify !== false,
                    'verify_peer_name' => $this->tlsVerify !== false,
                    'allow_self_signed' => $this->tlsVerify === false,
                ];

                if (is_string($this->tlsVerify)) {
                    $opts['ssl']['cafile'] = $this->tlsVerify;
                }
            }

            $context = stream_context_create($opts);
            $body = @file_get_contents($url, false, $context);
            return $body ? json_decode($body, true) ?? [] : [];
        } catch (RequestException $e) {
            $this->handleHttpException($e);
            throw $e;
        }
    }

    /**
     * Perform a POST request to the server
     */
    private function post(string $path, array $data, array $extraHeaders = []): array
    {
        $url = $this->serverUrl . $path;
        $payload = json_encode($data);

        try {
            if ($this->http instanceof GuzzleClient) {
                $headers = array_merge($this->headers(), ['Content-Type' => 'application/json'], $extraHeaders);

                $resp = $this->http->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $payload,
                ]);
                return json_decode((string) $resp->getBody(), true) ?? [];
            }

            $hdrs = array_merge($this->headers(), ['Content-Type' => 'application/json'], $extraHeaders);
            $headerLines = [];
            foreach ($hdrs as $key => $value) {
                $headerLines[] = $key . ': ' . $value;
            }
            $opts = ['http' => ['method' => 'POST', 'header' => implode("\r\n", $headerLines), 'content' => $payload]];

            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme === 'https') {
                $opts['ssl'] = [
                    'verify_peer' => $this->tlsVerify !== false,
                    'verify_peer_name' => $this->tlsVerify !== false,
                    'allow_self_signed' => $this->tlsVerify === false,
                ];

                if (is_string($this->tlsVerify)) {
                    $opts['ssl']['cafile'] = $this->tlsVerify;
                }
            }

            $context = stream_context_create($opts);
            $body = @file_get_contents($url, false, $context);
            return $body ? json_decode($body, true) ?? [] : [];
        } catch (RequestException $e) {
            $this->handleHttpException($e);
            throw $e;
        }
    }

    /**
     * Build HTTP headers for server authentication
     */
    private function headers(): array
    {
        $hdr = [
            'Accept' => 'application/json',
        ];

        if (!empty($this->token)) {
            $hdr['Authorization'] = 'Bearer ' . $this->token;
        }

        return $hdr;
    }

    /**
     * Handle HTTP exceptions from the server
     */
    private function handleHttpException(RequestException $e): void
    {
        $resp = $e->getResponse();
        if (!$resp) {
            // connection/timeout
            return;
        }

        $code = $resp->getStatusCode();
        // Note: We're not logging individual HTTP responses here to keep logs clean.
        // The calling code (AgentWorkCommand) handles logging of errors.
    }
}
