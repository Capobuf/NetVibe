# Run tests safely

La suite usa `RefreshDatabase`: non puntare mai a DB reali.

NetVibe usa un DB dedicato per i test:
- servizio: `netvibe-test-db` (profile `test`)
- database: `netvibe_test`

Avvio servizi:

```bash
docker compose --profile dev --profile test up -d netvibe-web netvibe-test-db netvibe-redis netvibe-db
```

Esecuzione:

```bash
docker compose exec netvibe-web ./vendor/bin/pest
```

Oppure:

```bash
./scripts/run-tests.sh --yes
```
