# 0006 - Soft-retire lifecycle (stale/retired)

**Status:** Accepted

## Context
La discovery può non rilevare più un oggetto per motivi temporanei (down, rete, credenziali). La cancellazione automatica è rischiosa.

## Decision
Introdurre un lifecycle separato dallo status funzionale:

- Campi: `lifecycle_status`, `stale_at`, `retired_at` su `ip_addresses`, `devices`, `ports`.
- Job pianificato: `ApplySoftRetirePolicyJob` che marca `stale` e `retired` in base a soglie configurabili.
- Default configurabili in `DiscoverySettings` (abilitazione, giorni stale/retire).
- Quando un oggetto viene visto in ingest: ritorna `Active` (reset stale/retired).

## Consequences
- Nessun delete automatico.
- Operatori possono filtrare e ripristinare oggetti (UI Restore).
