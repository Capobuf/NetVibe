<?php

namespace App\Support;

use App\Exceptions\MikrotikException;
use App\Models\MikrotikConnection;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Http\Message\ResponseInterface;

class MikrotikClient
{
    // Only documented read menus and selected properties; never fetch scripts or sensitive menus.
    public const MENUS = [
        'system/identity' => ['Identità', 'name'],
        'system/resource' => ['Sistema', 'version,board-name,architecture-name,platform'],
        'interface' => ['Interfacce', 'name,type,mac-address,disabled,running,comment'],
        'interface/ethernet' => ['Porte Ethernet', 'name,default-name,mac-address,switch,disabled,running,comment'],
        'interface/vlan' => ['Interfacce VLAN', 'name,interface,vlan-id,use-service-tag,disabled,comment'],
        'interface/list' => ['Liste interfacce', 'name,include,exclude,dynamic,comment'],
        'interface/list/member' => ['Membri delle liste', 'list,interface,dynamic,disabled,comment'],
        'interface/bridge' => ['Configurazione bridge', 'name,vlan-filtering,pvid,disabled,comment'],
        'interface/bridge/port' => ['Porte dei bridge', 'bridge,interface,pvid,frame-types,ingress-filtering,hw,dynamic,disabled,comment'],
        'interface/bridge/vlan' => ['Appartenenze VLAN L2', 'bridge,vlan-ids,tagged,untagged,current-tagged,current-untagged,dynamic,disabled,comment'],
        'interface/bridge/host' => ['MAC appresi e locali', 'bridge,on-interface,mac-address,vid,dynamic,local,external'],
        'ip/address' => ['Indirizzi IPv4', 'address,network,interface,actual-interface,dynamic,disabled,invalid,comment'],
        'ip/arp' => ['ARP', 'address,mac-address,interface,status,dynamic,complete,disabled,comment'],
        'ip/dhcp-server' => ['Server DHCP', 'name,interface,address-pool,disabled,invalid,comment'],
        'ip/dhcp-server/network' => ['Reti DHCP', 'address,gateway,netmask,dns-server,domain,comment'],
        'ip/dhcp-server/lease' => ['Lease DHCP', 'address,mac-address,server,dynamic,disabled,status,active-address,active-mac-address,active-server,host-name,comment'],
        'ip/pool' => ['Pool IPv4', 'name,ranges,next-pool,comment'],
        'ip/neighbor' => ['Neighbor discovery', 'interface,address,mac-address,identity,platform,board,version,discovered-by,interface-name,age'],
    ];

    public const LLDP_MENU = 'ip/neighbor/lldp';

    private const LLDP_PROPERTIES = 'interface,mac-address,lldp-chassis-id,lldp-chassis-id-subtype,lldp-port-id,lldp-port-id-subtype,lldp-system-name,lldp-port-description';

    private string $diagnosticLog = '';

    private bool $anonymousTls = false;

    public function diagnosticLog(): string
    {
        return $this->diagnosticLog;
    }

    private function log(string $line): void
    {
        // Bound each persisted attempt below the TEXT column's byte limit.
        $this->diagnosticLog = mb_strcut($this->diagnosticLog.$line."\n", 0, 60000, 'UTF-8');
    }

    public static function normalizeUrl(string $url): string
    {
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || preg_match('/[\s\\\\%]/', $url)) {
            throw new MikrotikException('unsafe_url');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || $host === 'metadata.google.internal' || preg_match('/\A0x[0-9a-f]+\z/i', $host)
            || ($ip && ! self::allowedAddress($host))
            || (! $ip && preg_match('/\A[0-9.]+\z/', $host))) {
            throw new MikrotikException('unsafe_url');
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) {
            throw new MikrotikException('unsafe_url');
        }

        return 'https://'.$host.($port === 443 ? '' : ':'.$port);
    }

    public function inspect(#[\SensitiveParameter] MikrotikConnection $connection, bool $analyze): array
    {
        $result = $this->collect($connection, $analyze);

        return [
            'status' => $result['status'], 'identity' => $result['identity'],
            'analysis' => $analyze ? [
                'coverage' => $result['coverage'],
                'findings' => MikrotikAnalysis::summarize($result['tables']),
                'cases' => MikrotikAnalysis::cases($result['tables']),
            ] : null,
        ];
    }

    public function snapshot(#[\SensitiveParameter] MikrotikConnection $connection): array
    {
        return $this->collect($connection, true, true);
    }

    private function collect(#[\SensitiveParameter] MikrotikConnection $connection, bool $analyze, bool $sync = false): array
    {
        $this->diagnosticLog = '';
        $this->anonymousTls = false;
        $this->log($sync ? 'Sincronizzazione REST' : ($analyze ? 'Analisi REST' : 'Verifica connessione REST'));
        $deadline = microtime(true) + 45;
        $url = self::normalizeUrl($connection->server_url);
        $host = parse_url($url, PHP_URL_HOST);
        $this->log('Risoluzione IPv4: '.$host);
        $addresses = $this->resolve($host);
        if (! $addresses) {
            $this->log('Nessun indirizzo IPv4 risolto.');
            throw new MikrotikException('network');
        }
        foreach ($addresses as $address) {
            if (! self::allowedAddress($address)) {
                $this->log('Indirizzo IPv4 non consentito: '.$address);
                throw new MikrotikException('unsafe_url');
            }
        }
        $this->log('IPv4: '.implode(', ', $addresses));
        $tables = $coverage = [];
        $budget = 4_000_000;
        $identity = null;
        $stop = null;
        $menus = $analyze ? self::MENUS : array_slice(self::MENUS, 0, 2, true);
        if ($sync) {
            $menus[self::LLDP_MENU] = ['Dettagli LLDP', self::LLDP_PROPERTIES];
        }
        foreach ($menus as $path => [$label, $properties]) {
            $status = 'not_read';
            $attempted = false;
            if (! $stop) {
                try {
                    $emptyParent = $sync && (
                        (str_starts_with($path, 'interface/bridge/') && ($tables['interface/bridge'] ?? null) === [])
                        || (in_array($path, ['ip/dhcp-server/network', 'ip/dhcp-server/lease', 'ip/pool'], true) && ($tables['ip/dhcp-server'] ?? null) === [])
                    );
                    $tables[$path] = $emptyParent ? [] : $this->read($connection, $url, $addresses[0], $path,
                        $properties.($sync && ! str_starts_with($path, 'system/') ? ',.id' : ''), $deadline, $budget, $attempted);
                    $status = 'ok';
                } catch (MikrotikException $exception) {
                    $status = $exception->status;
                    $this->log('/rest/'.$path.' — '.$status.': '.$exception->getMessage());
                    if ($path !== self::LLDP_MENU && (str_starts_with($path, 'system/') || in_array($status, ['unauthorized', 'network', 'tls', 'timeout', 'rate_limit', 'redirect', 'limit'], true))) {
                        $stop = $status;
                    }
                }
            }
            $rows = $tables[$path] ?? null;
            $fields = [];
            foreach ($rows ?? [] as $row) {
                $fields = array_unique([...$fields, ...array_keys($row)]);
            }
            $coverage[] = [
                'path' => '/'.$path, 'label' => $label, 'status' => $status,
                'attempted' => $attempted,
                'count' => $rows === null ? null : count($rows),
                'fields' => array_values($fields),
                'message' => $status === 'ok' ? ($rows === [] ? 'Menu disponibile, nessun elemento.' : 'Letto dal dispositivo.') : MikrotikException::messageFor($status),
            ];
            if ($path === 'system/resource' && ! $stop) {
                $system = $tables[$path][0] ?? [];
                $name = $tables['system/identity'][0]['name'] ?? null;
                if (! is_string($name) || ! is_string($system['version'] ?? null)) {
                    $stop = 'format';
                } else {
                    $identity = ['name' => $name, 'version' => $system['version'], 'board' => $system['board-name'] ?? null, 'architecture' => $system['architecture-name'] ?? null];
                    if (! preg_match('/\A7\./', $system['version'])) {
                        $stop = 'version';
                    }
                }
            }
        }
        $status = $stop ?? (collect($coverage)->contains(fn ($menu) => $menu['path'] !== '/'.self::LLDP_MENU && $menu['status'] !== 'ok') ? 'partial' : 'ok');

        $this->log('Esito: '.$status);

        // Payloads stay in memory; only the caller's normalized records may be persisted.
        return compact('status', 'identity', 'coverage', 'tables');
    }

    protected function resolve(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
    }

    private function read(#[\SensitiveParameter] MikrotikConnection $connection, string $url, string $address, string $path, string $properties, float $deadline, int &$budget, bool &$attempted): array
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new MikrotikException('timeout');
        }
        $limit = min(2_000_000, $budget);
        if ($limit <= 0) {
            throw new MikrotikException('limit');
        }
        if (RateLimiter::tooManyAttempts('mikrotik:'.$url, 80)) {
            throw new MikrotikException('rate_limit');
        }
        RateLimiter::hit('mikrotik:'.$url, 60);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $trace = fopen('php://memory', 'w+');
        $handlerError = null;
        $retryAnonymous = false;
        // Pin validated DNS results for both TLS attempts.
        $curlOptions = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]];
        if ($this->anonymousTls) {
            $curlOptions[CURLOPT_SSL_CIPHER_LIST] = 'ADH-AES256-SHA256:ADH-AES128-SHA256:@SECLEVEL=0';
        }
        $this->log('GET '.$url.'/rest/'.$path.'?'.http_build_query(['.proplist' => $properties]));
        $this->log('Destinazione: '.$address.':'.$port.'; verifica certificato: '.($connection->allow_unverified_certificate ? 'disabilitata' : 'abilitata'));
        try {
            $attempted = true;
            $response = Http::withBasicAuth($connection->username, $connection->password)->acceptJson()->withUserAgent('NetVibe')
                ->connectTimeout(min(3, $remaining))->timeout(min(8, $remaining))->withoutRedirecting()
                ->withOptions([
                    'verify' => ! $connection->allow_unverified_certificate, 'proxy' => '', 'force_ip_resolve' => 'v4',
                    'debug' => $trace,
                    'on_stats' => function (TransferStats $stats) use (&$handlerError): void {
                        $handlerError = $stats->getHandlerErrorData();
                    },
                    'curl' => $curlOptions,
                    ...($this->anonymousTls ? [
                        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                        'crypto_method_max' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    ] : []),
                    'on_headers' => function (ResponseInterface $response) use ($limit): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new MikrotikException('limit');
                        }
                    },
                    'progress' => function ($total, $downloaded) use ($limit): void {
                        if ($downloaded > $limit) {
                            throw new MikrotikException('limit');
                        }
                    },
                ])->get($url.'/rest/'.$path, ['.proplist' => $properties]);
        } catch (ConnectionException|GuzzleException $exception) {
            if ($handlerError instanceof MikrotikException) {
                throw $handlerError;
            }
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof MikrotikException) {
                    throw $cause;
                }
            }
            $retryAnonymous = $handlerError === 35 && $connection->allow_unverified_certificate && ! $this->anonymousTls;
            if (! $retryAnonymous) {
                throw new MikrotikException(match ($handlerError) {
                    28 => 'timeout',
                    35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 => 'tls',
                    default => 'network',
                });
            }
        } finally {
            rewind($trace);
            $secrets = array_filter([
                $connection->password,
                rawurlencode($connection->password),
                base64_encode($connection->username.':'.$connection->password),
            ], fn ($secret) => $secret !== '');
            while (($line = fgets($trace)) !== false) {
                // Keep cURL transport lines, never authentication, response headers or bodies.
                if (str_starts_with($line, '* ') && stripos($line, ' auth ') === false) {
                    $this->log(str_replace($secrets, '[omesso]', rtrim(mb_convert_encoding($line, 'UTF-8', 'UTF-8'))));
                }
            }
            fclose($trace);
            if (is_int($handlerError) && $handlerError !== 0) {
                $this->log('cURL '.$handlerError.': '.curl_strerror($handlerError));
            }
        }
        if ($retryAnonymous) {
            $this->anonymousTls = true;
            $this->log('Nuovo tentativo: TLS 1.2 anonimo senza certificato (opzione abilitata).');

            return $this->read($connection, $url, $address, $path, $properties, $deadline, $budget, $attempted);
        }
        $this->log('HTTP '.$response->status());
        $body = $response->body();
        $budget -= strlen($body);
        if (strlen($body) > $limit) {
            throw new MikrotikException('limit');
        }
        $status = match (true) {
            $response->status() === 401 => 'unauthorized',
            $response->status() === 403 => 'forbidden',
            $response->status() === 404 => 'not_found',
            $response->status() === 429 => 'rate_limit',
            $response->redirect() => 'redirect',
            ! $response->successful() => 'http_error',
            default => null,
        };
        if ($status) {
            throw new MikrotikException($status);
        }
        $data = json_decode($body, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new MikrotikException('not_json');
        }
        if (str_starts_with($path, 'system/') && ! array_is_list($data)) {
            $data = [$data];
        }
        if (count($data) > 5000) {
            throw new MikrotikException('limit');
        }
        if (! array_is_list($data) || (str_starts_with($path, 'system/') && count($data) !== 1)) {
            throw new MikrotikException('format');
        }
        $allowed = array_flip(explode(',', $properties));
        $required = match ($path) {
            'interface/vlan' => ['name', 'interface', 'vlan-id'],
            'interface/bridge/vlan' => ['bridge', 'vlan-ids'],
            'interface/bridge/port' => ['bridge', 'interface'],
            'interface/list/member' => ['list', 'interface'],
            'ip/address' => ['address', 'interface'],
            default => [explode(',', $properties)[0]],
        };
        foreach ($data as &$row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new MikrotikException('format');
            }
            $row = array_intersect_key($row, $allowed);
            if (array_diff($required, array_keys($row)) !== []) {
                throw new MikrotikException('format');
            }
            foreach ($row as $value) {
                if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > 4096)) {
                    throw new MikrotikException('format');
                }
            }
        }

        return $data;
    }

    private static function allowedAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $address === '168.63.129.16') {
            return false;
        }
        $ip = ip2long($address);
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $cidr) {
            $range = Ipv4Cidr::parse($cidr);
            if ($ip >= $range['network_address'] && $ip <= $range['broadcast_address']) {
                return true;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false && $ip < ip2long('224.0.0.0');
    }
}
