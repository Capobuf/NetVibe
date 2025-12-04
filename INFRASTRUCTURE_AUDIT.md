# INFRASTRUCTURE_AUDIT.md

Data: 2025-12-03

Scopo: analisi rapida delle possibili cause infrastrutturali per il problema del Setup Wizard che "torna allo step 1 senza errori".
Basato su: `docker-compose.yml` (root), `docker/php/Dockerfile`, `.env.example`, `config/session.php`

---

## Executive summary (breve)

Ho analizzato la composizione dei container e le impostazioni delle sessioni. I punti critici osservati che possono causare il comportamento descritto sono:

- Bind-mount di tutto il codice (`./:/var/www/html`) nel `docker-compose.yml` (root) -> può sovrascrivere i permessi impostati al build time e creare mismatch di ownership tra host e container. Se il container usa `www-data` e il bind mount contiene file owned da host user, `www-data` non potrà scrivere in `storage`, `bootstrap/cache` o sul file `.env` creato dal setup wizard.
- Il `Dockerfile` imposta `USER www-data` e ha un `chown` durante il build — ma questo viene sovrascritto dalla bind-mount a runtime.
- Session driver predefinito in `.env.example` è `SESSION_DRIVER=redis` (buono), ma il funzionamento dipende dalla presenza di `.env` valida al runtime. Se `.env` non esiste o il wizard sta ancora cercando di scriverlo ma fallisce per permessi, l'app potrebbe ricadere su un driver diverso (es. `database` o `file`). Se il driver fosse `file` e `storage/framework/sessions` non sia scrivibile, le sessioni non verranno persistite.
- APP_URL in `.env.example` è impostato con `https://...` mentre il container serve HTTP (port 80). Se il browser e le policy cookie richiedono cookie 'Secure' (SESSION_SECURE_COOKIE=true o comportamento derivato), i cookie di sessione non saranno inviati su HTTP -> perdita di sessione -> wizard reset.
- Non esiste un entrypoint che garantisca chown a runtime quando si usa bind-mount (manca script che corregga perms quando la sorgente è montata da host). Questo è il punto singolo più probabile.

---

## Analisi dettagliata (evidenze)

1) Dockerfile (docker/php/Dockerfile)
- L'immagine si basa su `dunglas/frankenphp:php8.4-alpine`.
- Durante build: viene `chown -R www-data:www-data /var/www/html` e `chmod -R 755 /var/www/html`.
- Alla fine del Dockerfile viene impostato `USER www-data`.

Impatto: le operazioni di chown durante il build funzionano solo per i file che sono presenti all'interno dell'immagine. Se il runtime usa un bind-mount (`.`->`/var/www/html`) i file montati hanno i permessi/ownership del host e il chown effettuato in build non ha effetto.

2) docker-compose.yml (root)
- `app` usa `volumes: - ./:/var/www/html` (bind-mount TOTALE della sorgente). Questo è comodo per dev ma per produzione è pericoloso quando si lascia `USER www-data` e non si assicura ownership corretta sul host.
- `app` usa `env_file: .env` quindi l'app ha dipendenza diretta dal file `.env` montato nel container; se il wizard falla scrivendo `.env` i fallback del caricamento sconosciuti possono causare inconsistenze.

3) docker/compose.yml (rimosso/obsoleto — usare `docker-compose.yml` nella root)
- Questo file non monta `./:/var/www/html` — copia il codice nella build. Esiste quindi ambiguità: alcuni utenti possono usare il compose root (che monta bind) e questo può essere la causa della differenza tra ambiente di build e runtime.

4) .env.example e config/session.php
- `.env.example` imposta `SESSION_DRIVER=redis` (ottimo) e `TRUSTED_PROXIES=*`.
- `config/session.php` usa `env('SESSION_DRIVER','database')` come fallback. Se `.env` non contiene `SESSION_DRIVER` o è mancante, si userà database driver (necessita tabella sessions già migrata).
- `session.secure` è controllato da `SESSION_SECURE_COOKIE` (non impostato nell'esempio).

Impatto: se il wizard sta creando `.env` e fallisce (permessi), l'app potrebbe trovarsi con driver session diverso e/o DB non disponibile -> la sessione non persiste.

---

## Root cause (probabile)

Il problema più plausibile è un mismatch di permessi/ownership dovuto a bind-mount `./:/var/www/html` che sovrascrive le permission impostate in fase di build. Di conseguenza l'app (che gira come `www-data`) non riesce a scrivere in `storage/framework/sessions` o a modificare `.env` — questo porta alla perdita della sessione di setup, quindi la pagina ritorna allo step 1 senza messaggi di errore.

Un secondo fattore possibile è l'uso di `APP_URL` con https in `env`, con cookie 'secure' attivato (esplicitamente o per via di header X-Forwarded-Proto non trusted), che impedisce l'invio del cookie di sessione su HTTP.

---

## Severity & Priority

- Severity: HIGH — il Wizard non riesce a completare la configurazione iniziale (bloccante per deploy/configuration).
- Priority: P0/P1 — correggere immediatamente per ambienti di provisioning.

---

## Raccomandazioni pratiche e snippets correttivi

Obiettivo: garantire che `www-data` possa scrivere `storage`, `bootstrap/cache`, `vendor` quando necessario, ed evitare il binding completo dei file che sovrascrive ownership in produzione.

Approccio 1 — solida soluzione consigliata (produzione):

 - NON bind-mountare la sorgente completa in produzione. Lasciare la gestione del codice alla image build. Se serve sviluppare in dev, usare un `docker-compose.override.yml` o un compose dedicato per DEV.

Snippet (docker-compose.yml prod safer) — rimuovere mount `./:/var/www/html` per `app`:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    # NO bind mount in production
    # volumes: []
    # If you need persistence for uploads/sessions use named volume for storage
    volumes:
      - app-storage:/var/www/html/storage
      - app-upload:/var/www/html/public/uploads

volumes:
  app-storage:
  app-upload:
```

Approccio 2 — DEV/when bind-mount is necessary: add runtime entrypoint that fixes perms

1) Install `su-exec` (or `gosu`) in Dockerfile to drop privileges cleanly.

Update Dockerfile (excerpt) — install su-exec and add entrypoint:

```dockerfile
RUN apk add --no-cache su-exec

# Copy entrypoint
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
# Keep default user as root at container start so entrypoint can chown if bind-mounts override
USER root
CMD ["php","artisan","octane:start","--server=frankenphp","--host=0.0.0.0","--port=80","--admin-port=2019"]

# Note: entrypoint will drop privileges to www-data after corrections
```

Create `docker/entrypoint.sh` (example):

```sh
#!/bin/sh
set -e
# Fix common permission problems when host bind-mounts override container ownership
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
chown -R www-data:www-data /var/www/html/vendor || true
# Ensure session dir exists
mkdir -p /var/www/html/storage/framework/sessions
chown -R www-data:www-data /var/www/html/storage/framework/sessions || true

# Finally run the container command as www-data
exec su-exec www-data "$@"
```

This approach is safe for DEV and will ensure `www-data` can write the necessary runtime folders even when the code is bind-mounted.

Approccio 3 — quick temporary workaround on environment host

On the host, chown the repo directories to match `www-data` uid/gid used inside container. Example (Linux host):

```bash
# discover www-data uid on container
docker compose run --rm app id -u www-data
# assume uid 82 or 1000 (depends on image)
sudo chown -R 82:82 ./storage ./bootstrap/cache
```

Questo è utile quando non si vuole cambiare Dockerfile immediatamente.

---

## Session driver & cookie config checks (to avoid lost sessions)

1) Preferire `SESSION_DRIVER=redis` in `.env` for high-throughput and persistence across servers. Assicurati che `redis` service sia healthy (il docker-compose lo definisce) e che `REDIS_HOST` corrisponda.

2) Local dev vs production — `SESSION_SECURE_COOKIE` behaviour:
- In local HTTP dev, assicurati `SESSION_SECURE_COOKIE=false` o non impostato (null -> Laravel logic), o usa `APP_URL=http://localhost:9693` per evitare cookie secure flag.
- In production con TLS terminate dal proxy (Nginx traffico TLS->HTTP), assicurati `TRUSTED_PROXIES` includa i proxy e che l'app venga configurata con `APP_URL=https://your.domain` e `SESSION_SECURE_COOKIE=true`.

3) Verify at runtime inside container:

```bash
# check session driver
docker compose exec app php -r "echo config('session.driver').PHP_EOL;"

# check session folder perms if file driver
docker compose exec app ls -la storage/framework/sessions

# test ability to write
docker compose exec app php -r "file_put_contents('storage/framework/sessions/health', 'ok'); echo file_exists('storage/framework/sessions/health') ? 'ok' : 'fail';"
```

---

## Validation checklist (post remediation)

1) Re-deploy with non-binding production compose (no `./:/var/www/html`) and validate setup wizard completes.
2) If bind-mount required, add `entrypoint.sh` and ensure it runs as root at startup and then drops to www-data.
3) Confirm session driver is redis in `.env` and `config('session.driver') === 'redis'`.
4) Confirm secure cookie settings match deployment (HTTP vs HTTPS + trusted proxies).

---

## Steps success/rollback playbook (quick)

1. Create backup: DB snapshot, and copy `.env` -> `.env.bak`.
2. Update compose/Dockerfile + add entrypoint and rebuild image: `docker compose build app`.
3. Restart environment: `docker compose up -d`.
4. Validate via `docker compose logs` and the `php -r` checks above.
5. Rollback: restore previous image or reapply previous compose file and restore backups.

---

Se vuoi, posso:
- applicare i cambiamenti proposti (aggiungere `docker/entrypoint.sh` e aggiornare `docker/php/Dockerfile`), creare una versione dev-safe del `docker-compose.yml` senza bind-mount, o generare uno script di controllo automatizzato per i permessi. Dimmi quale preferisci che implementi ora.
