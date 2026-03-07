# NetVibe

Minimal Django + Unfold + PostgreSQL development setup with Docker Compose.

## Prerequisites

- Docker Desktop
- VS Code

## Environment setup

1. Copy the example file:

   ```powershell
   Copy-Item .env.example .env
   ```

2. Generate a real Django secret key on the host and replace `DJANGO_SECRET_KEY` in `.env`:

   ```powershell
   python -c "import secrets; print(secrets.token_urlsafe(50))"
   ```

3. Set the pgcrypto key used for MikroTik credential encryption:

   ```env
   NETVIBE_PGCRYPTO_KEY=change-me-to-a-real-secret
   ```

## Start (normal)

```powershell
docker compose up --build
```

Then run:

```powershell
docker compose exec web python manage.py migrate
```

The site and admin are served at `http://127.0.0.1:8001` (`/admin/` for admin).
For local development, a default admin account is available with username `admin` and password `admin`.

## Greenfield reset (canonical schema path)

Use this when you want a fully clean database with no preserved dev data.

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec web python manage.py migrate
docker compose exec web python manage.py showmigrations ipam
docker compose exec web python manage.py test ipam
```

This is the canonical setup path for a fresh environment. No manual SQL and no `schema_editor` patching are required.

## Start (debug)

```powershell
docker compose -f docker-compose.yml -f docker-compose.debug.yml up --build
```

Then attach from VS Code using the included `Python: Attach (Docker)` launch configuration.

## Runtime constraints

- Stack stays Django + Unfold + PostgreSQL + existing worker process
- No Node.js/npm/yarn/pnpm or frontend build pipeline
- Docker Compose runs three services: `web`, `db`, `worker`

## MikroTik integration operator flow

Admin sidebar under **Integrations** includes:

- MikroTik settings
- MikroTik targets
- MikroTik discovery runs
- MikroTik devices
- MikroTik sync runs
- MikroTik VLANs
- MikroTik subnets

### 1. Configure global credentials

Open **Integrations -> MikroTik settings** and configure:

- global username
- global password (write-only)
- run retention days

### 2. Add discovery targets

Open **Integrations -> MikroTik targets** and add one IPv4 endpoint per row:

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

Open **MikroTik devices** only for operational tuning:

- sync interval
- import flags
- authority flags
- status diagnostics

Endpoint fields on devices are readonly diagnostics.
Endpoint edits belong to **MikroTik targets**; rerunning discovery overwrites device endpoint fields from target values.

## Running tests

```powershell
docker compose exec web python manage.py test ipam
```

## Verification

After sync completes, check:

- **MikroTik VLANs**: imported VLANs, source device, upstream state, sync toggle
- **MikroTik subnets**: imported subnets, interface name, upstream state, scan toggle
- **IP addresses**: MikroTik provenance, lease fields, sync state
- **MikroTik sync runs**: sync-only run history and diagnostics
- **MikroTik discovery runs/results**: per-target onboarding outcomes

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
