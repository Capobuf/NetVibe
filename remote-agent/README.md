# NetVibe Remote Agent (skeleton)

This folder contains a minimal, simulated skeleton of a remote agent (Laravel Zero-like CLI) used in Phase 3.

It includes:
- a basic CLI command `agent:work` that polls the control server for config and jobs
- a small server/client `MikrotikClient` wrapper used to talk to the control server and to target Mikrotik devices
- job handlers:
	- `handleCheckConnectivity` — attempts to call Mikrotik REST endpoints `/rest/system/resource` and `/rest/system/identity` using provided credentials
	- `handleDiscoveryDeep` — will fallback to legacy `fping`-based discovery when `cidr` or `legacy_fping` is present in payload and send results back to the server `/api/v1/discovery/results`
- Dockerfile and docker-compose example for running the agent

- Notes / runtime configuration
- Environment variables supported in `docker-compose.yml` / container runtime:
		- `SERVER_URL` or `AGENT_SERVER_URL` — the API server base URL (default: `http://host.docker.internal:9693`).
			The agent also supports `IPAM_AGENT_API_URL` for backwards compatibility; if this contains a full `/api/v1` path the agent will normalize and use the base host:port.
	- `AGENT_API_TOKEN` — bearer token for the agent to authenticate against the API
	- `IPAM_AGENT_API_KEY` — alternate env var name accepted for backwards compatibility (the agent will also read this if `AGENT_API_TOKEN` isn't set)

Docker compose and .env
- `remote-agent/docker-compose.yml` now reads `remote-agent/.env` using `env_file: .env`.
	- Place your `IPAM_AGENT_API_URL` and `IPAM_AGENT_API_KEY` (or `AGENT_API_TOKEN`) into `remote-agent/.env` (use https:// for the server URL), or set corresponding host environment variables when starting the container.
	- If you previously started the agent from the project root without passing the file, compose will not automatically load `remote-agent/.env` unless you invoke `docker compose` from the `remote-agent` dir or run `docker compose -f remote-agent/docker-compose.yml --env-file remote-agent/.env up`.
	- Important: avoid relying on variable substitution (${VAR}) inside `docker-compose.yml` for values coming from `remote-agent/.env` — Compose performs variable substitution from the shell / .env in the working directory, not the `env_file` entries. This is why `AGENT_SERVER_URL` or `AGENT_API_TOKEN` could appear as "not set" when docker compose was executed from a different directory.
	- `LOG_LEVEL` — optional (info|debug|warning)

Security & behaviour
- The `check_connectivity` job uses credentials in the job payload to attempt direct REST calls to the target device. It returns structured results or error codes like `AUTH_ERROR` or network `NET_TIMEOUT`.
- The legacy discovery handler will run `fping` and post results to the server's discovery ingest endpoint in the expected format.

This skeleton is intended for development and local verification; for production you should migrate it to a tested worker framework (Laravel Zero/Go/Rust), add proper retries, secrets handling and stronger authentication.
