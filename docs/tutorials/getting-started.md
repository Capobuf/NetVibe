# Getting started (Docker)

## Prerequisiti
- Docker + Docker Compose v2
- Rete esterna `proxy` (richiesta da `compose.yml`)

```bash
docker network create proxy
```

## Avvio (dev)

> Nota: questo progetto usa **Docker Compose profiles**.

```bash
docker compose --profile dev up -d
```

Apri l'app (default):
- `http://localhost:9693`

## Primo setup

Segui il wizard su `/setup`.

Per migrazioni manuali:

```bash
docker compose exec netvibe-web php artisan migrate --seed
```
