# EPIC-001 — Tenancy & Ingestion Hardening

**Status:** Implementato nel codice (non eseguito in runtime in questa consegna)

## Obiettivo
Garantire che ogni operazione su entità customer-scoped sia **sempre** vincolata al `customer_id` corretto (UI/API/queue) e rendere ingestion/discovery deterministica in presenza di IP space sovrapponibile tra clienti.

## Root causes (da eliminare)
- Tenancy implicita/legata alla sessione UI.
- Lookup su chiavi non globalmente univoche (IP/MAC/VLAN) senza `customer_id`.
- Assenza di constraint DB a impedire regressioni.

## Acceptance criteria (sintesi)
- Nessun path ingestion/discovery opera senza contesto tenant esplicito (fail-fast).
- Query deterministiche e scoping per customer su IP/MAC/VLAN.
- Blacklist hard (non importare) e gestione da UI.
- Soft-retire configurabile (stale/retired) senza delete automatico.

## Implementazione (as-is nel codice)

### Step 1 — TenantContext (API/queue/web)
- `app/Tenancy/TenantContext.php`
- `app/Tenancy/TenantContextStore.php`
- Middleware: `app/Http/Middleware/SetTenantContextFromScanAgent.php`

### Step 2 — Refactor ingestion queries (tenant-scoped)
- `app/Actions/Discovery/IngestScanResultsAction.php`
- `app/Actions/Agent/IngestMikrotikArpAction.php`
- `app/Actions/Agent/IngestMikrotikTopologyAction.php`

### Step 3 — DiscoveryEvent tenant-aware
- `discovery_events.customer_id` (migration)
- `app/Models/DiscoveryEvent.php` + tenancy scope

### Step 4 — VLAN/Subnet blacklist hard
- `vlan_blacklists (customer_id, vlan_number)`
- Filament resources blacklist (IPAM)

### Step 5 — Eliminazione agent globali
- `scan_agents.customer_id` NOT NULL (migration)
- Rimozione di fallback su agent globali

### Step 6 — Constraints DB customer-scoped
- Migration hardening (fast-fail) su NOT NULL, index e unique per customer

### Step 7 — Guard-rails + test isolamento
- Invariante `IpAddress.customer_id == Subnet.customer_id`
- Test multi-tenant su overlap IP

### Step 8 — Soft-retire policy
- Campi lifecycle su `ip_addresses/devices/ports`
- Job `ApplySoftRetirePolicyJob` + settings

### Step 9 — UI lifecycle
- Badge/filtri/restore su IP/Device/Port in Filament

### Step 10 — Allineamento documentazione & ADR
- `docs/reference/status/implementation-status.md`
- nuovi ADR 0004–0007

## ADR correlati
- `docs/adr/0004-tenant-context-resolution.md`
- `docs/adr/0005-customer-scoped-constraints.md`
- `docs/adr/0006-soft-retire-lifecycle.md`
- `docs/adr/0007-blacklist-hard-exclusions.md`
