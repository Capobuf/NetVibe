# Agent communication

Stato attuale: **HTTP polling** (API) con token Sanctum.

Motivazioni principali:
- semplicità operativa (firewall/NAT friendly)
- idempotenza e retry più semplici
- meno complessità rispetto a WebSocket (keepalive, reconnect, infra)

Endpoint principali:
- `GET /api/v1/agent/config` (ETag)
- `GET /api/v1/agent/jobs`
- `POST /api/v1/agent/jobs/{job}/result`
- `POST /api/v1/discovery/results`
- `POST /api/v1/agent/logs`
