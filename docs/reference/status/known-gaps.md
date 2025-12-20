# Known gaps & technical debt (as-is)

Questa pagina elenca **problemi noti nel codice**. Ogni voce dovrebbe puntare a un'Epic o a un ticket.

## Risolti recentemente

- ✅ Tenancy basata solo su sessione UI → risolta con EPIC-001 (TenantContext runtime).
- ✅ DiscoveryEvent/merge potenzialmente cross-tenant → risolta con EPIC-001 (`discovery_events.customer_id`).

## Sicurezza / affidabilità

- ❗ SSL verify disabilitato nel remote agent (`remote-agent/src/Drivers/MikrotikApiDriver.php`, `remote-agent/src/Http/NetVibeHttpClient.php`).
- ❗ Endpoint debug attivi (`routes/web.php` sotto `/_debug/*`).
- ⚠️ Loop remote-agent con backoff minimale (polling) — valutare policy più robusta.

## Consistenza dati / UI

- ⚠️ `tagged_vlans` formato incoerente (string vs array) tra ingest e UI.
- ⚠️ Risorse Filament duplicate per Device (Racks vs Infrastructure) — rischio divergenza UX.

