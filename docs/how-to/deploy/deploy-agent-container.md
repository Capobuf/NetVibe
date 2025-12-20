# Deploy agent container (per customer)

## Prerequisiti
- Un record **ScanAgent** creato nel server NetVibe e associato al **Customer**.
- Un token Sanctum (Bearer) associato allo ScanAgent.
- Connettività **outbound** dall'agent verso il server NetVibe.

## Configurazione (as-is nel repo)
Nel progetto è presente un agent "skeleton" in `remote-agent/`.

Variabili supportate (vedi `remote-agent/README.md`):
- `SERVER_URL`
- `AGENT_API_TOKEN`
- `LOG_LEVEL`
- `AGENT_POLL_INTERVAL_SECONDS`
- `AGENT_CONFIG_REFRESH_SECONDS`

## Esecuzione
Usa `remote-agent/docker-compose.yml` come base e avvia il servizio `netvibe-remote-agent`.

## Endpoint usati
Vedi `docs/reference/api/agent-protocol.md`.
