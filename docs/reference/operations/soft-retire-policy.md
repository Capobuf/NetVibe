# Soft-retire policy (lifecycle)

## Obiettivo
Gestire oggetti non più trovati dalla discovery senza cancellazioni automatiche.

## Campi
Su `ip_addresses`, `devices`, `ports`:

- `lifecycle_status` (Active/Stale/Retired)
- `stale_at`
- `retired_at`

## Settings
In `DiscoverySettings`:

- `inventory_soft_retire_enabled`
- `inventory_stale_after_days` (default 15)
- `inventory_retire_after_days` (default 45, deve essere >= stale)

## Job pianificato
- `ApplySoftRetirePolicyJob` è schedulato daily in `routes/console.php`.

## UI

Le liste IP/Device/Port mostrano un badge **Lifecycle** con filtro e bulk action **Restore (Active)**.

Quando un oggetto viene visto durante ingest/scan, viene riportato automaticamente ad **Active** (reset di stale/retired).

