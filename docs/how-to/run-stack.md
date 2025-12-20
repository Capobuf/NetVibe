# Run the stack

## Profiles
I servizi sono definiti sotto **profiles** (vedi `compose.yml`).

- `dev`: stack di sviluppo
- `prod`: stack di produzione
- `worker`: avvia il worker (oltre al web)
- `test`: avvia il DB di test `netvibe-test-db`

Note operative:
- Prima del build dell'immagine esegui `npm ci && npm run build` (vedi `docs/how-to/build-assets.md`) così `public/build/` è disponibile senza dipendere dall'host.
- Nei profili `dev` si monta solo il codice (non `vendor`/`node_modules`): i container usano le dipendenze già incluse nell'immagine.
- Il remote agent non è incluso in questo compose: per avviarlo usa `remote-agent/docker-compose.yml` se serve.

## Esempi

Dev:
```bash
docker compose --profile dev up -d
```

Dev + worker:
```bash
docker compose --profile dev --profile worker up -d
```

Dev + test:
```bash
docker compose --profile dev --profile test up -d
```
