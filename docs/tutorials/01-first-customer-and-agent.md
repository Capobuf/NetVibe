# Tutorial 01 — Primo customer e primo agent

## Obiettivo
Creare un customer, registrare uno ScanAgent e verificare che l'agent operi nel tenant corretto.

## Passi
1. Crea un **Customer** (Filament → Organization → Customers).
2. Crea uno **ScanAgent** associandolo al customer.
3. Genera o assegna un token Sanctum per lo ScanAgent.
4. Avvia l'agent container con `SERVER_URL` e `AGENT_API_TOKEN` (vedi `docs/how-to/deploy/deploy-agent-container.md`).
5. Verifica che l'agent riesca a leggere `/api/v1/agent/config` e `/api/v1/agent/jobs` (vedi `docs/reference/api/agent-protocol.md`).
