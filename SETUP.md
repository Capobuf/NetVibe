# Project Setup Guide

> **Navigation:** [← README](README.md) | [Architecture](ARCHITECTURE.md) | [Issues](ISSUES.md) | [📚 Docs Map](NAVIGATION.md)

---

## 🛠️ Prerequisites

- **Docker Desktop** (Windows/Mac/Linux)
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
# Edit .env if needed (DB credentials usually default in docker-compose)
```

### 3. Start Containers
```bash
docker-compose up -d
```

### 4. Install Dependencies & Seed
```bash
# Install PHP dependencies
docker-compose exec app composer install

# Generate Key
docker-compose exec app php artisan key:generate

# Run Migrations & Seeds
docker-compose exec app php artisan migrate --seed

# Install Node dependencies (for assets)
docker-compose exec app npm install
docker-compose exec app npm run build
```

---

## 💻 Development Workflow

### Running Commands
Always run Artisan commands inside the container:
```bash
docker-compose exec app php artisan <command>
```

### Clearing Cache
If you change Filament resources or config:
```bash
docker-compose exec app php artisan optimize:clear
docker-compose exec app php artisan filament:cache-components
```

### Accessing the App
- **URL:** http://localhost:8080/admin
- **Login:** `admin@modernipam.local` / `password`

---

## 🧪 Testing

Run tests inside the container:
```bash
docker-compose exec app php artisan test
```

---

## 📦 Troubleshooting

**"Class not found" errors:**
Run `docker-compose exec app composer dump-autoload`

**Database connection refused:**
Ensure containers are running: `docker-compose ps`
