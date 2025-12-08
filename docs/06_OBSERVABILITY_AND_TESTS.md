Observability & tests

Logging e health
- Laravel logging di base; eccezioni API rese JSON quando header Authorization/JSON (bootstrap/app.php). Debug routes ancora presenti (`/_debug/*`).
- Agent log ingest: `/api/v1/agent/logs` salva in `agent_logs`, aggiorna last_seen, trigger alert se livello ≥ soglia GeneralSettings (`agent_alert_level`). Config da GeneralSettingsPage.
- Remote agent logger: STDERR locale, mirror verso server via NetVibeHttpClient sink con soglia configurabile (Logger + AgentWorkCommand).
- Heartbeat agent: last_seen aggiornato su config poll, job poll, log ingest, discovery ingest.
- Manutenzione: prune agent logs daily via console scheduler → PruneAgentLogsJob (usa DiscoverySettings::prune_logs_days).

Test (Pest)
- Sicurezza: test rifiutati se `DB_DATABASE` ≠ `netvibe-test`; script `scripts/run-tests.sh` forza DB di test.
- Coperture chiave: IP math, Fping wrapper, device/model flows, link constraints, tenancy scope, IPAM UI (Subnets/IP relation manager), discovery ingest (API/full cycle), Mikrotik ingest actions, Filament v4 compliance/arch guard, remote agent stub.
- Gap noti: nessun e2e topologia UI, IngestMikrotikInventoryAction poco testata, sezione/migration mancante non coperta.
