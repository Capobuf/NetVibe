<?php

namespace App\Support;

use App\Exceptions\SnipeItException;
use App\Models\SnipeItConnection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\RateLimiter;

class SnipeItClient
{
    public static function normalizeUrl(string $url): string
    {
        // Installations may live under a path; it must not point into the API itself.
        return SafeHttp::normalizeUrl($url, allowPath: true) ?? throw new SnipeItException('unsafe_url');
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
                    'image' => self::imageUrl($row['image'] ?? null),
                    'model_id' => is_int($modelId) && $modelId > 0 ? $modelId : null,
                    'mapping' => $mapping,
                    'notes' => self::mappedValue($row, $mapping['description']),
                    'network' => [
                        'ip' => self::mappedValue($row, $mapping['ip']),
                        'mac' => MacAddress::normalize(self::mappedValue($row, $mapping['mac'])),
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

    private static function imageUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim(htmlspecialchars_decode($value, ENT_QUOTES));
        $parts = parse_url($url);

        return strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
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
            if (! SafeHttp::allowedAddress($address)) {
                throw new SnipeItException('unsafe_url');
            }
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new SnipeItException('network');
        }
        if (RateLimiter::tooManyAttempts('snipe-it:'.$url, 100)) {
            throw new SnipeItException('rate_limit');
        }
        RateLimiter::hit('snipe-it:'.$url, 60);
        try {
            $response = SafeHttp::request($url, $addresses[0], 2_000_000, SnipeItException::class)
                ->withToken($token)->asJson()
                ->connectTimeout(min(3, $remaining))->timeout(min(10, $remaining))
                ->withOptions(['verify' => true])
                ->get($url.'/api/v1/'.$endpoint, $query);
        } catch (ConnectionException|GuzzleException $exception) {
            // Upstream exceptions and bodies can contain credentials; return only controlled messages.
            SafeHttp::rethrowOwn($exception, null, SnipeItException::class);
            throw new SnipeItException('network');
        }
        // Snipe-IT has no dedicated 404 status: it is reported as a generic HTTP error.
        if ($status = SafeHttp::failure($response, [401 => 'unauthorized', 403 => 'forbidden', 429 => 'rate_limit'])) {
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
}
