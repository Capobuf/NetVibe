# 0004 - Tenant context resolution

**Status:** Accepted

## Context
NetVibe è multi-tenant (MSP). Gli spazi IP possono sovrapporsi tra clienti. La tenancy basata solo su sessione UI non copre API/queue e porta a rischio cross-tenant.

## Decision
Introdurre un contesto tenant runtime esplicito e fail-fast:

- Value object: `app/Tenancy/TenantContext.php`
- Store per request/job: `app/Tenancy/TenantContextStore.php`
- Middleware per API agent imposta tenant dal `ScanAgent` autenticato: `app/Http/Middleware/SetTenantContextFromScanAgent.php`
- `TenantResolverService` preferisce `TenantContextStore` e fa fallback alla sessione solo per UI.

Se il contesto tenant manca in path ingestion/discovery → errore esplicito (fast-fail).

## Consequences
- API/queue operano con tenancy deterministica.
- Query customer-scoped possono affidarsi allo scope solo se `TenantContextStore` è stato impostato.
- Non esiste "agent globale" (scan_agent senza customer).
