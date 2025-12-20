# Agent protocol (server ↔ agent)

## Autenticazione

- L'agent usa **Sanctum token** associato a un record `ScanAgent`.
- Lo ScanAgent è **sempre** legato a un `customer_id` (vietati agent globali).

## Tenant context

Tutte le route agent girano con middleware:

- `auth:sanctum`
- `SetTenantContextFromScanAgent`

Questo rende la tenancy deterministica in ingestion/discovery.

## Endpoints (as-is)

| Method | Path | Controller | Descrizione |
|---|---|---|---|
| GET | `/api/v1/agent/config` | `AgentConfigController@index` | Poll configurazione agent |
| GET | `/api/v1/agent/jobs` | `AgentJobController@index` | Poll job (HTTP polling) |
| POST | `/api/v1/agent/jobs/{job}/result` | `AgentJobController@storeResult` | Invio risultato job |
| POST | `/api/v1/agent/logs` | `AgentLogController@store` | Ingest log agent |
| POST | `/api/v1/discovery/results` | `DiscoveryIngestController@store` | Ingest risultati discovery |

## Error handling (fast-fail)

- 401 se non autenticato
- 403 se lo ScanAgent autenticato non ha `customer_id`
