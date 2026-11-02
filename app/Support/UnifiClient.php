<?php

namespace App\Support;

use App\Exceptions\MikrotikException;
use App\Exceptions\UnifiException;
use App\Models\UnifiConnection;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Message\ResponseInterface;

class UnifiClient
{
    private ?float $deadline = null;

    private int $budget = 20_000_000;

    public static function normalizeUrl(string $url): string
    {
        try {
            return MikrotikClient::normalizeUrl($url);
        } catch (MikrotikException) {
            throw new UnifiException('unsafe_url');
        }
    }

    public function hosts(#[\SensitiveParameter] UnifiConnection $connection): array
    {
        if ($connection->mode !== 'cloud') {
            throw new UnifiException('unsafe_url');
        }
        $hosts = $tokens = [];
        $token = null;
        do {
            $page = $this->read($connection, '/v1/hosts', null, array_filter(['pageSize' => 100, 'nextToken' => $token]));
            foreach ($this->rows($page) as $host) {
                $id = $this->text($host['id'] ?? null);
                if (! preg_match('/\A[a-zA-Z0-9:_-]{1,255}\z/', $id) || isset($hosts[$id])) {
                    throw new UnifiException('format');
                }
                $name = data_get($host, 'reportedState.name') ?? data_get($host, 'reportedState.hostname') ?? $id;
                $hosts[$id] = ['id' => $id, 'name' => $this->text($name)];
            }
            $token = $page['nextToken'] ?? null;
            if ($token !== null && $token !== '') {
                $token = $this->text($token, 4096);
                if (isset($tokens[$token]) || count($hosts) > 5000) {
                    throw new UnifiException('limit');
                }
                $tokens[$token] = true;
            }
        } while ($token !== null && $token !== '');

        return array_values($hosts);
    }

    public function info(#[\SensitiveParameter] UnifiConnection $connection, ?string $consoleId = null): array
    {
        $info = $this->read($connection, '/info', $consoleId);
        $version = $this->text($info['applicationVersion'] ?? null, 80);
        if (! preg_match('/\A\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?\z/', $version)) {
            throw new UnifiException('format');
        }
        // The documented baseline includes info, sites and adopted devices; Network 11 is not required.
        if (version_compare($version, '9.4.17', '<')) {
            throw new UnifiException('unsupported');
        }

        return ['applicationVersion' => $version];
    }

    public function sites(#[\SensitiveParameter] UnifiConnection $connection, ?string $consoleId = null): array
    {
        return array_map(fn (array $site) => [
            'id' => $this->uuid($site['id'] ?? null),
            'name' => $this->text($site['name'] ?? null),
        ], $this->pages($connection, '/sites', $consoleId));
    }

    public function devices(#[\SensitiveParameter] UnifiConnection $connection, string $siteId, ?string $consoleId = null): array
    {
        return array_map(fn (array $device) => $this->deviceData($device),
            $this->pages($connection, '/sites/'.$this->uuid($siteId).'/devices', $consoleId));
    }

    public function device(#[\SensitiveParameter] UnifiConnection $connection, string $siteId, string $deviceId, ?string $consoleId = null): array
    {
        $data = $this->read($connection, '/sites/'.$this->uuid($siteId).'/devices/'.$this->uuid($deviceId), $consoleId);
        if ($this->uuid($data['id'] ?? null) !== $deviceId) {
            throw new UnifiException('format');
        }
        $ports = data_get($data, 'interfaces.ports', []);
        if (! is_array($ports) || ! array_is_list($ports) || count($ports) > 1024) {
            throw new UnifiException('format');
        }
        $normalized = [];
        foreach ($ports as $port) {
            if (! is_array($port) || ! is_int($port['idx'] ?? null) || $port['idx'] < 1 || isset($normalized[$port['idx']])
                || ! in_array($port['state'] ?? null, ['UP', 'DOWN', 'UNKNOWN'], true)) {
                throw new UnifiException('format');
            }
            foreach (['speedMbps', 'maxSpeedMbps'] as $key) {
                if (isset($port[$key]) && (! is_int($port[$key]) || $port[$key] < 0)) {
                    throw new UnifiException('format');
                }
            }
            $poe = $port['poe'] ?? null;
            if ($poe !== null && (! is_array($poe) || ! is_bool($poe['enabled'] ?? null)
                || ! is_int($poe['type'] ?? null) || ! is_string($poe['standard'] ?? null)
                || ! in_array($poe['state'] ?? null, ['UP', 'DOWN', 'LIMITED', 'UNKNOWN'], true))) {
                throw new UnifiException('format');
            }
            $normalized[$port['idx']] = [
                'idx' => $port['idx'], 'connector' => $this->text($port['connector'] ?? null, 40), 'state' => $port['state'],
                'speed_mbps' => $port['speedMbps'] ?? null, 'max_speed_mbps' => $port['maxSpeedMbps'] ?? null,
                'poe' => $poe === null ? null : array_intersect_key($poe, array_flip(['standard', 'type', 'enabled', 'state'])),
            ];
        }
        ksort($normalized);

        return [...$this->deviceData($data), 'ports' => array_values($normalized),
            'uplink_device_id' => data_get($data, 'uplink.deviceId') === null ? null : $this->uuid(data_get($data, 'uplink.deviceId'))];
    }

    private function deviceData(array $device): array
    {
        $features = $device['features'] ?? [];
        if (! is_array($features)) {
            throw new UnifiException('format');
        }
        $features = array_is_list($features) ? $features : array_keys($features);
        foreach ($features as $feature) {
            $this->text($feature, 80);
        }
        $mac = strtoupper(str_replace('-', ':', $this->text($device['macAddress'] ?? null, 17)));
        if (! preg_match('/\A(?:[0-9A-F]{2}:){5}[0-9A-F]{2}\z/', $mac)) {
            throw new UnifiException('format');
        }

        return [
            'id' => $this->uuid($device['id'] ?? null), 'name' => $this->text($device['name'] ?? null),
            'model' => $this->text($device['model'] ?? null), 'mac_address' => $mac,
            'ip_address' => filter_var($device['ipAddress'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null,
            'state' => $this->text($device['state'] ?? null, 80), 'features' => $features,
            'firmware_version' => isset($device['firmwareVersion']) ? $this->text($device['firmwareVersion'], 80) : null,
        ];
    }

    public function networks(#[\SensitiveParameter] UnifiConnection $connection, string $siteId, string $version, ?string $consoleId = null): array
    {
        $networks = [];
        foreach ($this->networkDetails($connection, $siteId, $version, $consoleId) as $detail) {
            $id = $detail['id'];
            if (! is_int($detail['vlanId'] ?? null)
                || $detail['vlanId'] < 1 || $detail['vlanId'] > 4094
                || ! in_array($detail['management'] ?? null, ['GATEWAY', 'SWITCH', 'UNMANAGED'], true)
                || ! is_bool($detail['default'] ?? null)) {
                throw new UnifiException('format');
            }
            $ipv4 = $detail['ipv4Configuration'] ?? null;
            $cidr = null;
            $ipAddress = null;
            if ($ipv4 !== null) {
                if (! is_array($ipv4) || ! is_string($ipv4['hostIpAddress'] ?? null) || ! is_int($ipv4['prefixLength'] ?? null)) {
                    throw new UnifiException('format');
                }
                $cidr = Ipv4Cidr::parse($ipv4['hostIpAddress'].'/'.$ipv4['prefixLength']);
                if ($cidr === null) {
                    throw new UnifiException('format');
                }
                $ipAddress = $ipv4['hostIpAddress'];
            }
            $networks[] = [
                'id' => $id, 'name' => $this->text($detail['name'] ?? null),
                'vlan_id' => $detail['vlanId'], 'subnet' => $cidr['cidr'] ?? null, 'ip_address' => $ipAddress,
                'management' => $detail['management'], 'default' => $detail['default'],
            ];
        }

        return $networks;
    }

    public function networkDetails(#[\SensitiveParameter] UnifiConnection $connection, string $siteId, string $version, ?string $consoleId = null): array
    {
        // shortcut: network endpoints are verified from 10.0.162; extend only with an earlier official schema.
        if (version_compare($version, '10.0.162', '<')) {
            throw new UnifiException('unsupported');
        }
        $path = '/sites/'.$this->uuid($siteId).'/networks';
        try {
            $overview = $this->pages($connection, $path, $consoleId);
        } catch (UnifiException $exception) {
            throw $exception->status === 'not_found' ? new UnifiException('unsupported') : $exception;
        }
        $networks = [];
        foreach ($overview as $network) {
            $id = $this->uuid($network['id'] ?? null);
            $detail = $this->read($connection, $path.'/'.$id, $consoleId);
            if ($this->uuid($detail['id'] ?? null) !== $id) {
                throw new UnifiException('format');
            }
            $networks[] = array_intersect_key($detail, array_flip(['id', 'name', 'management', 'vlanId', 'default', 'enabled', 'deviceId', 'ipv4Configuration']));
        }

        return $networks;
    }

    public function clients(#[\SensitiveParameter] UnifiConnection $connection, string $siteId, ?string $consoleId = null): array
    {
        return array_map(fn ($client) => array_intersect_key($client, array_flip([
            'id', 'type', 'name', 'ipAddress', 'macAddress', 'connectedAt', 'access',
        ])), $this->pages($connection, '/sites/'.$this->uuid($siteId).'/clients', $consoleId));
    }

    private function pages(#[\SensitiveParameter] UnifiConnection $connection, string $path, ?string $consoleId): array
    {
        $rows = [];
        $offset = 0;
        $total = null;
        do {
            $page = $this->read($connection, $path, $consoleId, ['offset' => $offset, 'limit' => 200]);
            $data = $this->rows($page);
            if (($page['offset'] ?? null) !== $offset || ($page['count'] ?? null) !== count($data)
                || ! is_int($page['totalCount'] ?? null) || $page['totalCount'] < $offset + count($data)
                || ($total !== null && $page['totalCount'] !== $total)
                || ($data === [] && $offset < $page['totalCount'])) {
                throw new UnifiException('format');
            }
            $total = $page['totalCount'];
            foreach ($data as $row) {
                $id = $this->uuid($row['id'] ?? null);
                if (isset($rows[$id])) {
                    throw new UnifiException('format');
                }
                $rows[$id] = $row;
            }
            $offset += count($data);
            if ($page['totalCount'] > 5000) {
                throw new UnifiException('limit');
            }
        } while ($offset < $page['totalCount']);

        return array_values($rows);
    }

    protected function resolve(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
    }

    private function read(#[\SensitiveParameter] UnifiConnection $connection, string $path, ?string $consoleId, array $query = []): array
    {
        if (! $connection->enabled) {
            throw new UnifiException('suspended');
        }
        if (! preg_match('~\A(?:/v1/hosts|/info|/sites(?:/[a-f0-9-]{36}/(?:devices|networks|clients)(?:/[a-f0-9-]{36})?)?)\z~i', $path)) {
            throw new UnifiException('unsafe_url');
        }
        $cloud = $connection->mode === 'cloud';
        $base = $cloud ? 'https://api.ui.com' : self::normalizeUrl($connection->server_url);
        $rateKey = 'unifi:'.$base;
        if ($path !== '/v1/hosts') {
            if ($cloud) {
                if ($consoleId === null || ! in_array($consoleId, array_column($connection->hosts ?? [], 'id'), true)
                    || ! in_array($consoleId, $connection->selected_console_ids ?? [], true)
                    || ! preg_match('/\A[a-zA-Z0-9:_-]{1,255}\z/', $consoleId)) {
                    throw new UnifiException('forbidden');
                }
                $rateKey .= ':'.$consoleId;
                $path = '/v1/connector/consoles/'.rawurlencode($consoleId).'/proxy/network/integration/v1'.$path;
            } else {
                $path = '/proxy/network/integration/v1'.$path;
            }
        }
        $cooldownKey = 'unifi-retry:'.$connection->id.':'.($consoleId ?? ($cloud ? 'hosts' : 'direct'));
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            throw new UnifiException('rate_limit');
        }
        $this->deadline ??= microtime(true) + 45;
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new UnifiException('timeout');
        }
        $limit = min(10_000_000, $this->budget);
        if ($limit <= 0) {
            throw new UnifiException('limit');
        }
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT) ?? 443;
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            throw new UnifiException('network');
        }
        foreach ($addresses as $address) {
            self::normalizeUrl('https://'.$address);
            if ($cloud && ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new UnifiException('unsafe_url');
            }
        }
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new UnifiException('timeout');
        }
        if (RateLimiter::increment($rateKey, 60) > 100) {
            throw new UnifiException('rate_limit');
        }
        $handlerError = null;
        try {
            $response = Http::withHeaders(['X-API-Key' => $connection->api_key])->acceptJson()->withUserAgent('NetVibe')
                ->connectTimeout(min(3, $remaining))->timeout(min(25, $remaining))->withoutRedirecting()
                ->withOptions([
                    'verify' => $cloud || ! $connection->allow_unverified_certificate,
                    'proxy' => '', 'force_ip_resolve' => 'v4',
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$addresses[0]}"]],
                    'on_stats' => function (TransferStats $stats) use (&$handlerError): void {
                        $handlerError = $stats->getHandlerErrorData();
                    },
                    'on_headers' => function (ResponseInterface $response) use ($limit): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new UnifiException('limit');
                        }
                    },
                    'progress' => function ($total, $downloaded) use ($limit): void {
                        if ($downloaded > $limit) {
                            throw new UnifiException('limit');
                        }
                    },
                ])->get($base.$path, $query);
        } catch (ConnectionException|GuzzleException $exception) {
            if ($handlerError instanceof UnifiException) {
                throw $handlerError;
            }
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof UnifiException) {
                    throw $cause;
                }
            }
            throw new UnifiException(match ($handlerError) {
                28 => 'timeout',
                35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 => 'tls',
                default => 'network',
            });
        }
        $body = $response->body();
        $this->budget -= strlen($body);
        if (strlen($body) > $limit) {
            throw new UnifiException('limit');
        }
        if ($response->status() === 429) {
            $retryAfter = trim((string) $response->header('Retry-After'));
            $seconds = 60;
            if (preg_match('/\A[0-9]{1,9}\z/', $retryAfter)) {
                $seconds = max(1, (int) $retryAfter);
            } elseif (preg_match('/\A[A-Z][a-z]{2}, [0-9]{2} [A-Z][a-z]{2} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/', $retryAfter)) {
                $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $retryAfter, new \DateTimeZone('UTC'));
                if ($date && $date->format('D, d M Y H:i:s \G\M\T') === $retryAfter) {
                    $seconds = max(1, $date->getTimestamp() - now()->timestamp);
                }
            }
            // Keep a server-requested pause across requests and cron runs; never sleep inside an HTTP request.
            RateLimiter::hit($cooldownKey, $seconds);
        }
        $status = match (true) {
            $response->status() === 401 => 'unauthorized',
            $response->status() === 403 => 'forbidden',
            $response->status() === 404 => 'not_found',
            $response->status() === 429 => 'rate_limit',
            in_array($response->status(), [408, 504], true) => 'timeout',
            $response->redirect() => 'redirect',
            ! $response->successful() => 'http_error',
            default => null,
        };
        if ($status !== null) {
            throw new UnifiException($status);
        }
        $data = json_decode($body, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data) || array_is_list($data)) {
            throw new UnifiException('format');
        }

        return $data;
    }

    private function rows(array $page): array
    {
        $data = $page['data'] ?? null;
        if (! is_array($data) || ! array_is_list($data) || count($data) > 5000) {
            throw new UnifiException('format');
        }
        foreach ($data as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new UnifiException('format');
            }
        }

        return $data;
    }

    private function uuid(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $value)) {
            throw new UnifiException('format');
        }

        return strtolower($value);
    }

    private function text(mixed $value, int $maxLength = 255): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $maxLength) {
            throw new UnifiException('format');
        }

        return $value;
    }
}
