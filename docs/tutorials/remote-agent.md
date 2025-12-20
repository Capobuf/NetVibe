# Remote Agent (locale)

## 1) Genera un token per l’agent

Nella UI Filament:
1. Crea un record **ScanAgent**.
2. Apri l’edit e usa l’azione **Manage API Key** (genera un PAT).

## 2) Configura l’agent

Nel folder `remote-agent/`:

```bash
cp .env.example .env
nano .env
```

Variabili:
- `SERVER_URL` (base URL dell’app, senza `/api`)
- `AGENT_API_TOKEN` (bearer token)

## 3) Avvia l’agent

```bash
cd remote-agent
docker compose up -d
```

Il servizio si chiama `netvibe-remote-agent`.
