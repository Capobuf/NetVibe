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

Esegui i test all'interno del container. Per coerenza con l'ambiente CI e le impostazioni di `phpunit.xml` i test usano MariaDB (database `netvibe_testing`) — non usare SQLite in-memory per i test dell'intera applicazione.

Passaggi consigliati per eseguire i test localmente con MariaDB (container):

1. Assicurati di avere `DB_DATABASE` impostato su `netvibe_testing` nella tua `.env` prima di avviare i container. Questo permette al servizio MariaDB di creare il database iniziale:

```bash
cp .env.example .env
# Edit .env and set:
# DB_DATABASE=netvibe_testing
nano .env
```

2. Avvia i servizi Docker necessari (DB, Redis, App):

```bash
docker compose up -d db redis app
```

3. (Opzionale) Se il database non è stato creato automaticamente — per esempio se `DB_DATABASE` era differente — crea il DB di test manualmente nella container DB:

```bash
docker compose exec db mysql -u root -p${DB_PASSWORD} -e "CREATE DATABASE IF NOT EXISTS netvibe_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

4. Esegui i test dentro il container `app` (ciò garantisce che la connessione punti al container DB):

```bash
docker compose exec app ./vendor/bin/pest --filter <TestFileOrPattern>
# oppure
docker compose exec app php artisan test
```

Note:
- `tests/TestCase.php` forza l'uso del database `netvibe_testing` in fase di test; ciò assicura l'isolamento dei test se il servizio MariaDB è correttamente in ascolto.
- Se preferisci eseguire test in locale senza MariaDB (per singoli test più veloci), è possibile impostare un driver `sqlite` locale; tuttavia, il progetto e la CI usano MariaDB e gli integration tests sono scritti con dipendenze MySQL compatibili (es. SQL dialect e storage engine), quindi non è consigliato.

Se hai bisogno, posso aggiungere un breve script `scripts/run-tests.sh` che automatizza questi passaggi per ambienti di sviluppo.

---

## 📦 Troubleshooting

**"Class not found" errors:**
Run `docker compose exec app composer dump-autoload`

**Database connection refused:**
Ensure containers are running: `docker compose ps`

---

**Last Updated:** 2025-12-02
