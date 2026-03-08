# NetVibe

Minimal Django + Unfold + PostgreSQL development setup with Docker Compose.

## Prerequisites

- Docker Desktop (or Docker Engine + Docker Compose plugin)
- Optional: VS Code for debugging with `docker-compose.debug.yml`

## Environment setup

1. Copy the example file:

   ```powershell
   Copy-Item .env.example .env
   ```

   `.env` is local-only and ignored by git.

2. Generate a real Django secret key on the host and replace `DJANGO_SECRET_KEY` in `.env`:

   ```powershell
   python -c "import secrets; print(secrets.token_urlsafe(50))"
   ```

3. Set the pgcrypto key used for MikroTik credential encryption:

   ```env
   NETVIBE_PGCRYPTO_KEY=change-me-to-a-real-secret
   ```

   `NETVIBE_PGCRYPTO_KEY` is required by `ipam/crypto.py` for credential encrypt/decrypt.

## Start (normal)

```powershell
docker compose up --build
```

Then run:

```powershell
docker compose exec web python manage.py migrate
docker compose exec web python manage.py createsuperuser
```

The site and admin are served at `http://127.0.0.1:8001` (`/admin/` for admin).

## Greenfield reset (canonical schema path)

Use this when you want a fully clean database with no preserved dev data.

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec web python manage.py migrate
docker compose exec web python manage.py showmigrations ipam
docker compose exec web python manage.py test ipam
```

## Start (debug)

```powershell
docker compose -f docker-compose.yml -f docker-compose.debug.yml up --build
```

Then attach from VS Code using the included `Python: Attach (Docker)` launch configuration.

## Runtime constraints

- Stack stays Django + Unfold + PostgreSQL + existing worker process
- No Node.js/npm/yarn/pnpm or frontend build pipeline
- Docker Compose runs three services: `web`, `db`, `worker`

## Admin navigation

The admin sidebar is organized as:

- **Navigation**: Dashboard
- **Networks**: VLANs, Subnets, IP addresses, IPAM settings
- **Discovery**: Discovery overview, MikroTik targets, MikroTik devices, Discovery runs, Review queue
- **System**: Job runs, Task results, MikroTik settings

## Discovery operator flow

### 1. Configure global credentials

Open **System -> MikroTik settings** and configure:

- global username
- global password (write-only)
- run retention days

### 2. Add discovery targets

Open **Discovery -> MikroTik targets** and add one IPv4 endpoint per row:

- host (IPv4 only)
- scheme (`https` or `http`)
- port
- verify TLS

Default onboarding target values: `https`, `443`, `verify_tls=False`.

### 3. Run discovery for selected targets

In the target changelist, select one or more rows and execute the action:

- `Run discovery for selected targets`

This creates one discovery batch run, pre-creates per-target result rows, enqueues one background task, and redirects to the run detail page.

### 4. Watch live run progress

From **Discovery -> Discovery overview**:

- monitor targets/devices/runs/conflicts counters
- jump quickly to targets, devices, discovery runs, sync runs, and MikroTik settings

On the discovery run detail page:

- the page auto-refreshes while status is `scheduled` or `running`
- a live related results table is embedded on the same page
- a direct link opens the filtered full results list

### 5. Outcome behavior

On success, discovery automatically:

- creates or updates `MikroTikDevice` by host
- copies endpoint config from target to device
- updates detected metadata
- links target to device
- schedules first full sync immediately

On failure or blocked conflict:

- target remains available for retry
- result stores concise reason and message
- identity conflicts are blocked for manual resolution (no merge wizard)

### 6. Operate devices after onboarding

Open **Discovery -> MikroTik devices** only for operational tuning:

- sync interval
- import flags
- authority flags
- status diagnostics

Endpoint fields on devices are readonly diagnostics.
Endpoint edits belong to **Discovery -> MikroTik targets**; rerunning discovery overwrites device endpoint fields from target values.

## Canonical vs observed data

- `IPAddress` is the canonical MSP-owned IPAM record.
- `IPAddressObservation` stores latest observed discovery facts per device/IP (hostname, MAC, lease metadata, timestamps).
- DHCP/ARP sync updates operational canonical fields (`last_seen`, sync/source timestamps, lease metadata, sync state) but does not silently overwrite canonical `hostname`, `mac_address`, `status`, or `note`.
- ICMP subnet scan updates operational reachability (`last_seen`) and does not auto-populate or overwrite canonical `hostname`/`mac_address`.

## Review queue

Use **Discovery -> Review queue** as the persistent operational inbox.

Current review kinds:

- `new_ip_observed`
- `hostname_conflict`
- `mac_conflict`
- `sync_failure`
- `discovery_conflict`

Repeated occurrences update the same review item via `dedupe_key` instead of creating duplicates.

## Running tests

```powershell
docker compose exec web python manage.py check
docker compose exec web python manage.py test ipam
```

`ipam/tests.py` sets a test-local fallback `NETVIBE_PGCRYPTO_KEY` so encryption-related tests do not depend on ambient host `.env` state.

## Verification

After sync completes, check:

- **Networks -> VLANs**: imported VLANs, source device, upstream state, sync toggle
- **Networks -> Subnets**: imported subnets, interface name, upstream state, scan toggle
- **Networks -> IP addresses**: MikroTik provenance, lease fields, sync state
- **Discovery -> Review queue**: conflicts, new IP observations, and sync/discovery issues
- **System -> Task results** and **System -> Job runs**: background execution status
- **Discovery -> Discovery runs/results**: per-target onboarding outcomes

## Translations

Generate Italian message catalogs:

```powershell
docker compose exec web python manage.py makemessages -l it
```

Compile translations:

```powershell
docker compose exec web python manage.py compilemessages
```

Switch the active language with Django's standard endpoint at `/i18n/setlang/`.
