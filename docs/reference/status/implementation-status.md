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
| Discovery | SNMP settings UI su Device | UI sola (persistente) | Campi Filament salvano `snmp_settings` ma i job SNMP non sono ancora integrati. |
| DCIM | Management IP su Device | Usa `ip_addr` (campo unico) | `management_ip` non è esposto in UI; `ip_addr` è usato come indirizzo di management. |
| DCIM | VLAN ingest (Mikrotik) | ✅ via bridges REST (fallback bridge_ports/vlan) | `device_result.bridges.items` è la fonte primaria per PVID/tagged per bridge/porta; se mancante, usa `bridge_ports`/`bridge_vlan` REST. |
| IPAM | Subnet ingest da Mikrotik | Parziale | Subnet auto-create quando l’agent invia `ip_addresses.items` (`/rest/ip/address`); prefisso usato invece di /24 di fallback; flag `discover_subnet`/`ping_subnet`/`resolve_dns` applicati solo se le colonne esistono (niente errori su schemi ridotti); se già esistono (anche soft-deleted) vengono ripristinate/aggiornate anziché reinserite. |
| IPAM | VLAN↔Subnet binding | ✅ | VLAN derivata da PVID o nome interfaccia (es. `vlan60`, `VLAN_835`), creata se mancante; la subnet generata/agganciata porta `vlan_id` coerente. |
| Discovery | DiscoveryEvent per MAC/IP non mappati | Parziale | Gli eventi vengono creati solo se `device.scan_agent_id` e `customer_id` sono valorizzati; in caso contrario il mapping continua ma l’evento è saltato (evita eccezioni runtime). |
| Blacklist | VLAN blacklist per `vlan_number` (hard exclusion) | ✅ | migrations blacklist + `VlanBlacklist` |
| Lifecycle | Soft-retire policy (stale/retired) | ✅ | `app/Jobs/ApplySoftRetirePolicyJob.php`, `DiscoverySettings` |
| UI | Lifecycle badge + filtri + restore | ✅ | Filament resources IP/Device/Port |
| Ops | Tenant integrity audit (fast-fail) | ✅ | `php artisan tenant:audit`, `app/Console/Commands/TenantIntegrityAudit.php` |
| Security | TLS verify configurabile (agent↔server, agent↔mikrotik) | ✅ | `remote-agent/src/Http/NetVibeHttpClient.php`, `remote-agent/src/Drivers/MikrotikApiDriver.php` |
