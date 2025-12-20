<?php

declare(strict_types=1);

namespace RemoteAgent\Drivers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

/**
 * Mikrotik API Driver
 *
 * Handles all device-specific communication with Mikrotik RouterOS devices.
 * This driver is responsible for:
 * - Constructing REST endpoint URLs
 * - Managing device-specific timeouts and authentication
 * - Converting device responses to standardized format
 *
 * This class should NOT be used for communicating with the NetVibe server.
 * Use NetVibeHttpClient for server communication.
 */
class MikrotikApiDriver
{
    private ?GuzzleClient $http;
    /**
     * TLS verification option for MikroTik REST API calls.
     * - bool: enable/disable system CA verification
     * - string: path to CA bundle
     */
    private bool|string $tlsVerify;

    public function __construct()
    {
        $this->tlsVerify = $this->resolveTlsVerify();
        if (class_exists(GuzzleClient::class)) {
            // Initialize Guzzle client for device communication
            // Mikrotik devices may have slower response times, so we use a reasonable timeout
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
        // Default: do NOT verify device TLS certificates (common: self-signed).
        $raw = getenv('MIKROTIK_TLS_VERIFY');
        if ($raw === false || $raw === '') {
            $raw = 'false';
        }

        $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            $bool = false;
        }

        $caBundle = getenv('MIKROTIK_TLS_CA_BUNDLE');
        if ($bool === true && is_string($caBundle) && trim($caBundle) !== '') {
            return trim($caBundle);
        }

        return $bool;
    }

    /**
     * Execute a REST API call against a Mikrotik device.
     *
     * @param string $host Device IP or hostname (with protocol, e.g., https://192.168.1.1)
     * @param string $path REST API endpoint path (e.g., /rest/ip/arp)
     * @param string|null $username Device username for authentication
     * @param string|null $password Device password for authentication
     * @param int $timeout Request timeout in seconds (device-specific)
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     *
     * @return array API response as array, or error array with keys: 'ok', 'error', 'status', 'message'
     */
    public function executeRestCall(
        string $host,
        string $path,
        ?string $username = null,
        ?string $password = null,
        int $timeout = 5,
        string $method = 'GET'
    ): array {
        $url = rtrim($host, '/') . $path;

        try {
            if ($this->http instanceof GuzzleClient) {
                return $this->executeWithGuzzle($url, $method, $username, $password, $timeout);
            }

            // Fallback to file_get_contents if Guzzle not available
            return $this->executeWithFileGetContents($url, $method, $username, $password, $timeout);
        } catch (ConnectException $e) {
            // Network problem or timeout
            return [
                'ok' => false,
                'error' => 'NET_TIMEOUT',
                'message' => $e->getMessage(),
            ];
        } catch (RequestException $e) {
            // HTTP response received with error
            $resp = $e->getResponse();
            if ($resp && in_array($resp->getStatusCode(), [401, 403], true)) {
                return [
                    'ok' => false,
                    'error' => 'AUTH_ERROR',
                    'status' => $resp->getStatusCode(),
                ];
            }

            return [
                'ok' => false,
                'error' => 'http_error',
                'message' => $e->getMessage(),
                'status' => $resp ? $resp->getStatusCode() : null,
            ];
        }
    }

    /**
     * Convenience method for GET requests to a device endpoint
     */
    public function deviceGet(
        string $host,
        string $path,
        ?string $username = null,
        ?string $password = null,
        int $timeout = 5
    ): array {
        return $this->executeRestCall($host, $path, $username, $password, $timeout, 'GET');
    }

    /**
     * Convenience method for POST requests to a device endpoint
     */
    public function devicePost(
        string $host,
        string $path,
        array $body = [],
        ?string $username = null,
        ?string $password = null,
        int $timeout = 5
    ): array {
        return $this->executeRestCall($host, $path, $username, $password, $timeout, 'POST');
    }

    /**
     * Execute request using Guzzle HTTP client
     */
    private function executeWithGuzzle(
        string $url,
        string $method,
        ?string $username,
        ?string $password,
        int $timeout
    ): array {
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'headers' => ['Accept' => 'application/json'],
        ];

        if ($username !== null) {
            $options['auth'] = [$username, $password ?: ''];
        }

        error_log("[MikrotikDriver] Executing {$method} {$url} with timeout {$timeout}s");

        $resp = $this->http->request($method, $url, $options);
        $body = (string) $resp->getBody();

        error_log("[MikrotikDriver] Response status: " . $resp->getStatusCode());

        return json_decode($body, true) ?? ['ok' => true];
    }

    /**
     * Fallback implementation using file_get_contents and Basic Auth
     */
    private function executeWithFileGetContents(
        string $url,
        string $method,
        ?string $username,
        ?string $password,
        int $timeout
    ): array {
        $headers = ['Accept: application/json'];

        if ($username !== null) {
            $auth = base64_encode($username . ':' . ($password ?? ''));
            $headers[] = 'Authorization: Basic ' . $auth;
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
            ],
        ];

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

        error_log("[MikrotikDriver] Executing {$method} {$url} (file_get_contents) with timeout {$timeout}s");

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return [
                'ok' => false,
                'error' => 'http_error',
                'message' => 'Failed to reach device',
            ];
        }

        return json_decode($body, true) ?? ['ok' => true];
    }
}
