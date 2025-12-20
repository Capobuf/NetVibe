# ADR 0003: Server–agent communication via HTTP polling

- Status: **Accepted**
- Date: 2025-12-14

## Context

NetVibe includes a remote agent (`remote-agent/`) that communicates with the control server.

Current server endpoints are implemented as HTTP API routes under `/api/v1/...` protected by `auth:sanctum`.

A proposal was raised to switch server–agent communication from HTTP polling to WebSocket.

## Decision

We keep **HTTP polling** for agent communication for now.

- Agent polls:
  - `GET /api/v1/agent/config` (with ETag support)
  - `GET /api/v1/agent/jobs`
- Agent posts:
  - `POST /api/v1/agent/jobs/{job}/result`
  - `POST /api/v1/discovery/results`
  - `POST /api/v1/agent/logs`

No WebSocket transport is introduced in Phase 1.

## Rationale

- Simpler deployment and operations (no long-lived WS connections, no sticky routing, no additional WS infra).
- Better compatibility with NAT/firewalls (outbound HTTPS requests).
- Reliability can be handled with idempotency and retries at HTTP level.
- The current code already implements ETag for config and structured job/result endpoints.

## Consequences

- Latency is bounded by polling interval.
- If required later, we may evolve to **long-polling** first, then WebSocket only if strong real-time requirements emerge.

