# Tenancy (customer scoping)

## Concetti

- **Customer**: tenant principale (isolamento dati).
- **Location**: sede del customer (opzionale; in Fase 1 il MikroTik/connector è legato a una Location).
- **Entità globali**: condivise tra tenant (es. Manufacturer, DeviceModel...).
- **Entità customer-scoped**: devono avere `customer_id` (Subnet, VLAN, IP, Device, Rack, Port, Cable, Circuit...).

## Risoluzione del tenant (as-is)

### Web/UI (Filament)
- Fallback alla sessione per selezione customer (solo UI).

### API agent
- Autenticazione: `auth:sanctum`.
- Middleware `SetTenantContextFromScanAgent` deriva `customer_id` dallo **ScanAgent** autenticato.
- Se `ScanAgent.customer_id` manca → **403 (fast-fail)**.

### Queue/Jobs
- I job di ingest/scan impostano `TenantContextStore` all'inizio di `handle()` e lo puliscono in `finally`.

## API surface rilevante
Vedi: `docs/reference/api/agent-protocol.md`.
