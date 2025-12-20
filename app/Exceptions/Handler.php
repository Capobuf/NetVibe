<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Psr\Log\LoggerInterface;
use Throwable;
use Illuminate\Auth\AuthenticationException;
use Livewire\Exceptions\RootTagMissingFromViewException;

class Handler extends ExceptionHandler implements ExceptionHandlerContract
{
    /**
     * Report or log an exception.
     */
    public function report(Throwable $e): void
    {
        parent::report($e);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // Defensive: if an unauthenticated redirect attempt triggers a missing
        // named route (Route [login] not defined) convert that into a
        // JSON 401 for API-like requests so clients (agents) don't receive
        // an HTML 500 page.
        if ($e instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException && str_contains($e->getMessage(), 'Route [login] not defined')) {
            // This RouteNotFoundException is emitted when the unauthenticated
            // handling attempts to redirect to a named 'login' route that
            // doesn't exist. Returning an HTML 500 for API clients is wrong —
            // convert this into a JSON 401 so agents and API callers get a
            // sensible response irrespective of headers.
            try {
                app(\Psr\Log\LoggerInterface::class)->warning('Converted missing `login` route RouteNotFoundException into JSON 401 for API-like request', [
                    'uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                    'accept' => $request->header('Accept'),
                    'has_authorization' => $request->headers->has('Authorization'),
                    'request_id' => $request->header('X-Request-Id'),
                ]);
            } catch (\Throwable $_) {
                // ignore logging issues
            }

            return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
        }
        if ($e instanceof RootTagMissingFromViewException) {
            // log helpful diagnostics to aid debugging: include request content so we can find the failing Livewire component
            try {
                $logger = app(LoggerInterface::class);
                $logger->error('[RootTagMissingFromViewException] Livewire root tag missing while rendering component', [
                    'uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                    'body' => $request->getContent(),
                    'headers' => $request->headers->all(),
                ]);
            } catch (Throwable $logEx) {
                // swallow any logging errors — we don't want to mask the original exception
            }
        }

        return parent::render($request, $e);
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * For API calls we must return a JSON 401 and avoid attempting redirects
     * that rely on the presence of a named 'login' route (which may not exist
     * in API-only deployments). This prevents the RouteNotFoundException -> 500
     * previously observed when agents polled without a token.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        // (no-op) unauthenticated override — keep diagnostics below but avoid
        // noisy debug-only logging here.
        // Capture some diagnostics for unauthenticated API requests so we can
        // trace failing tokens / agents without ever logging secrets.
        try {
            $authHeader = $request->headers->get('Authorization');
            $tokenId = null;
            $tokenMask = null;

            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $plain = substr($authHeader, 7);
                // Sanctum plainTextToken format is often "<id>|<random>", so
                // extract the id prefix if present and mask remainder for logs.
                if (str_contains($plain, '|')) {
                    [$id, $rest] = explode('|', $plain, 2);
                    $tokenId = $id;
                    $tokenMask = $id . '|'. (strlen($rest) > 8 ? substr($rest, 0, 4) . '...' . substr($rest, -4) : '***');
                } else {
                    $tokenMask = substr($plain, 0, 4) . '...' . substr($plain, -4);
                }
            }

            $logContext = [
                'path' => $request->path(),
                'ip' => $request->ip(),
                'method' => $request->method(),
                'request_id' => $request->header('X-Request-Id'),
            ];

            if ($tokenId !== null) {
                $logContext['token_id'] = $tokenId;
            }
            if ($tokenMask !== null) {
                $logContext['token_mask'] = $tokenMask;
            }

            // Try to resolve the agent id by a prefix token id if available
            if ($tokenId) {
                try {
                    $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($plain);
                    if ($pat) {
                        $logContext['token_db_id'] = $pat->id;
                        $logContext['tokenable_type'] = $pat->tokenable_type;
                        $logContext['tokenable_id'] = $pat->tokenable_id;
                    }
                } catch (\Throwable $_e) {
                    // best-effort only, don't break unauthenticated handling
                }
            }

            app(\Psr\Log\LoggerInterface::class)->warning('Unauthenticated API request', $logContext);
        } catch (\Throwable $_) {
            // ignore logging errors — we don't want to mask the original exception
        }

        // If client explicitly accepts JSON, path looks like an API call, OR
        // the request contains an Authorization header, treat as API and
        // return JSON 401. This guards against clients (or proxies) that
        // do not set Accept headers but still send bearer tokens.
            // Be tolerant: treat any request touching an API path or carrying
            // an Authorization header as API traffic. Use is('api/*') which
            // correctly handles various path forms and add a defensive
            // contains() check for proxies that might include the API
            // segment in other parts of the request URI.
            $isApiPath = $request->is('api/*') || str_contains($request->getRequestUri(), '/api/');

            // Record useful diagnostics so we can see whether the header
            // was present for the failing request (helps debug proxy issues).
            $diag = [
                'accept' => $request->header('Accept'),
                'has_authorization' => $request->headers->has('Authorization'),
            ];

            if (!empty($diag)) {
                app(\Psr\Log\LoggerInterface::class)->debug('Unauthenticated request diagnostics', $diag);
            }

            if ($request->expectsJson() || $isApiPath || $request->headers->has('Authorization')) {
                return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
            }

            // If the framework's default unauthenticated() attempts to redirect
            // to a named 'login' route that doesn't exist it will throw a
            // RouteNotFoundException. Catch that here and convert to JSON 401 so
            // API clients (including agents) never receive an HTML 500 page.
            try {
                return parent::unauthenticated($request, $exception);
            } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException $rnf) {
                if (str_contains($rnf->getMessage(), 'Route [login] not defined')) {
                    return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
                }

                // Re-throw if it's some other route-not-found issue
                throw $rnf;
            }
    }
}
