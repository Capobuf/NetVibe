<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Outbound HTTPS guard shared by the read-only integration clients. Each client passes its own
 * exception class (constructed from a status string) so callers keep their own messages.
 */
class SafeHttp
{
    public const STATUSES = [401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 429 => 'rate_limit'];

    /** Returns `https://host[:port][/path]`, or null when the URL could reach a forbidden destination. */
    public static function normalizeUrl(string $url, bool $allowPath = false): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (! $allowPath && ! in_array($parts['path'] ?? '', ['', '/'], true)) || preg_match('/[\s\\\\%]/', $url)) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || $host === 'metadata.google.internal' || preg_match('/\A0x[0-9a-f]+\z/i', $host)
            || ($ip && ! self::allowedAddress($host))
            || (! $ip && preg_match('/\A[0-9.]+\z/', $host))) {
            return null;
        }
        $path = $allowPath ? rtrim($parts['path'] ?? '', '/') : '';
        if (preg_match('~/api(?:/|$)~i', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            return null;
        }
        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return 'https://'.$host.($port === 443 ? '' : ':'.$port).$path;
    }

    public static function allowedAddress(string $address): bool
    {
        // Azure's WireServer is a public address that reaches host platform services.
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $address === '168.63.129.16') {
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

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false && $ip < ip2long('224.0.0.0');
    }

    /**
     * A JSON GET client without proxy or redirects, pinned to the validated address so DNS cannot
     * move credentials to a forbidden destination, aborting responses larger than $limit bytes.
     *
     * @param  class-string<\RuntimeException>  $exception
     */
    public static function request(string $url, string $address, int $limit, string $exception): PendingRequest
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? 443;

        return Http::acceptJson()->withUserAgent('NetVibe')->withoutRedirecting()->withOptions([
            'proxy' => '', 'force_ip_resolve' => 'v4',
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]],
            'on_headers' => function (ResponseInterface $response) use ($limit, $exception): void {
                if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                    throw new $exception('limit');
                }
            },
            'progress' => function ($total, $downloaded) use ($limit, $exception): void {
                if ($downloaded > $limit) {
                    throw new $exception('limit');
                }
            },
        ]);
    }

    /**
     * Rethrows the client's own exception raised inside a transfer callback, so upstream
     * exceptions (which can contain credentials) never surface.
     *
     * @param  class-string<\RuntimeException>  $exception
     */
    public static function rethrowOwn(Throwable $error, mixed $handlerError, string $exception): void
    {
        if ($handlerError instanceof $exception) {
            throw $handlerError;
        }
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof $exception) {
                throw $cause;
            }
        }
    }

    /** Maps a cURL error code to a transport status. */
    public static function transportStatus(mixed $handlerError): string
    {
        return match ($handlerError) {
            28 => 'timeout',
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 => 'tls',
            default => 'network',
        };
    }

    /** @param  array<int, string>  $statuses */
    public static function failure(Response $response, array $statuses = self::STATUSES): ?string
    {
        return $statuses[$response->status()] ?? match (true) {
            $response->redirect() => 'redirect',
            ! $response->successful() => 'http_error',
            default => null,
        };
    }
}
