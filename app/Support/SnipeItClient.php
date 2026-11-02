<?php

namespace App\Support;

use App\Exceptions\SnipeItException;
use App\Models\SnipeItConnection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Message\ResponseInterface;

class SnipeItClient
{
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])
            || ! preg_match('/\A[a-zA-Z0-9.-]+\z/', $parts['host'])
            || preg_match('/[\s\\\\%]/', $url)) {
            throw new SnipeItException('unsafe_url');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (preg_match('/\A0x[0-9a-f]+\z/i', $host) || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ! self::allowedAddress($host))
            || (preg_match('/\A[0-9.]+\z/', $host) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
            throw new SnipeItException('unsafe_url');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('~/api(?:/|$)~i', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new SnipeItException('unsafe_url');
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) {
            throw new SnipeItException('unsafe_url');
        }

        return 'https://'.$host.($port === 443 ? '' : ':'.$port).$path;
    }

    public function verify(string $url, #[\SensitiveParameter] string $token): array
    {
        $deadline = microtime(true) + 20;
        $companies = $this->companies($url, $token, $deadline);
        $this->read($url, $token, 'hardware', ['limit' => 1], $deadline);

        return $companies;
    }

    public function companies(string $url, #[\SensitiveParameter] string $token, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + 20;
        $companies = [];
        for ($page = 0; $page < 20; $page++) {
            $data = $this->read($url, $token, 'companies', ['limit' => 500, 'offset' => count($companies), 'sort' => 'id', 'order' => 'asc'], $deadline);
            foreach ($data['rows'] as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1
                    || ! is_string($row['name'] ?? null)
                    || isset($companies[$row['id']])) {
                    throw new SnipeItException('format');
                }
                // Snipe-IT escapes company names for HTML, even in JSON responses.
                $name = htmlspecialchars_decode($row['name'], ENT_QUOTES);
                if (mb_strlen($name) > 255) {
                    throw new SnipeItException('format');
                }
                $companies[$row['id']] = ['id' => $row['id'], 'name' => $name];
            }
            if (count($companies) === $data['total']) {
                return array_values($companies);
            }
            if ($data['rows'] === [] || count($companies) > $data['total']) {
                throw new SnipeItException('format');
            }
        }
        throw new SnipeItException('limit');
    }

    public function fields(string $url, #[\SensitiveParameter] string $token): array
    {
        $fields = [
            ['value' => 'name', 'label' => 'Nome asset'],
            ['value' => 'asset_tag', 'label' => 'Asset tag'],
            ['value' => 'serial', 'label' => 'Seriale'],
            ['value' => 'notes', 'label' => 'Note asset'],
        ];
        $deadline = microtime(true) + 20;
        for ($offset = 0; $offset < 10_000;) {
            $data = $this->read($url, $token, 'fields', ['limit' => 500, 'offset' => $offset], $deadline);
            foreach ($data['rows'] as $row) {
                $column = $row['db_column_name'] ?? null;
                $name = self::text($row['name'] ?? null);
                if (! is_string($column) || ! str_starts_with($column, '_snipeit_') || mb_strlen($column) > 255 || $name === null) {
                    throw new SnipeItException('format');
                }
                $fields[] = ['value' => 'custom:'.$column, 'label' => $name];
            }
            $offset += count($data['rows']);
            if ($offset === $data['total']) {
                return $fields;
            }
            if ($data['rows'] === [] || $offset > $data['total']) {
                throw new SnipeItException('format');
            }
        }
        throw new SnipeItException('limit');
    }

    public function inventory(string $url, #[\SensitiveParameter] string $token, int $company, array $mapping = SnipeItConnection::DEFAULT_MAPPING): array
    {
        $deadline = microtime(true) + 55;
        $seen = $assets = $models = [];
        $total = null;
        do {
            $data = $this->read($url, $token, 'hardware', [
                'company_id' => $company, 'limit' => 100, 'offset' => count($seen), 'sort' => 'id', 'order' => 'asc',
            ], $deadline);
            $total ??= $data['total'];
            if ($total !== $data['total'] || ($data['rows'] === [] && count($seen) !== $total)) {
                throw new SnipeItException('format');
            }
            foreach ($data['rows'] as $row) {
                if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1 || isset($seen[$row['id']])) {
                    throw new SnipeItException('format');
                }
                $seen[$row['id']] = true;
                if (($row['company']['id'] ?? null) !== $company) {
                    continue;
                }
                $modelId = $row['model']['id'] ?? null;
                $assets[$row['id']] = [
                    'name' => self::mappedValue($row, $mapping['name']),
                    'asset_tag' => self::text($row['asset_tag'] ?? null),
                    'serial' => self::text($row['serial'] ?? null),
                    'model_id' => is_int($modelId) && $modelId > 0 ? $modelId : null,
                    'mapping' => $mapping,
                    'notes' => self::mappedValue($row, $mapping['description']),
                    'network' => [
                        'ip' => self::mappedValue($row, $mapping['ip']),
                        'mac' => ($mac = self::mappedValue($row, $mapping['mac'])) !== null
                            ? strtoupper(str_replace('-', ':', $mac)) : null,
                    ],
                ];
                if ($assets[$row['id']]['model_id'] && ! isset($models[$modelId])) {
                    $models[$modelId] = [
                        'name' => self::text($row['model']['name'] ?? null),
                        'manufacturer' => self::text($row['manufacturer']['name'] ?? null),
                        'category' => self::text($row['category']['name'] ?? null),
                    ];
                    // Hardware already includes model metadata on supported instances; fetch only missing essentials.
                    if ($models[$modelId]['name'] === null || $models[$modelId]['manufacturer'] === null) {
                        $model = $this->request($url, $token, 'models/'.$modelId, [], $deadline);
                        if (($model['id'] ?? null) !== $modelId) {
                            throw new SnipeItException('format');
                        }
                        $models[$modelId] = [
                            'name' => self::text($model['name'] ?? null),
                            'manufacturer' => self::text($model['manufacturer']['name'] ?? null),
                            'category' => self::text($model['category']['name'] ?? null),
                        ];
                    }
                }
            }
            if (count($seen) > $total) {
                throw new SnipeItException('format');
            }
        } while (count($seen) < $total);

        return ['assets' => $assets, 'models' => $models];
    }

    private static function mappedValue(array $row, ?string $field): ?string
    {
        if ($field === null) {
            return null;
        }
        if (str_starts_with($field, 'custom:')) {
            return self::text(collect($row['custom_fields'] ?? [])->firstWhere('field', substr($field, 7))['value'] ?? null);
        }
        if (str_starts_with($field, 'label:')) {
            return self::text($row['custom_fields'][substr($field, 6)]['value'] ?? null);
        }
        $value = self::text($row[$field] ?? null);

        return $field === 'notes' && $value !== null ? trim(strip_tags($value)) : $value;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new SnipeItException('format');
        }

        $text = trim(htmlspecialchars_decode($value, ENT_QUOTES));

        return $text === '' ? null : $text;
    }

    private function read(string $url, #[\SensitiveParameter] string $token, string $endpoint, array $query, float $deadline): array
    {
        $data = $this->request($url, $token, $endpoint, $query, $deadline);
        if (! is_int($data['total'] ?? null) || $data['total'] < 0
            || ! is_array($data['rows'] ?? null) || ! array_is_list($data['rows'])) {
            throw new SnipeItException('format');
        }

        return $data;
    }

    private function request(string $url, #[\SensitiveParameter] string $token, string $endpoint, array $query, float $deadline): array
    {
        $url = self::normalizeUrl($url);
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : gethostbynamel($host);
        if (! $addresses) {
            throw new SnipeItException('network');
        }
        foreach ($addresses as $address) {
            if (! self::allowedAddress($address)) {
                throw new SnipeItException('unsafe_url');
            }
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new SnipeItException('network');
        }
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        if (RateLimiter::tooManyAttempts('snipe-it:'.$url, 100)) {
            throw new SnipeItException('rate_limit');
        }
        RateLimiter::hit('snipe-it:'.$url, 60);
        try {
            $response = Http::withToken($token)->acceptJson()->asJson()->withUserAgent('NetVibe')
                ->connectTimeout(min(3, $remaining))->timeout(min(10, $remaining))->withoutRedirecting()
                ->withOptions([
                    'verify' => true, 'proxy' => '', 'force_ip_resolve' => 'v4',
                    // Pin the validated address so DNS cannot redirect credentials to a forbidden destination.
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$addresses[0]}"]],
                    'on_headers' => function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > 2_000_000) {
                            throw new SnipeItException('limit');
                        }
                    },
                    'progress' => function ($total, $downloaded): void {
                        if ($downloaded > 2_000_000) {
                            throw new SnipeItException('limit');
                        }
                    },
                ])->get($url.'/api/v1/'.$endpoint, $query);
        } catch (ConnectionException|GuzzleException $exception) {
            // Upstream exceptions and bodies can contain credentials; return only controlled messages.
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof SnipeItException) {
                    throw new SnipeItException($cause->status);
                }
            }
            throw new SnipeItException('network');
        }
        $status = match (true) {
            $response->status() === 401 => 'unauthorized',
            $response->status() === 403 => 'forbidden',
            $response->status() === 429 => 'rate_limit',
            $response->redirect() => 'redirect',
            ! $response->successful() => 'http_error',
            default => null,
        };
        if ($status) {
            throw new SnipeItException($status);
        }
        $body = $response->body();
        if (strlen($body) > 2_000_000) {
            throw new SnipeItException('limit');
        }
        if (str_contains(strtolower($response->header('Content-Type')), 'text/html') || str_starts_with(ltrim($body), '<')) {
            throw new SnipeItException('html');
        }
        $data = json_decode($body, true);
        if (is_array($data) && ($data['status'] ?? null) === 'error') {
            throw new SnipeItException('api_error');
        }
        if (! is_array($data)) {
            throw new SnipeItException('format');
        }

        return $data;
    }

    private static function allowedAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        // Private installations are supported; loopback, link-local, shared and reserved networks are not.
        $ip = ip2long($address);
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $cidr) {
            $range = Ipv4Cidr::parse($cidr);
            if ($ip >= $range['network_address'] && $ip <= $range['broadcast_address']) {
                return true;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && $ip < ip2long('224.0.0.0');
    }
}
