# IPAM simplification worklog

## Interventi

- Rimossi `Section` e `strict mode` dal dominio IPAM: eliminato il model dedicato, la FK `Subnet.section`, la validazione di overlap condizionale e tutti i riferimenti admin/UI collegati.
- Convertiti i campi temporali di IPAM da secondi a minuti nel dominio dati:
  `warning_after_minutes`, `offline_after_minutes`, `default_scan_interval_minutes`,
  `scan_interval_minutes`, `warning_after_minutes_override`,
  `offline_after_minutes_override`.
- Lasciato `ping_timeout_ms` in millisecondi, perché in `ipam/tasks.py` viene passato al timeout di `subprocess.run()` e la conversione a secondi resta solo al bordo di chiamata.
- Semplificata la UI admin di subnet:
  `VLAN` spostata in testa alla form, rimosso il layer overview/edit separato, unificati discovery e scheduling in una sola sezione `Scanning`.
- Ridotto il rumore UI in Unfold:
  rimossa la configurazione `UNFOLD["TABS"]`, rimossa la voce sidebar `Sections`, eliminati i template custom inutilizzati della subnet.
- Corretto il doppio header di `IPAM settings` azzerando il `subtitle` nel change form del singleton admin.
- Resettate le migrazioni `ipam` e rigenerata una baseline pulita (`ipam/migrations/0001_initial.py`).
- Ripristinata la vista overview della subnet come destinazione di default al click, mantenendo l’edit separato via azione dedicata e senza reintrodurre i tab globali.
- Chiarita la differenza UI tra scansione e discovery con label/help text admin piu espliciti.
- Esteso il worker di scansione per recuperare e salvare i MAC address dalla neighbor table (`ip neigh`) dopo il ping, quando disponibili.
- Esteso il worker di scansione per recuperare e salvare anche l’hostname via reverse DNS (`socket.gethostbyaddr`) per gli host raggiungibili.
- Aggiornato `docker-compose.yml` per eseguire il worker in `host` networking, cosi puo operare direttamente sulla LAN `10.0.0.0/24`; PostgreSQL resta esposto solo su `127.0.0.1:5432` per supportare il worker senza esporre il DB in LAN.
- Separata la colonna `Hostname` nella tabella IP dell’overview subnet, invece di mostrarla come testo secondario sotto l’indirizzo IP.

## File principali toccati

- `ipam/models.py`
- `ipam/admin.py`
- `ipam/tasks.py`
- `ipam/tests.py`
- `config/settings.py`
- `ipam/migrations/0001_initial.py`
- `locale/it/LC_MESSAGES/django.po`

## Motivazioni basate sul codice

- `Section` era ancora strutturale in `ipam/models.py` e propagava complessità in `Subnet`, nei filtri job e nel menu Unfold, ma non serviva ad altro oltre alla validazione `strict_mode`.
- I tab rumorosi erano generati da due layer diversi:
  `classes=("tab",)` nei fieldset admin e `UNFOLD["TABS"]` in `config/settings.py`.
  In particolare i tab subnet `Child Subnets` e `Subnet IPs` erano vuoti perché `SubnetAdmin.get_inlines()` restituiva sempre `()`.
- La duplicazione visiva di `IPAM settings` derivava dal singleton change form: Django popola `title` e `subtitle`, e `IPAMSettings.__str__()` restituiva lo stesso testo del verbose name.
- La pianificazione delle scansioni usava davvero i campi in secondi in `ipam/tasks.py` (`timedelta(seconds=...)`), quindi la conversione a minuti richiedeva l’aggiornamento del dominio e del worker, non solo delle label admin.

## Trade-off

- Il reset schema elimina completamente la storia delle migrazioni precedenti, coerentemente con il vincolo greenfield.
- La form subnet ora è lineare e senza tab; si perde la vista “overview” precedente, ma si elimina un flusso duplicato che obbligava a passare tra `view` e `edit`.
- Non ho ricreato seed o bootstrap admin: nella repo non risultano fixture, command o codice di provisioning per utenti iniziali.

---

## MikroTik integration – Milestone 1

### Scope

End-to-end integration with MikroTik RouterOS v7 via REST API only.
Supports: device onboarding, connection test, periodic sync, VLAN/subnet/IP import (DHCP + ARP enrichment), neighbor observation, per-object sync control.

### Changes

- Added `httpx` and `pydantic` to `requirements.txt`.
- Created `ipam/crypto.py` — pgcrypto encrypt/decrypt helpers using `pgp_sym_encrypt`/`pgp_sym_decrypt`. Key via `NETVIBE_PGCRYPTO_KEY` env var.
- Created migration `0002_enable_pgcrypto.py` — enables the `pgcrypto` PostgreSQL extension.
- Added `MikroTikDevice` model — stores connection details (host, port, scheme, username, encrypted password), import policy toggles, sync interval, authority flags, and status fields populated by test/sync.
- Added `MikroTikSyncRun` model — one row per test or sync execution, with mode, status, timing, counters, warnings, and diagnostics.
- Added provenance fields to `VLAN`, `Subnet`, `IPAddress` — `source_kind`, `source_device`, `source_remote_id`, `source_job_id`, `source_trace_id`, `last_synced_at`, `sync_state`, `integration_sync_enabled`.
- Added lease fields to `IPAddress` — `lease_kind`, `lease_status`, `lease_server_name`.
- Created migration `0003_mikrotik_integration.py` — all new models and fields.
- Created `ipam/contracts.py` — Pydantic DTOs for `icmp.scan.result.v1`, `mikrotik.snapshot.result.v1`, `mikrotik.sync.apply.result.v1`.
- Created `ipam/integrations/mikrotik_client.py` — httpx-based REST client with bounded timeouts, explicit TLS, error hierarchy.
- Created `ipam/integrations/mikrotik_mapper.py` — maps raw RouterOS JSON to Pydantic DTOs, handles bool strings and hyphenated keys.
- Created `ipam/integrations/mikrotik_sync.py` — sync engine with `run_device_test` (metadata population), `run_device_sync` (full IPAM upsert), VLAN/subnet/DHCP/ARP sync rules.
- Created `ipam/forms.py` — `MikroTikDeviceForm` with write-only password field, encryption on save, blank-means-keep-existing.
- Updated `ipam/admin.py` — `MikroTikDeviceAdmin` (Save and test, Sync now actions), `MikroTikSyncRunAdmin`, proxy `MikroTikVLAN`/`MikroTikSubnet` admins for Integrations sidebar.
- Updated `config/settings.py` — added Integrations sidebar group.
- Updated `ipam/tasks.py` — MikroTik scheduling helpers (`schedule_mikrotik_sync_now`, `schedule_next_mikrotik_sync`) and `run_mikrotik_sync` task.
- Updated `ipam/tests.py` — comprehensive tests for pgcrypto, contracts, models, client, mapper, sync, scheduling, proxy admin, and regression.
- Updated `.env.example` and `.env` — added `NETVIBE_PGCRYPTO_KEY`.

### Files added

- `ipam/crypto.py`
- `ipam/contracts.py`
- `ipam/forms.py`
- `ipam/integrations/__init__.py`
- `ipam/integrations/mikrotik_client.py`
- `ipam/integrations/mikrotik_mapper.py`
- `ipam/integrations/mikrotik_sync.py`
- `ipam/migrations/0002_enable_pgcrypto.py`
- `ipam/migrations/0003_mikrotik_integration.py`

### Files modified

- `ipam/models.py`
- `ipam/admin.py`
- `ipam/tasks.py`
- `ipam/tests.py`
- `config/settings.py`
- `requirements.txt`
- `.env.example`
- `.env`

### Design decisions

- Credentials use pgcrypto `pgp_sym_encrypt`/`pgp_sym_decrypt` because reversible encryption is needed (the client must decrypt to authenticate). Key via env var to separate from Django's `SECRET_KEY`.
- Proxy models (`MikroTikVLAN`, `MikroTikSubnet`) provide filtered admin views without duplicating schema — the proxy queryset filters by `source_kind=mikrotik`.
- VLAN uniqueness is global on `number` for this milestone. One-NetVibe-instance = one-logical-network-domain.
- Subnets derive from `/ip/address` (not from VLANs), which is the RouterOS source of truth for L3.
- DHCP leases are the primary source for IP records; ARP enriches MAC on existing IPs or creates observed-only records. Neighbors are observed (summary stored) but never create IPAM objects.
- `integration_sync_enabled` on each VLAN/Subnet lets the operator freeze individual objects without disabling the whole device.
- Scheduling follows the same `django-tasks-db` pattern as ICMP scans: guard against duplicate active runs, enqueue via `task.using(run_after=...).enqueue()`.

### Trade-offs and omissions

- No write-back to MikroTik — this milestone is read-only.
- No Celery, Redis, or external task broker — reuses `django-tasks-db`.
- No destructive sync deletes — orphaned objects are only marked `sync_state=orphaned` in future milestones.
- Subnet↔VLAN auto-linking from interface names is deferred — operator assigns manually for now.
- No custom CSS or extra containers.
