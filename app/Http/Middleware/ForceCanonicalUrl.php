<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force requests to the canonical APP_URL host/scheme when enabled.
 *
 * Enable by setting FORCE_CANONICAL_URL=true in your .env (recommended: only in production).
 */
final class ForceCanonicalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! filter_var((string) env('FORCE_CANONICAL_URL', false), FILTER_VALIDATE_BOOLEAN)) {
            return $next($request);
        }

        $appUrl = config('app.url') ?? env('APP_URL');

        if (empty($appUrl)) {
            return $next($request);
        }

        $parsed = parse_url($appUrl);

        $expectedHost = $parsed['host'] ?? null;
        $expectedScheme = $parsed['scheme'] ?? null;

        // Nothing to enforce
        if (empty($expectedHost)) {
            return $next($request);
        }

        $currentHost = $request->getHost();
        $currentScheme = $request->getScheme();

        // If host or scheme mismatch, redirect to canonical
        if ($currentHost !== $expectedHost || ($expectedScheme && $currentScheme !== $expectedScheme)) {
            $uri = $request->getRequestUri();
            $target = ($expectedScheme ? "$expectedScheme://" : '//') . $expectedHost . $uri;

            // Use permanent redirect - caller can cache / CDN can update
            return redirect()->to($target, 301);
        }

        return $next($request);
    }
}
