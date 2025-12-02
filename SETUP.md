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

## 🧪 Testing

Esegui i test all'interno del container. Il test runner è configurato per usare SQLite in-memory:
```bash
docker-compose exec app php artisan test
```

---

## 📦 Troubleshooting

**"Class not found" errors:**
Run `docker compose exec app composer dump-autoload`

**Database connection refused:**
Ensure containers are running: `docker compose ps`

---

**Last Updated:** 2025-12-02
