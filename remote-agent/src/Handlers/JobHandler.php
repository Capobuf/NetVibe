<?php

declare(strict_types=1);

namespace RemoteAgent\Handlers;

use RemoteAgent\Drivers\MikrotikApiDriver;
use RemoteAgent\Http\NetVibeHttpClient;
use RemoteAgent\Logging\Logger;
use RemoteAgent\Support\DeviceResultBuilder;

class JobHandler
{
    private MikrotikApiDriver $mikrotikDriver;
    private NetVibeHttpClient $netVibeClient;
    private Logger $logger;
    private DeviceResultBuilder $deviceResultBuilder;

    public function __construct(MikrotikApiDriver $mikrotikDriver, NetVibeHttpClient $netVibeClient = null, ?Logger $logger = null, ?DeviceResultBuilder $deviceResultBuilder = null)
    {
        $this->mikrotikDriver = $mikrotikDriver;
        $this->netVibeClient = $netVibeClient;
        $this->logger = $logger ?? new Logger('info');
        $this->deviceResultBuilder = $deviceResultBuilder ?? new DeviceResultBuilder();
    }

    public function handleCheckConnectivity(array $payload): array
    {
        // Prefer explicit target_ip, fallback to hostname
        $target = $payload['target_ip'] ?? $payload['hostname'] ?? ($payload['ip_addr'] ?? null);
        if (! $target) {
            return ['ok' => false, 'error' => 'no_target'];
        }

        $username = $payload['username'] ?? null;
        $password = $payload['password'] ?? null;
        $timeoutMs = (int) ($payload['timeout_ms'] ?? 200);
        $timeoutSec = max(5, (int) ceil($timeoutMs / 1000)); // deep discovery needs longer HTTP calls

        // Try HTTPS REST endpoints first; fall back to HTTP
        $schemes = ['https://', 'http://'];
        $resource = null;
        $identity = null;

        foreach ($schemes as $scheme) {
            $host = $scheme . $target;
            $res = $this->mikrotikDriver->deviceGet($host, '/rest/system/resource', $username, $password, $timeoutSec);
            if (isset($res['error'])) {
                // auth error: stop and report
                if ($res['error'] === 'AUTH_ERROR') {
                    return ['ok' => false, 'error' => 'AUTH_ERROR', 'message' => 'Authentication failed'];
                }

                // network timeout: try next scheme or report later
                if ($res['error'] === 'NET_TIMEOUT') {
                    // try next scheme (http)
                    continue;
                }

                // other HTTP errors: try next scheme
                continue;
            }

            // success; fetch identity
            $resource = $res;
            $ident = $this->mikrotikDriver->deviceGet($host, '/rest/system/identity', $username, $password, $timeoutSec);
            if (isset($ident['error'])) {
                // if identity had auth error, treat as auth error
                if ($ident['error'] === 'AUTH_ERROR') {
                    return ['ok' => false, 'error' => 'AUTH_ERROR', 'message' => 'Authentication failed (identity)'];
                }

                // identity network problem: report resource but flag identity missing
                $identity = ['ok' => false, 'error' => $ident['error'], 'message' => $ident['message'] ?? null];
            } else {
                $identity = $ident;
            }

            // if we've got this far, consider it a successful connectivity check
            return [
                'ok' => true,
                'target' => $target,
                'resource' => $resource,
                'identity' => $identity,
            ];
        }

        // All attempts failed due to network timeouts or other issues
        return ['ok' => false, 'error' => 'NET_UNREACHABLE'];
    }

    public function handleDiscoveryDeep(array $payload): array
    {
        // If the payload contains a 'legacy_fping' marker or a cidr -> run legacy scan
        if (! empty($payload['legacy_fping']) || ! empty($payload['cidr'])) {
            return $this->handleLegacyConfig($payload);
        }

        $target = $payload['target_ip'] ?? $payload['hostname'] ?? ($payload['ip_addr'] ?? null);
        $username = $payload['username'] ?? null;
        $password = $payload['password'] ?? null;
        $timeoutMs = (int) ($payload['timeout_ms'] ?? 5000);
        $timeoutSec = max(1, (int) ceil($timeoutMs / 1000));

        if (! $target) {
            return ['ok' => false, 'error' => 'no_target'];
        }

        $schemes = ['https://', 'http://'];
        foreach ($schemes as $scheme) {
            $host = $scheme . $target;
            $this->logger->debug('DiscoveryDeep: probing host ' . $host);

            $this->logger->debug('DiscoveryDeep: GET system/resource ' . $host);
            $resource = $this->mikrotikDriver->deviceGet($host, '/rest/system/resource', $username, $password, $timeoutSec);
            if (isset($resource['error'])) {
                $this->logger->warning('DiscoveryDeep: system/resource failed on ' . $host . ' error=' . ($resource['error'] ?? 'unknown'));
                if ($resource['error'] === 'AUTH_ERROR') {
                    return ['ok' => false, 'error' => 'AUTH_ERROR'];
                }
                continue;
            }

            $this->logger->debug('DiscoveryDeep: GET system/identity ' . $host);
            $identity = $this->mikrotikDriver->deviceGet($host, '/rest/system/identity', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: GET interface ' . $host);
            $interfaces = $this->mikrotikDriver->deviceGet($host, '/rest/interface', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: GET interface/bridge/vlan ' . $host);
            $vlans = $this->mikrotikDriver->deviceGet($host, '/rest/interface/bridge/vlan', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: GET interface/bridge/host ' . $host);
            $fdb = $this->mikrotikDriver->deviceGet($host, '/rest/interface/bridge/host', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: GET ip/neighbor ' . $host);
            $lldp = $this->mikrotikDriver->deviceGet($host, '/rest/ip/neighbor', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: GET ip/arp ' . $host);
            $arp = $this->mikrotikDriver->deviceGet($host, '/rest/ip/arp', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: GET ip/dhcp-server/lease ' . $host);
            $dhcp = $this->mikrotikDriver->deviceGet($host, '/rest/ip/dhcp-server/lease', $username, $password, $timeoutSec);

            $this->logger->debug('DiscoveryDeep: completed fetch for ' . $host);

            $deviceResult = $this->deviceResultBuilder->fromMikrotik(
                $target,
                $resource,
                $identity,
                $interfaces,
                $vlans,
                $arp,
                $dhcp,
                $lldp,
                $fdb
            );

            return [
                'ok' => true,
                'device_result' => $deviceResult,
            ];
        }

        return ['ok' => false, 'error' => 'NET_UNREACHABLE'];
    }

    /**
     * Permette di aggiornare il limite max_records_per_dataset a runtime
     * sulla base della configurazione ricevuta dal server.
     */
    public function setMaxRecordsPerDataset(int $max): void
    {
        $this->deviceResultBuilder->setMaxRecordsPerDataset($max);
    }

    /**
     * Legacy discovery: run fping on provided CIDR (or 'targets') and post results
     * to the control server discovery ingest endpoint.
     */
    public function handleLegacyConfig(array $payload): array
    {
        $cidr = $payload['cidr'] ?? null;
        $targets = $payload['targets'] ?? null;
        $timeout = (int) ($payload['timeout_ms'] ?? 200);

        $found = [];

        if ($cidr) {
            $cmd = sprintf('fping -a -g -r 1 -t %d %s 2>&1', $timeout, escapeshellarg($cidr));
            $out = [];
            @exec($cmd, $out, $exit);

            foreach ($out as $line) {
                $ip = trim($line);
                if ($ip !== '') {
                    $found[] = ['ip_addr' => $ip];
                }
            }
        }

        if (is_array($targets)) {
            foreach ($targets as $t) {
                $ip = $t['ip'] ?? $t;
                if ($ip) $found[] = ['ip_addr' => $ip];
            }
        }

        if (empty($found)) {
            return ['ok' => false, 'error' => 'no_hosts_found'];
        }

        // Send to server discovery endpoint (via NetVibe client)
        if ($this->netVibeClient) {
            $resp = $this->netVibeClient->postDiscoveryResults(['results' => $found]);
        } else {
            $resp = ['ok' => false, 'error' => 'NetVibeClient not initialized'];
        }

        return ['ok' => true, 'posted' => count($found), 'server_response' => $resp];
    }

    protected function fakePing(string $host): bool
    {
        // Very basic socket attempt
        $sock = @fsockopen($host, 80, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return true;
        }
        // fallback to check with gethostbyname
        $resolved = gethostbyname($host);
        return $resolved !== $host;
    }
}
