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

## Start (normal)

```powershell
docker compose up --build
```

Then run:

```powershell
docker compose exec web python manage.py migrate
```

The site is served at http://127.0.0.1:8000 and the admin is at http://127.0.0.1:8000/admin/.
For local development, a default admin account is available with username `admin` and password `admin`.
After migrating, the admin sidebar includes an `IPAM` group with `VLANs` and a nested `Subnets` link.
When `DJANGO_DEBUG=1`, `django-debug-toolbar` is enabled at http://127.0.0.1:8000/__debug__/.

## Start (debug)

```powershell
docker compose -f docker-compose.yml -f docker-compose.debug.yml up --build
```

Then attach from VS Code using the included `Python: Attach (Docker)` launch configuration.

## Project constraints

- No Node.js, npm, yarn, pnpm, or frontend build step
- Only two containers: `web` and `db`
- PostgreSQL is always used, with development-only dependencies kept in `requirements-dev.txt`

## MikroTik integration

### Environment

Add `NETVIBE_PGCRYPTO_KEY` to your `.env` file. This key is used to encrypt MikroTik device passwords at rest via PostgreSQL's `pgcrypto` extension. **Use a strong, unique value in production.**

```env
NETVIBE_PGCRYPTO_KEY=change-me-to-a-real-secret
```

### Setup

After rebuilding the containers and running migrations:

```powershell
docker compose up --build
docker compose exec web python manage.py migrate
```

The admin sidebar now includes an **Integrations** group with:
- MikroTik devices
- MikroTik sync runs
- MikroTik VLANs (filtered view of imported VLANs)
- MikroTik subnets (filtered view of imported subnets)

### Adding a device

1. Navigate to **Integrations → MikroTik devices → Add**
2. Enter the minimum onboarding fields (host/IP, scheme, port, username, password, gateway flag)
3. Click **Save and test** to save immediately and schedule a background connection test
4. Refresh the change page to see test result, detected identity/version, and any error details
5. After first successful contact, advanced sync/import/authority settings become available
6. Use the **Sync now** action on the device detail page to trigger a full sync

### Running tests

```powershell
docker compose exec web python manage.py test ipam
```

### Verification

After a successful sync, check:
- **MikroTik VLANs**: shows imported VLANs with source device, upstream state, and sync toggle
- **MikroTik subnets**: shows imported subnets with interface name, upstream state, and scan toggles
- **IP addresses**: MikroTik-sourced IPs show lease kind, DHCP server, and provenance fields
- **MikroTik sync runs**: each test/sync is logged with counters and diagnostics

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
