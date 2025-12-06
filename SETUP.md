# NetVibe Setup Guide

## 1. Environment Setup

Copy the example environment file and configure it:
```bash
cp .env.example .env
nano .env
```

## 2. Docker Start

Start the application containers:
```bash
docker compose up -d
```

## 3. Frontend Assets Build

Since the Node.js service is not included in the runtime stack, you need to build assets using a temporary container:

```bash
docker run --rm \
    -v $(pwd):/app \
    -w /app \
    node:22-alpine \
    sh -c "npm install && npm run build"
```

This command mounts your current directory, installs dependencies, and builds the production assets into `public/build/`.

## 4. Database Setup

Run migrations and seed the database:
```bash
docker compose exec app php artisan migrate --seed
```

### Svuotare la Cache
Se modifichi le risorse Filament o la configurazione:
```bash
docker-compose exec app php artisan optimize:clear
docker-compose exec app php artisan filament:cache-components
```

### Accesso all'App
- **URL:** https://ipam.zeroloop.it
- **Login:** admin@modernipam.local / password (default) or the one created during setup.

---

## 🧪 Testing (Safe local instructions)

Important: do not run the full test-suite against your production database. The test helper `RefreshDatabase` will recreate/drop tables and will destroy real data if pointed at a non-test DB.

Recommended local approach (no extra containers required):

1) Ensure your `.env` is configured to use the dedicated test database name `netvibe-test` before starting containers (or override the env for the test command). Example edit:

```bash
cp .env .env.local
# Edit and set DB_DATABASE=netvibe-test
nano .env.local
```

2) Start the necessary services (DB, Redis, App):

```bash
docker compose up -d netvibe-db netvibe-redis netvibe-web
```

3) If the `netvibe-test` database does not exist, create it from the app container helper:

```bash
# runs the PHP helper that creates `netvibe-test`
docker compose exec netvibe-web php scripts/create_test_db.php
```

4) Run the tests inside the app container. Two safe options:

- Temporarily override the env var for the command (recommended when you can't change `.env`):

```bash
docker compose exec netvibe-web sh -c "DB_DATABASE=netvibe-test ./vendor/bin/pest"
```

- If you edited `.env` to set `DB_DATABASE=netvibe-test`, run normally inside the app container:

```bash
docker compose exec netvibe-web ./vendor/bin/pest
```

Safety note: we added a guard in `tests/Pest.php` that refuses to run the test suite unless `DB_DATABASE` equals `netvibe-test`. If you see a refusal message, set the env as shown above.

If you prefer an automated helper, we can add `scripts/run-tests.sh` to wrap these steps.

---

## 📦 Troubleshooting

**"Class not found" errors:**
Run `docker compose exec netvibe-web composer dump-autoload`

**Database connection refused:**
Ensure containers are running: `docker compose ps`

---

**Last Updated:** 2025-12-06
