Observability & tests

Logging e health
- Laravel logging lato server usa un canale JSON (`stderr_json`) come default; eccezioni API sono rese JSON quando l’header indica client API (Authorization/JSON). Gli eventuali debug routes legacy (`/_debug/*`) sono da considerarsi obsolete e rimosse in fase di hardening.
- Agent log ingest:
  - endpoint `/api/v1/agent/logs` salva in `agent_logs` (con `scan_agent_id` e `tenant_id` derivato da `ScanAgent.customer_id`);  
  - aggiorna sempre `last_seen` per l’agent;  
  - può triggerare alert se il livello ricevuto ≥ soglia `GeneralSettings.agent_alert_level` (configurabile da GeneralSettingsPage).  
- Remote agent logger:
  - scrive sempre su STDERR un record JSON strutturato `{timestamp, level, component, message, context}` (component almeno `AGENT_CORE` / `AGENT_MIKROTIK` ecc.);  
  - inoltra opzionalmente lo stesso record al server via `NetVibeHttpClient::postAgentLogs()` con payload `{level, message, context}`;  
  - la soglia minima per il mirror remoto è configurabile (LOG_LEVEL locale o override da `/api/v1/agent/config`).
- Heartbeat agent: last_seen aggiornato su config poll, job poll, log ingest, discovery ingest.
- Manutenzione: prune agent logs daily via console scheduler → PruneAgentLogsJob (usa DiscoverySettings::prune_logs_days).

Notifications testing:
- Webhook endpoint: tests must assert that POST JSON payloads follow the notification contract: `event_type`, `tenant_id`, `device_id`, `agent_id`, `job_id`, `timestamp`, `severity`, `payload`.
- Rate-limit tests: ensure rate-limiting per tenant + event_type via `rate_limit_seconds` is honoured.

Job Center
- Il Job Center (Resource `AgentJobResource`) è lo strumento principale per osservare lo stato dei job:
  - tabella `agent_jobs` con colonne type/scope/status/device/agent/customer/timestamps;  
  - filtri per `type`, `status`, `tenant_id`;  
  - azione `view` che apre uno slide-over read-only con `payload`, `result`, `result_metadata`, `error_message`;
  - il dettaglio job include anche i DiscoveryEvent correlati (`agent_job_id = job.id`) ordinati `created_at ASC`.
  - export CSV/XLSX tramite ExportAction/ExportBulkAction (per analisi esterne).

Test (Pest)
- Sicurezza: test rifiutati se `DB_DATABASE` ≠ `netvibe_test`; script `scripts/run-tests.sh` forza DB di test.
- Coperture chiave: IP math, Fping wrapper, device/model flows, link constraints, tenancy scope, IPAM UI (Subnets/IP relation manager), discovery ingest (API/full cycle), Mikrotik ingest actions, Filament v4 compliance/arch guard, remote agent stub.
- Gap noti: nessun e2e topologia UI, IngestMikrotikInventoryAction poco testata, sezione/migration mancante non coperta.

Health / Heartbeat tests:
- `WorkerHeartbeatJob`: test that it updates `system_state.last_worker_heartbeat_at` and runs on `low` queue.
- `SchedulerHeartbeatJob`: test that it updates `system_state.last_scheduler_run_at` and runs on `default` queue.
- `HealthCheckService`: unit tests for `getSystemHealthSummary()` covering worker/scheduler last_seen logic, agent online/offline using `agent_offline_threshold_seconds`, and queue backlog thresholds from `DiscoverySettings`.
