Infra, deployment & networking

Stack container (Compose V2, `compose.yml`)

- `app`: container Laravel php-fpm/nginx (ServerSideUp base), espone HTTP interno sulla porta 80 (mappata esternamente, es. `8080:80`), health `/up`.  
  - Build da `Dockerfile.app` nella root del repository.  
  - Monta il volume `storage:/var/www/html/storage` per la persistenza.  
  - Usa profili Compose `["dev","prod"]`.
- `worker`: container worker queue che usa la stessa immagine di `app`, avvia `php artisan queue:work` su code `discovery`, `default`, `low` come da PDL.  
  - Usa anch’esso `Dockerfile.app` (stessa immagine, comando differente).
  - Usa profili `["prod","dev","worker"]`.  
  - Condivide il volume applicativo con `app`.
- `db`: MariaDB 11.x con volume persistente `dbdata:/var/lib/mysql`.  
  - Variabili: `MYSQL_DATABASE=netvibe`, `MYSQL_USER=netvibe`, `MYSQL_PASSWORD=secret`, `MYSQL_ROOT_PASSWORD=root`.  
  - Profilo: `["dev","prod","test"]`.
- `redis`: Redis 8.4 (immagine `redis:8.4`, senza AOF per semplicità; `--appendonly no`) per code/cache/sessioni.  
  - Volume dedicato `redisdata:/data`.  
  - Profili: `["dev","prod"]`.
- `test-db`: MariaDB 11 per test, con storage su `tmpfs` per reset rapido.  
  - Variabili: `MYSQL_DATABASE=netvibe_test`, utente/password come `db`.  
  - Profilo: `["test"]`.

Note:

- Non viene più usato `docker-compose.yml` legacy: il file canonico è `compose.yml`.  
- La network interna principale è `netvibe-network` (bridge) e collega `app`, `worker`, `db`, `redis`, `test-db`.  
- Eventuali network aggiuntive come `proxy` sono opzionali e servono solo per collegare un reverse proxy esterno (Nginx Proxy Manager, Traefik, ecc.); il loro uso è fuori dallo scope dell’applicazione.

Laravel bootstrap

- `bootstrap/app.php` definisce trusted proxies, forza URL canonica/HTTPS quando configurato, registra il middleware `CheckSystemInstalled` nel gruppo `web` e forza risposta JSON per eccezioni API/auth-header.

Sessioni e cookie

- Per evitare problemi di sessione (es. errori 419 Page Expired) dietro reverse proxy:
  - `SESSION_DOMAIN` **non** deve essere impostato alla stringa `"null"`: lasciarlo vuoto (`SESSION_DOMAIN=`) o non impostato, così Laravel usa il dominio dell’header `Host`.  
  - `SESSION_SECURE_COOKIE` deve essere `true` quando l’accesso avviene via HTTPS tramite reverse proxy.  
  - `TRUSTED_PROXIES=*` o una lista esplicita di IP/subnet del proxy devono essere configurati, così gli header `X-Forwarded-*` vengono interpretati correttamente.

Remote agent runtime

- Remote agent eseguito in un container PHP 8.3 CLI separato, build da `remote-agent/Dockerfile` (PHP CLI + fping, curl, composer).  
  - Entry principale: script/CLI `bin/netvibe-agent` che invoca `AgentWorkCommand`.  
  - Lo stack server non include necessariamente il servizio agent nel `compose.yml`; l’agent può essere gestito con un proprio `compose.yml` o `docker run`.
- Env vars:
  - `SERVER_URL` / `AGENT_SERVER_URL` / `IPAM_AGENT_API_URL` (base URL, con `/api/v1` escluso).  
  - Token `AGENT_API_TOKEN` / `IPAM_AGENT_API_KEY` (PAT generato da UI su ScanAgent).  
  - `LOG_LEVEL` opzionale.

Control-plane ↔ agent protocol

- Auth: ScanAgent usa un PAT (Sanctum) per chiamare `/api/v1/agent/*`.  
- Endpoints principali:
  - Config polling: `GET /api/v1/agent/config`.  
  - Job polling: `GET /api/v1/agent/jobs`.  
  - Risultati job: `POST /api/v1/agent/jobs/{job}/result`.  
  - Log ingest: `POST /api/v1/agent/logs`.  
- Device comms: l’agent chiama Mikrotik REST via HTTP(S) con auth di base; in Fase 1 TLS verify può essere disattivato lato agent (vedi Debito & rischi). Fping/ICMP e SNMP sono driver separati; l’agent normalizza sempre in `device_result`.

External dependencies

- MariaDB per persistenza, Redis per cache/session/queue, Sanctum per PAT, Spatie Settings (`settings` table).  
- Non sono previste altre integrazioni esterne oltre Mikrotik REST, SNMP standard, UniFi Controller API e tool di sistema (fping, ping, ecc.).
