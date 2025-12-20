# Agent API (v1)

All endpoints are behind `auth:sanctum` and expect a **Bearer token** associated to a `ScanAgent` record.

Base path: `/api/v1`

## `GET /agent/config`
Returns current agent configuration and feature flags.

Notes:
- Server updates agent `last_seen` on every call.
- Response supports **ETag**; if client sends `If-None-Match` and config is unchanged, server returns `304`.

## `GET /agent/jobs`
Returns pending jobs assigned to the authenticated agent.

Response shape (high level):
- `agent_config`: agent settings blob (`scan_agents.settings`)
- `discovery_config`: global discovery settings snapshot
- `jobs`: list of pending `AgentJob` objects (includes `payload` and may include injected credentials)

## `POST /agent/jobs/{job}/result`
Submit job execution result.

Body fields:
- `status`: `completed|failed|timeout|success` (success is mapped to completed)
- `output`: string
- `result`: object (may include `device_result`)

Validation:
- if `result.device_result.schema_version` is missing/invalid -> `422`
- if schema major version is below `security.min_supported_schema_version` -> `426`

## `POST /discovery/results`
Ingest discovery results from agent.

Request body:
```json
{ "results": [ {"ip_addr":"192.0.2.10","mac_address":"...","hostname":"...","last_seen":"..."} ] }
```

Server enqueues `ProcessDiscoveryIngestJob` and returns `202`.

## `POST /agent/logs`
Ingest agent log lines.

Body:
- `level`: `DEBUG|INFO|WARNING|ERROR|CRITICAL`
- `message`: string
- `context`: optional object

Server stores logs in DB and may emit a Filament database notification depending on `GeneralSettings.agent_alert_level`.

