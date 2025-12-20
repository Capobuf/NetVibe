# Docker Compose runtime

NetVibe uses **Docker Compose profiles**. If you run `docker compose up -d` without profiles, Compose may start **nothing**.

## Profiles

- `dev`: local development stack (bind-mount del codice)
- `prod`: production-like stack (single-node, immagine immutabile)
- `worker`: utility profile per avviare il solo worker (queue)
- `test`: ephemeral MariaDB for tests (`netvibe-test-db`)

## Service names

| Service | Profile(s) | Notes |
|---|---:|---|
| `netvibe-web-dev` | dev | Laravel app (bind-mount), exposed as `9693:8080` |
| `netvibe-web` | prod | Laravel app (image-only), exposed as `9693:8080` |
| `netvibe-worker-dev` | dev | Runs `php artisan queue:work --queue=discovery,default,low` (bind-mount) |
| `netvibe-worker` | prod, worker | Runs `php artisan queue:work --queue=discovery,default,low` (image-only) |
| `netvibe-db` | dev, prod, test | MariaDB persistent volume `dbdata` |
| `netvibe-redis` | dev, prod | Redis (appendonly off) |
| `netvibe-test-db` | test | MariaDB on tmpfs, DB=`netvibe_test` |

## Volumes

- `dbdata`: persistenza MariaDB (prod/dev)
- `redisdata`: persistenza Redis (prod/dev)
- `netvibe_storage`: persistenza Laravel `storage/` (prod)
- `netvibe_uploads`: persistenza `public/uploads` (prod)

In `dev` si usano bind-mount (`./storage`, `./public/uploads`) per una UX più comoda durante lo sviluppo.

## Common commands

### Development

```bash
docker network create proxy 2>/dev/null || true
docker compose --profile dev up -d
```

### Production-like

```bash
docker network create proxy 2>/dev/null || true
docker compose --profile prod up -d --build
```

### Run prod worker explicitly

Se vuoi avviare/riavviare il solo worker in produzione:

```bash
docker compose --profile prod --profile worker up -d netvibe-worker
```

### Test stack

```bash
docker compose --profile dev --profile test up -d netvibe-test-db netvibe-web-dev
```
