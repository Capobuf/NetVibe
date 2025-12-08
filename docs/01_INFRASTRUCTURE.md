Infra, deployment & networking

Stack container
- `netvibe-web`: php-fpm/nginx app (ServerSideUp base), port 9693→8080, health `/up`.
- `netvibe-worker`: queue worker same image, command `php artisan queue:work --tries=3 --timeout=300`.
- `netvibe-db`: MariaDB 11.8; `docker/db-init.sh` can seed test DB/grants.
- `netvibe-redis`: Redis 8 AOF.
- Image build: `docker/php/Dockerfile` adds bcmath, intl, sockets, gd + fping/iputils/dnsutils; prod volumes for storage/uploads. Dev override bind-mounts repo+storage/uploads and enables debug (docker-compose.override.yml).
- Networks/volumes: bridge `ipam-network`, external `proxy`; volumes `db-data`, `redis-data`, `app-storage`, `app-uploads`.

Laravel bootstrap
- `bootstrap/app.php` sets trusted proxies, forces canonical URL/HTTPS when configured, adds CheckSystemInstalled middleware to web group, and forces JSON for API/auth-header exceptions.

Remote agent runtime
- Separate PHP 8.3 CLI container (`remote-agent/docker-compose.yml`) built by `remote-agent/Dockerfile` (fping, curl, composer). Entry `agent.php` runs `AgentWorkCommand`.
- Env vars: `SERVER_URL`/`AGENT_SERVER_URL`/`IPAM_AGENT_API_URL` (base URL, `/api/v1` stripped), token `AGENT_API_TOKEN|IPAM_AGENT_API_KEY`, optional `LOG_LEVEL`. Token issued via UI (ScanAgent edit modal).

Control-plane ↔ agent protocol
- Auth: ScanAgent (Sanctum PAT) hits `/api/v1/agent/*`.
- Endpoints: config polling (AgentConfigController@index), job polling + result POST (AgentJobController@index/storeResult), log ingest (AgentLogController@store), discovery ingest (DiscoveryIngestController@store).
- Device comms: Agent calls Mikrotik REST over https/http with basic auth; SSL verify disabled by default (MikrotikApiDriver). Legacy fping used when payload has `cidr`/`legacy_fping` (JobHandler::handleDiscoveryDeep).

External dependencies
- MariaDB persistence, Redis cache/session/queue, Sanctum PATs, Spatie Settings (`settings` table). No other third-party APIs beyond Mikrotik REST and OS tools.
