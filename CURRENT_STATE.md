# CURRENT_STATE.md

Generated: 29 novembre 2025

This document summarises the current implemented state of the project relevant to the planned Worker Node and Global/Local Scan Configuration work.

---

## 1) Infrastructure Audit (docker-compose, Dockerfile, supervision)

- Containers defined in `docker-compose.yml`:
  - app (PHP-FPM) — built from `docker/php/Dockerfile` (container_name: modern-ipam-app)
  - webserver (nginx) — image nginx:1.28.0-alpine (ports: 8080:80)
  - db (MariaDB 11.8) — container modern-ipam-db
  - redis (Redis 8.4) — container modern-ipam-redis
  - node (Node.js) — used for asset compilation when using the `dev` profile

- Worker / queue service:
  - There is currently NO dedicated *worker* service in docker-compose. Queue workers are expected to be started manually inside the `app` container (examples in docs and README use `docker-compose exec app php artisan queue:work`).

- fping and binaries:
  - `fping` is installed in the `app` container image (added in `docker/php/Dockerfile` via apk). The Dockerfile also installs `iputils`, `bind-tools` and healthcheck utilities — so scanning binaries are available in-app.

- Process supervision:
  - There is no `supervisord.conf` or dedicated supervisor configuration present. The project relies on explicitly launching workers (or running processes inside containers) instead of a supervisor-managed worker container.

Conclusion: app image has the scanning tools; however, there is no dedicated worker node/service in Compose — scanning jobs run by dispatching queue jobs which require a worker process started manually in `app` or run via external orchestration.

---

## 2) Database Schema Audit (subnets & settings)

- Subnets schema / model:
  - `app/Models/Subnet.php` contains fields and casts demonstrating built-in support for scanning metadata: `ping_subnet` (boolean), `discover_subnet` (boolean), `scan_agent_id` (nullable FK), `last_scan` (datetime), `last_discovery` (datetime).
  - Migrations confirm these columns exist: `2025_01_01_000002_create_ipam_schema.php` (subnets table) and `2025_01_01_000006_add_last_discovery_to_subnets.php` / `2025_11_28_000004_alter_subnets_add_customer_scan_agent.php` add/alter the related scan columns.

- Settings (global) schema / classes:
  - The project uses Spatie Settings; `database/migrations/2025_11_29_133422_create_settings_table.php` exists and was published/committed.
  - There is a `GeneralSettings` Spatie Settings class implemented at `app/Settings/GeneralSettings.php` which exposes application-wide config items: `site_name`, `scan_ping_timeout` (ms), and `site_color`.
  - No dedicated `DiscoverySettings` class or dedicated global scan configuration class was found; global scan-related options appear limited to `scan_ping_timeout` in the `general` settings group.

Conclusion: Subnets include explicit fields that support per-subnet scan configuration and agent link. Global settings are present but limited — no higher-level discovery configuration class exists yet (e.g., default scan enable/interval per subnet).

---

## 3) Logic Flow Audit (Scan job and wrapper)

- How scanning runs:
  - `app/Jobs/ScanSubnetJob` is a queued job (implements ShouldQueue). It accepts a Subnet model when dispatched and performs scanning using the `FpingWrapper`.

- Wrapper details:
  - `app/Services/Scanners/FpingWrapper.php` runs `fping` with flags `-a -g -r 1 -t 200 <CIDR>`.
  - It returns a list of alive IPv4 addresses and throws on fatal exit codes (3 or 4), while handling other exit codes as valid (no results).

- Job logic and result handling:
  - The Job calls the wrapper, converts each discovered IP to decimal via `IpConversionService`, and upserts `IpAddress` rows using `updateOrCreate` with `subnet_id` and `ip_addr` (sets `last_seen` and `state=1`).
  - After processing, the Job sets `last_discovery` on the Subnet record and saves.
  - On errors, the Job logs and bubbles exceptions to allow retries / failure handling.

- Triggering & safeguards:
  - Scans are triggered manually from the Filament UI via a record action in `SubnetResource` that dispatches `ScanSubnetJob::dispatch($record)`. There is no evidence of an automated scheduled scan flow in repo scheduling configs.
  - The Job itself DOES NOT check `discover_subnet` or `ping_subnet` flags — it runs whenever dispatched. The model supports those flags, but the job performs no extra guard.

Conclusion: Scanning is implemented end-to-end and persists discovery results. The current flow is manual UI-trigger dispatch to queued jobs; the job performs conversion/upsert and updates discovery timestamp but doesn’t guard based on subnet-level flags.

---

## 4) UI Audit (Subnet and Settings pages)

- Subnet UI (`SubnetResource`):
  - `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` exposes a `Scan Network` record action which dispatches `ScanSubnetJob` for that subnet.
  - The `form()` shape (Create/Edit pages) shows VRF, VLAN, CIDR and Description only — it does NOT present explicit toggles for `discover_subnet` or `ping_subnet` in the current schema. That means: even though the Subnet model tracks `discover_subnet` / `ping_subnet`, the standard Subnet edit UI doesn't currently expose those toggles to users.

- Settings UI (`GeneralSettingsPage`):
  - `app/Filament/Pages/GeneralSettingsPage.php` exposes `site_name`, `scan_ping_timeout` (ms), and `site_color` via a Filament form.
  - There is NO `DiscoverySettings` or “default scan settings” page/section currently — defaults are limited to `scan_ping_timeout` in the `general` group.

Conclusion: Users can manually start a scan from the Subnet UI, but cannot configure scan enable/interval per subnet from the standard Subnet form. There is a single global setting for ping timeout exposed in General Settings, but there is no dedicated discovery configuration UI nor default per-subnet scan configuration exposed in the UI.

---

## Summary (short)

- App container already contains scanning binaries (`fping`). There is no dedicated worker service in Compose — queue workers must be launched manually or by orchestration.
- Subnets already model scan-related metadata (enable flags, agent FK, last timestamps). Migrations for those columns exist and are applied.
- Scan logic is implemented — FpingWrapper + ScanSubnetJob (upsert discovered IPs, update last_discovery). The job does not guard against subnet-level flags; it runs when dispatched.
- UI supports manual scans via Subnet resource action; global settings include `scan_ping_timeout` but there is no Discovery-specific settings page or default per-subnet scan configuration exposed in the UI.

---

If you'd like, I can now draft a precise Implementation Plan to add a dedicated Docker worker node and a Global/Local Scan Configuration system (safe migration, UI, scheduling, worker orchestration). Which do you prefer next: a) full implementation plan with code change list and Docker compose changes, or b) a minimal, incremental plan with low-risk steps for staging first?
