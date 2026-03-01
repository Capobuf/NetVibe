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
docker compose exec web python manage.py createsuperuser
```

The site is served at http://127.0.0.1:8000 and the admin is at http://127.0.0.1:8000/admin/.
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
