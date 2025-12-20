<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use App\Tenancy\TenantContextStore;

class TenantResolverService
{
    /**
     * Resolve the current tenant id in the context of a web request.
     *
     * UI (Filament): legge il tenant corrente dalla sessione.
     * API/Queue: usa TenantContextStore (set esplicitamente da middleware/job).
     */
    public function currentTenantId(?Request $request = null): ?int
    {
        // Prefer the runtime tenant context when present (API / queue / long running).
        // Fallback to HTTP session for the admin panel.
        $store = app(TenantContextStore::class);
        $id = $store->currentCustomerId();

        if ($id !== null) {
            return (int) $id;
        }

        // Backward compatible: web UI still uses the session.
        $sessionId = session('tenant_id');
        return $sessionId !== null ? (int) $sessionId : null;
    }
}

