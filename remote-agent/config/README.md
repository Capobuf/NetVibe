## remote-agent config/

Questa directory è riservata per eventuali file di configurazione locale dell’agent (preset, mapping vendor, ecc.), come previsto dal PDL.

Al momento non viene usata dal codice: la configurazione runtime principale arriva da `/api/v1/agent/config` e dalle variabili d’ambiente (`SERVER_URL`, `AGENT_API_TOKEN`, `LOG_LEVEL`, `AGENT_POLL_INTERVAL_SECONDS`, `AGENT_CONFIG_REFRESH_SECONDS`).

