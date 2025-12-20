<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ScanAgent;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures agent API routes run with a tenant context derived from the authenticated ScanAgent.
 *
 * Fast-fail: agent tokens without customer_id are rejected.
 */
final class SetTenantContextFromScanAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var mixed $principal */
        $principal = $request->user();

        if (! $principal instanceof ScanAgent) {
            return response()->json(['message' => 'Unauthenticated or invalid agent'], 401);
        }

        if (blank($principal->customer_id)) {
            return response()->json([
                'message' => 'ScanAgent must be bound to a customer (customer_id is required).',
            ], 403);
        }

        $store = app(TenantContextStore::class);

        $store->set(new TenantContext(
            customerId: (int) $principal->customer_id,
            locationId: null,
            actorType: 'agent',
            actorId: (string) $principal->getKey(),
        ));

        try {
            return $next($request);
        } finally {
            // Important for long-running runtimes (e.g. Octane) and test isolation.
            $store->clear();
        }
    }
}
