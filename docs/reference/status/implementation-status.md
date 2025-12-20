# Implementation status

Questa pagina descrive lo **stato attuale del codice** (as-is). Non è una roadmap.

## Epiche

| Epic | Stato nel codice | Note |
|---|---|---|
| EPIC-001 Tenancy & Ingestion Hardening | Implementato (Step 1–12) | Modifiche applicate nel repository; non validate in runtime in questa consegna. |

## Feature matrix (as-is)

| Area | Feature | Stato | File/entry point |
|---|---|---|---|
| Tenancy | TenantContext runtime (API/queue/web) | ✅ | `app/Tenancy/*`, `app/Services/TenantResolverService.php` |
| Tenancy | Middleware API agent imposta tenant (fast-fail) | ✅ | `app/Http/Middleware/SetTenantContextFromScanAgent.php`, `routes/api.php` |
| Tenancy | Agent globale (customer_id NULL) consentito | ❌ (vietato) | `scan_agents.customer_id` enforced NOT NULL (migration) |
| Ingestion | Query IP scoperte per customer | ✅ | `app/Actions/Discovery/IngestScanResultsAction.php`, Mikrotik ingest actions |
| Discovery | `discovery_events` tenant-aware (`customer_id`) | ✅ | `app/Models/DiscoveryEvent.php`, migration `add_customer_id...` |
| Blacklist | VLAN blacklist per `vlan_number` (hard exclusion) | ✅ | migrations blacklist + `VlanBlacklist` |
| Lifecycle | Soft-retire policy (stale/retired) | ✅ | `app/Jobs/ApplySoftRetirePolicyJob.php`, `DiscoverySettings` |
| UI | Lifecycle badge + filtri + restore | ✅ | Filament resources IP/Device/Port |
| Ops | Tenant integrity audit (fast-fail) | ✅ | `php artisan tenant:audit`, `app/Console/Commands/TenantIntegrityAudit.php` |
| Security | TLS verify configurabile (agent↔server, agent↔mikrotik) | ✅ | `remote-agent/src/Http/NetVibeHttpClient.php`, `remote-agent/src/Drivers/MikrotikApiDriver.php` |

