# Project Setup Guide


---

## 🛠️ Prerequisites

- **Docker (Engine & Compose)** (Windows/Mac/Linux)
- **Git**
- **Terminal** (PowerShell, Bash, or Zsh)

---
 
 

## 🚀 Installation

### 1. Clone Repository
```bash
git clone <repo_url> phpipam-laravel
cd phpipam-laravel
```

### 2. Environment Setup
```bash
cp .env.example .env
# Modifica .env se necessario (DB credentials di default in docker-compose)
```

### 3. Start Containers

For development (recommended) — use the override file that mounts your local source and exposes the webserver port:

```bash
docker compose -f docker/docker-compose.yml -f docker/dev/docker-compose.override.yml up -d
```

For production (example) — combine the base file with the production override:

```bash
docker compose -f docker/docker-compose.yml -f docker/production/docker-compose.prod.yml up -d
```

### 4. Install Dependencies & Seed
```bash
# Installa dipendenze PHP
docker compose exec app composer install

# Genera Key
docker compose exec app php artisan key:generate

# Esegui Migrations & Seeds
docker compose exec app php artisan migrate --seed

# Installa dipendenze Node (per gli asset)
docker compose exec app npm install
docker compose exec app npm run build
```

---

## 💻 Development Workflow

### Esecuzione Comandi
Esegui sempre i comandi Artisan all'interno del container (development example using the override):
```bash
docker compose exec app php artisan <command>
```

### Svuotare la Cache
Se modifichi le risorse Filament o la configurazione:
```bash
docker-compose exec app php artisan optimize:clear
docker-compose exec app php artisan filament:cache-components
```

### Accesso all'App
- **Dev URL (override):** http://localhost:9693/admin

If your environment uses a reverse proxy or different port mapping for production, consult the deployment configuration.
- **Login:** admin@modernipam.local / password

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

**Last Updated:** 2025-11-30

 
