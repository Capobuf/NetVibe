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
