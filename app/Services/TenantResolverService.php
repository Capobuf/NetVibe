<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

class TenantResolverService
{
    /**
     * Resolve the current tenant id in the context of a web request.
     *
     * Fase 1: usa session('tenant_id') come unica fonte. In futuro potrà
     * integrare header, token o altre fonti senza toccare modello e scope.
     */
    public function currentTenantId(?Request $request = null): ?int
    {
        // Per ora il tenant è sempre determinato dalla sessione HTTP.
        // Se servisse in futuro, potremo leggere anche da header o token.
        $id = session('tenant_id');

        return $id !== null ? (int) $id : null;
    }
}

