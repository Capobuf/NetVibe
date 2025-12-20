# NetVibe Remote Agent (skeleton)

This folder contains a minimal, simulated skeleton of a remote agent (Laravel Zero-like CLI) used in Phase 3.

It includes:
- a basic CLI command `agent:work` that polls the control server for config and jobs
- a small server/client `MikrotikClient` wrapper used to talk to the control server and to target Mikrotik devices
- job handlers:
	- `handleCheckConnectivity` — attempts to call Mikrotik REST endpoints `/rest/system/resource` and `/rest/system/identity` using provided credentials
	- `handleDiscoveryDeep` — esegue discovery REST Mikrotik e costruisce il `device_result` completo; in presenza di `cidr` o `legacy_fping` può ancora richiamare il percorso di compatibilità `handleLegacyConfig`.
- Dockerfile e `remote-agent/docker-compose.yml` di esempio per eseguire l’agent.

Notes / runtime configuration

- Environment variables supportate nel container netvibe-remote-agent (contratto PDL):
	- `SERVER_URL` — base URL dell’app NetVibe (es. `http://ipam:9693`), **senza** `/api` o `/api/v1`; l’agent aggiunge sempre `/api/v1/...` autonomamente.  
	- `AGENT_API_TOKEN` — bearer token (PAT) per autenticare l’agent verso l’API.  
	- `LOG_LEVEL` — livello log (`debug|info|warning|error|critical`), default `info` se non impostato.  
	- `AGENT_POLL_INTERVAL_SECONDS` — intervallo di polling jobs (default 5).  
	- `AGENT_CONFIG_REFRESH_SECONDS` — intervallo di polling `/api/v1/agent/config` (default 60).  
	- `NETVIBE_TLS_VERIFY` — verifica TLS quando l’agent chiama il server NetVibe (`true|false`, default `true`).
	- `NETVIBE_TLS_CA_BUNDLE` — percorso opzionale a CA bundle (usato quando `NETVIBE_TLS_VERIFY=true`).
	- `MIKROTIK_TLS_VERIFY` — verifica TLS quando si chiama l’API REST MikroTik (`true|false`, default `false`).
	- `MIKROTIK_TLS_CA_BUNDLE` — percorso opzionale a CA bundle per MikroTik (usato quando `MIKROTIK_TLS_VERIFY=true`).

Docker compose e .env

- Il file `remote-agent/.env.example` contiene un esempio di configurazione con le variabili sopra (inclusi i flag TLS).  
- Il file `remote-agent/docker-compose.yml` espone il servizio:
	- `netvibe-remote-agent` con:
		- `SERVER_URL: ${SERVER_URL}`
		- `AGENT_API_TOKEN: ${AGENT_API_TOKEN}`
		- `LOG_LEVEL: ${LOG_LEVEL:-info}`
		- `AGENT_POLL_INTERVAL_SECONDS: ${AGENT_POLL_INTERVAL_SECONDS:-5}`
		- `AGENT_CONFIG_REFRESH_SECONDS: ${AGENT_CONFIG_REFRESH_SECONDS:-60}`
	- volume `./:/app` e comando `php /app/agent.php`.

Security & behaviour
- The `check_connectivity` job uses credentials in the job payload to attempt direct REST calls to the target device. It returns structured results or error codes like `AUTH_ERROR` or network `NET_TIMEOUT`.
- The legacy discovery handler will run `fping` and post results to the server's discovery ingest endpoint in the expected format.

This skeleton is intended for development and local verification; for production you should migrate it to a tested worker framework (Laravel Zero/Go/Rust), add proper retries, secrets handling and stronger authentication.
