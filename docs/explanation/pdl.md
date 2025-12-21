# Piano di Lavoro (PDL) — IPAM/DCIM MSP

Documento derivato dal codice e dalla documentazione tecnica esistente. Funziona come “contratto” tra prodotto e implementazione: tutto ciò che è descritto qui deve avere un riscontro concreto nel codice o rientrare nei TODO/FIXME espliciti.

## 0. Contesto, vincoli e fonti

- **Stack applicativo**  
  - Laravel 12 (ultima stabile).  
  - Filament v4 (ultima stabile), solo componenti nativi Filament; Filament v3 non è supportato.  
  - Nessun CSS custom; Livewire custom evitato salvo eccezioni documentate (es. Tenant Switcher via hook Filament).  
  - PHP-FPM + nginx nell’immagine applicativa (ServerSideUp base).
- **Dominio funzionale**  
  - IPAM (Subnets, IP, VLAN, ruoli IP, occupancy).  
  - DCIM (Devices, Ports, Racks, Links fisici, alimentazioni, WAN/ISP).  
  - Discovery automatizzata via agent remoto.  
  - Workflow di merge/approvazione dei dati di discovery.
- **Tenancy**  
  - Multi-tenant “soft”: `Customer` è il tenant logico.  
  - `Location` rappresenta la sede del cliente e coincide anche con l’ambito L2/logico.  
  - Ogni risorsa chiave ha sempre `customer_id`; molte hanno anche `location_id`.
- **Vincoli generali**  
  - Read-only verso i device: nessun push di configurazione (MikroTik REST è usato solo per lettura).  
  - L’agent è un acceleratore, non un requisito: tutte le operazioni chiave devono essere eseguibili anche solo da UI/IPAM manuale.  
  - Sicurezza by-default: HTTPS con TLS verify per l’app; per gli agent verso i device il verify può essere rilassato in Fase 1 (vedi Debito & rischi).  
  - Non inventare API, formati o campi non presenti nel codice o descritti in questo PDL: in caso di dubbi, usare `TODO`/`FIXME`.

### Fonti base nel repo

- Architettura/infra/agent → `docs/01_INFRASTRUCTURE.md`  
- Schema/tenancy → `docs/02_DATABASE_SCHEMA.md`  
- Logica IPAM → `docs/03_CORE_LOGIC_IPAM.md`  
- Logica DCIM/device → `docs/04_CORE_LOGIC_DCIM.md`  
- UX/Filament → `docs/05_UI_UX_FILAMENT.md`  
- Observability/test → `docs/06_OBSERVABILITY_AND_TESTS.md`  
- Debito/rischi → `docs/99_DEBT_AND_INCONSISTENCIES.md`

### Target e scala

- MSP con ~1 istanza di NetVibe per MSP.  
- < 50 agent remoti per istanza.  
- Reti tipiche /24, con qualche /22–/21.  
- Supporto iniziale focalizzato su device Mikrotik; altri vendor sono fuori scope per la Fase 1 se non esplicitamente indicati.

### Principi di progettazione

- L’intero dominio (IPAM, DCIM, Discovery, WAN/ISP) è progettato per essere **multi-tenant soft**, con isolamento per `Customer` e scoping opzionale per `Location`.  
- L’UI deve essere moderna, stile UniFi/NetBox, ma ottenuta **solo** tramite componenti Filament v4 (Table, Form, Infolist, Widgets, ecc.).  
- Il flusso dati è sempre tracciato: ogni modifica rilevante passa dall’**Activity Log** (Spatie Activitylog) e/o da eventi di discovery/merge espliciti.  
- Il sistema è progettato per funzionare in modalità **read-only** verso i device. Ogni feature che potrebbe implicare scrittura sui device deve essere esplicitamente vietata o contrassegnata come fuori scope.

### Priorità (sequenza di lavoro)

1. **Sicurezza e hardening**
   - Verifica/abilitazione SSL/TLS tra agent ↔ server e per l’accesso UI, con verify on per l’app.  
   - Rimozione o disabilitazione di endpoint diagnostici legacy non protetti.  
   - Gestione robusta del loop agent (backoff, rate control, gestione token).
2. **Schema e coerenza dati**
   - Pulizia definitiva di concetti legacy non più usati (es. `sections`, VRF, L2Domain) a livello di codice e di UI.  
   - Unicità VLAN per customer; normalizzazione storage `tagged_vlans`/`vlan_ids`.  
   - Tenancy: policy esplicite per API/CLI quando `tenant_id` manca; obbligo di `customer_id` per jobs/discovery.
3. **Discovery/Agent pipeline**
   - Contratto formale del payload Mikrotik REST + `device_result`.  
   - Gestione coerente di errori di rete/autenticazione (`AUTH_ERROR`, `NET_TIMEOUT`, HTTP 4xx/5xx).  
   - Pipeline di ingest e merge con DiscoveryEvents e audit consistenti.
4. **DCIM/IPAM UX e dati**
   - Chiarire e consolidare l’unica `DeviceResource` definitiva (Filament), dismettendo eventuali duplicati.  
   - UI porte/VLAN: gestione unificata di VLAN untagged/tagged solo da DCIM, con riferimento all’entità VLAN di IPAM.  
   - Vista rack “tipo ECCM” completamente in Filament.
5. **Observability e test**
   - Copertura test su IngestMikrotikInventoryAction, DiscoveryEvent approval, edge case di tenancy e job schedulati.  
   - Metriche/logging per discovery success/fail, agent health e uso dei job schedulati.

### Deliverable attesi

- Patch set progressivo per le priorità 1–3 con test aggiornati.  
- Decision log per tenancy enforcement, rimozione concetti legacy e consolidamento DeviceResource.  
- KB aggiornata (`docs/*`) dopo ogni milestone, con questo PDL mantenuto aderente al codice reale.

---

## 1. Visione, scope e moduli logici

### 1.1 Visione

Sistema ibrido **IPAM + DCIM** per MSP che:

- mappa Subnet, IP, VLAN, ruoli IP, occupancy e regole di auto-creazione;  
- gestisce DCIM (Device, Ports, Rack, Links, alimentazione, WAN/ISP);  
- integra discovery automatizzata tramite agent remoti (Mikrotik REST, fping, ecc.);  
- offre un workflow di merge/approvazione dei dati che mantiene coerente la “source of truth”.

Il sistema deve essere utilizzabile:

- sia in modalità “manuale” (solo UI/IPAM/DCIM);  
- sia in modalità “assistita” da agent (discovery periodico + merge semi-automatico).

### 1.2 Scope funzionale

- **IPAM**
  - Gestione Subnet, IP, occupancy.  
  - Gestione VLAN per customer, con associazione Subnet ↔ VLAN.  
  - Ruoli IP (gateway, DNS, host, altro) e prenotazioni.  
  - Regole di auto-creazione Subnet/IP per cliente (es. da PVID/VLAN).
- **DCIM**
  - Gestione Device, DeviceModel, Ports, Racks, Links fisici.  
  - Modellazione alimentazione e WAN/ISP a livello device/porta.  
  - Label/QR generati via PDF per uso sul campo.
- **Discovery & Agent**
  - Agent remoti multi-customer, uno o più per MSP.  
  - Jobs di discovery (ICMP, Mikrotik REST deep discovery).  
  - Generazione di `device_result` normalizzato e pipeline di ingest.  
  - Gestione conflitti tramite `DiscoveryEvent` e vista di merge UI.
- **Logging & Audit**
  - Logging unificato (server + agent) con formato coerente.  
  - Activity log tramite Spatie Activitylog con retention configurabile.
- **Settings & Notifications**  
  - Settings globali via Spatie Settings, organizzati in gruppi:
    - `GeneralSettings` (`app/Settings/GeneralSettings.php`) esposto via `GeneralSettingsPage` (Filament).  
    - `DiscoverySettings` (`app/Settings/DiscoverySettings.php`) esposto via `DiscoverySettingsPage`.
      - Campi aggiuntivi richiesti (Fase 1):
        - `queue_backlog_warning_threshold` (int)
        - `queue_backlog_critical_threshold` (int)
    - `NotificationSettings` (`app/Settings/NotificationSettings.php`) esposto via `NotificationSettingsPage` (Fase 1: webhook/logica semplice).
      - Schema di `NotificationSettings` (Fase 1 + predisposizione Fase 2 Telegram):

        ```php
        [
          'webhook_enabled' => bool,
          'webhook_url' => string|null,

          'telegram_enabled' => bool, // Fase 2: solo predisposizione
          'telegram_bot_token' => string|null,
          'telegram_chat_id' => string|null,

          'notify_on' => [
             'agent_offline' => bool,
             'agent_online' => bool,
             'discovery_error' => bool,
             'discovery_conflict' => bool,
             'backup_completed' => bool,
             'backup_failed' => bool,
          ],

          'rate_limit_seconds' => int, // default 300
        ]
        ```

      - Webhook payload (Fase 1): inviare POST JSON con schema vincolante:

        ```json
        {
          "event_type": "agent_offline|agent_online|discovery_error|discovery_conflict|backup_completed|backup_failed",
          "tenant_id": 12,
          "device_id": 33,
          "agent_id": 2,
          "job_id": "uuid",
          "timestamp": "ISO8601",
          "severity": "info|warning|error|critical",
          "payload": { }
        }
        ```

      - Rate-limiting (Fase 1): memorizzare per tenant + `event_type` l'ultimo invio; non inviare nuovamente lo stesso evento per lo stesso tenant finché non sono passati `rate_limit_seconds`.
    - `SecuritySettings` (`app/Settings/SecuritySettings.php`) esposto via `SecuritySettingsPage`.  
    - `FeatureFlagSettings` (`app/Settings/FeatureFlagSettings.php`) esposto via `FeatureFlagSettingsPage` (minimal in Fase 1).  
    - `SystemStateSettings` (`app/Settings/SystemStateSettings.php`) senza pagina UI (read-only, usato per health/install state).
      - Campi aggiuntivi richiesti (Fase 1):
        - `agent_offline_threshold_seconds` (int, default 120)
  - Integrazione Telegram per notifiche operative.
- **Export/Import & PDF**
  - Export CSV/XLSX via Filament.  
  - Export PDF tramite `torgodly/html2media` (HTML → PDF).
- **Setup Wizard**
  - Wizard iniziale su nuova installazione, con migrazioni, primo admin, primo customer/location e diagnostica di base.

---

## 2. Architecture Overview

### 2.1 Monolite Laravel + Filament

- Applicazione monolitica Laravel 12 con pannello amministrativo Filament v4.  
- Nessun microservizio: l’applicazione server (control plane) è un singolo monolite; l’agent remoto è un processo separato che comunica via HTTP/HTTPS.

### 2.2 Componenti di runtime (Docker)

Come da `docs/01_INFRASTRUCTURE.md`:

- **Limite architetturale**  
  - Il prodotto è progettato esplicitamente per **deploy single-node**.  
  - Non è supportata alcuna configurazione HA/cluster, né bilanciamento multi-web.  
  - Aggiornamenti blue-green, roll-out multi-versione e scalabilità orizzontale non rientrano nello scope della Fase 1.

- **`netvibe-web` (app)**  
  - Contiene PHP-FPM + nginx (ServerSideUp base).  
  - Espone HTTP interno sulla porta 8080 (mappata esternamente, es. 9693→8080 nel compose).  
  - Healthcheck su `/up`.  
  - Serve sia UI Filament che API `/api/v1/*`.
- **`netvibe-worker` (worker)**  
  - Usa la stessa immagine dell’app.  
  - Comando di default:  
    - Worker “discovery”: `php artisan queue:work --queue=discovery --sleep=1 --tries=3`  
    - Worker “default/low”: `php artisan queue:work --queue=default,low --sleep=1 --tries=3`  
  - Processa jobs di discovery, ingest, export, backup, scheduled tasks.
  - In single-node i worker girano sullo stesso host del container web e condividono volume e rete.
- **`netvibe-db` (MariaDB)**  
  - MariaDB 11.x, volume persistente `dbdata`.  
  - Script `docker/db-init.sh` può eseguire seed/test DB.
- **`netvibe-redis` (Redis)**  
  - Redis 8.4 (immagine `redis:8.4`) per code, cache e (opzionalmente) sessioni.  
  - In configurazione standard viene usato senza AOF (`--appendonly no`) per semplicità operativa.  
  - Può essere assente in ambienti shared hosting; in quel caso si usano driver file/sync (vedi Setup Wizard).
- **Storage**  
  - Volume Docker nominato (es. `app-storage`) montato su `/var/www/html/storage`.  
  - Facoltativamente un volume separato per upload (es. `app-uploads`).

TLS e reverse proxy non sono responsabilità dell’app: terminazione TLS e routing HTTP(S) sono demandati a strumenti esterni (Nginx Proxy Manager, Traefik, Caddy, ecc.). Il PDL **non** prevede un nginx aggiuntivo oltre quello già incluso nell’immagine.

### 2.5 Single-node & dipendenza dal worker

- L’istanza NetVibe è **correttamente funzionante** solo se:
  - il container web/app è in esecuzione;  
  - almeno un worker è attivo per le code previste.
- Se il worker è offline o non raggiungibile:
  - tutte le funzionalità che richiedono queue (discovery, export, backup, notifiche, job schedulati) vengono disabilitate a livello UI e API.  
  - La System Health page (vedi §13) mostra un badge `Worker: DOWN` e un messaggio esplicito.
- `QUEUE_CONNECTION=sync`:
  - è ammesso solo in ambienti `local`/`testing`.  
  - in produzione (`APP_ENV=production`) è **vietato**: l’applicazione disabilita discovery/export/backup/notifications e mostra un banner di errore.

### 2.3 Multi-tenancy soft

- `Customer` è l’entità di tenancy:  
  - `customer_id` è richiesto per tutte le entità chiave (Subnets, IP, VLAN, Devices, Racks, Ports, ScanAgent, DiscoveryEvent, ecc.).  
  - Tenant scope per le query UI è applicato tramite `TenantAware` + `TenantScope`, attivo quando `session('tenant_id')` è presente.
- `Location` rappresenta:
  - Una sede fisica del cliente.  
  - L’ambito L2/logico di riferimento; non esiste più un’entità separata `L2Domain`.
- Ogni risorsa:
  - Ha almeno `customer_id`.  
  - `location_id` è presente dove ha senso (Subnet, Device, Rack, ScanAgent, ScheduledTask, ecc.).
- Tutti i job, le API e le pipeline di ingest:
  - ricevono sempre un `tenant_id` esplicito lato server (non dipendono da `session('tenant_id')`).  
  - sono tenute a validare/persistire `customer_id` in modo coerente con il tenant corrente.
- **Tenant Switcher UI**  
  - Presente nella top-bar Filament (inserito tramite hook di AdminPanelProvider).  
  - Cambiare tenant in UI influenza implicitamente le query Tenancy-aware, senza necessità di passare `customer_id` esplicitamente nella maggior parte dei form.

### 2.4 Moduli logici

- **IPAM**  
  - Subnets, IPAddresses, VLAN, ruoli IP, occupancy, auto-create rules per cliente, exclusions.  
  - Gateway detection e gestione delle subnet scoperte da Mikrotik.
- **DCIM**  
  - Device, DeviceModel, Ports, Racks, Links, alimentazioni, WAN/ISP, etichette/QR.  
  - Vista rack strutturata e relazione con i link fisici.
- **WAN/ISP**  
  - Modellazione logica di collegamenti WAN (provider, circuit ID, porte di terminazione sui device).
- **Discovery & Agent**  
  - Agenti remoti (ScanAgent) che consumano jobs e inviano `device_result`.  
  - Ingest, normalizzazione e merge tramite DiscoveryEvents.
- **Logging & Audit**  
  - Logging unificato (server + agent).  
  - ActivityLog (Spatie).
- **Settings & Notifications**  
  - Settings globali (Spatie Settings) per general, discovery, notifications, security, feature flags e system state, con i gruppi/classi definiti sopra.  
  - Integrazione Telegram per eventi chiave (agent offline, discovery errors, backup completati, ecc.).
- **Export/Import & PDF**  
  - Export CSV/XLSX da tabelle Filament.  
  - PDF tramite html2media.  
  - File generati su volume storage con retention configurabile.
- **Setup Wizard**  
  - Entry-point iniziale per nuove installazioni, con controlli di health, migrazioni e bootstrap entità base.

### 2.6 Struttura cartelle Laravel (server)

La struttura del progetto Laravel è standardizzata e funge da riferimento per il codice e per gli agent che contribuiscono:

```text
/
├── app/                         # Codice applicativo Laravel (core della domain logic)
│   ├── Actions/                 # Azioni atomiche, orchestrano singole operazioni (SOLID)
│   │   ├── Agent/               # Azioni specifiche per ingest, polling agent
│   │   ├── Discovery/           # Pipeline discovery (REST, SNMP, UniFi, ICMP)
│   │   ├── Ingest/              # Normalizzazione e upsert dati nel DB
│   │   ├── Merge/               # MergePolicyService + gestione campi locked (TODO: da consolidare)
│   │   ├── Snmp/                # SNMP parsing/normalizzazione (Fase 1, TODO: struttura da completare)
│   │   └── Health/              # Health check scheduler/worker (TODO: entrypoint centrale)
│   ├── Console/                 # Comandi artisan, scheduler Kernel
│   ├── Domain/                  # Moduli di dominio (DDD-lite; TODO: progressivo spostamento logica)
│   │   ├── Device/              # Logica DCIM, porte, layout rack
│   │   ├── IPAM/                # Subnet, IP, MAC, DHCP, ARP
│   │   ├── Discovery/           # Eventi, capability profiles, conflitti
│   │   ├── Snmp/                # Entità SNMP, preset, mappature MIB2
│   │   └── Wan/                 # Modello WAN, IP pubblici, NAT, provider
│   ├── Enums/                   # Enum PHP 8.2 (type safety per stati/capabilities)
│   ├── Exceptions/              # Eccezioni custom strutturate
│   ├── Filament/                # UI Filament v4 (unico framework UI supportato)
│   │   ├── Clusters/            # Aree funzionali: Discovery, IPAM, DCIM, Settings…
│   │   ├── Pages/               # Pagine non collegate a risorsa (System Health, Settings, ecc.)
│   │   ├── Resources/           # CRUD e viste entità (Device, IP, VLAN…)
│   │   └── Widgets/             # Dashboard, metriche, grafici
│   ├── Http/
│   │   ├── Controllers/         # Controller API e Web
│   │   │   ├── Api/Agent/       # Endpoint agent protocol (config, jobs, results)
│   │   │   └── Api/User/        # API per azioni asincrone lato UI
│   │   └── Middleware/          # Middleware: tenancy, auth token agent, wizard-lock, ecc.
│   ├── Jobs/                    # Tutti i job in queue
│   │   ├── Discovery/           # Job per Mikrotik/SNMP/UniFi/fping
│   │   ├── Export/              # CSV/XLSX/PDF export
│   │   ├── Backup/              # Backup DB (se implementato)
│   │   └── Notification/        # Telegram/email/webhook (Fase 2)
│   ├── Models/                  # Modelli Eloquent
│   ├── Observers/               # Listeners per eventi Eloquent
│   ├── Policies/                # RBAC / permessi
│   ├── Services/                # Servizi applicativi orizzontali
│   │   ├── DiscoveryJobService.php
│   │   ├── MergePolicyService.php
│   │   ├── SnmpService.php
│   │   ├── UnifiControllerService.php
│   │   └── HealthCheckService.php
│   ├── Settings/                # Spatie settings (General/Discovery/Notification/Security/Features/SystemState)
│   ├── Support/                 # Helpers, transformer, validazioni comuni
│   └── ViewModels/              # DTO per UI (pattern ViewModel; opzionale)
│
├── bootstrap/                   # Boot Laravel
├── config/                      # Config applicazione
├── database/
│   ├── migrations/              # Migrazioni SQL
│   ├── seeders/                 # Seeding dati
│   └── factories/               # Factory test
│
├── docs/
│   ├── docs-pdl/                # (TODO) Eventuale consolidamento PDL in sotto-cartella dedicata
│   ├── docs-laravelv12/         # Guide specifiche per Laravel 12
│   ├── docs-filamentv4/         # Guide per Filament v4
│   ├── docs-agent-protocol/     # Documentazione schema `device_result` e agent protocol
│   └── internal-diagrams/       # UML, architetture, diagrammi mermaid
│
├── docker/                      # Artefatti Docker (app, worker, agent)
├── resources/                   # Blade, asset, traduzioni
├── routes/                      # Web/API/console routes
├── storage/
│   ├── logs/                    # Log JSON strutturati
│   ├── app/exports/             # File export temporanei
│   └── app/backups/             # Backup DB (se abilitato)
│
├── tests/
│   ├── Feature/
│   ├── Unit/
│   ├── Integration/             # Test integration DB/queue (TODO: da consolidare)
│   ├── UI/                      # Laravel Dusk (wizard, flow UI) (TODO)
│   ├── AgentProtocol/           # Test schema `device_result` + chunking (TODO)
│   └── Snapshots/               # Snapshot testing (spatie/pest-plugin-snapshots)
│
├── .env.example
├── compose.yml                  # Docker Compose V2 (server stack)
└── README.md
```

Per il codice esistente che non rispecchia ancora questa struttura, il PDL impone un refactoring graduale: nuovi moduli devono rispettare il layout, quelli legacy devono essere migrati quando toccati.

### 2.7 Struttura progetto remote-agent

Il remote-agent vive in una directory dedicata (`remote-agent/` o `/agent/` a seconda del layout definitivo) con una struttura pensata per separare driver, job e support:

```text
/agent/
├── Drivers/                     # Ogni “sorgente” separata
│   ├── MikrotikRestDriver.php   # REST RouterOS 7+
│   ├── SnmpDriver.php           # SNMP Fase 1 (MIB-II)
│   └── UnifiDriver.php          # UniFi Controller API
├── Jobs/
│   ├── DiscoveryJob.php         # Discovery principale (Mikrotik/UniFi)
│   ├── SnmpEnrichmentJob.php    # Fase 1 SNMP (enrichment interfacce)
│   └── ConnectivityJob.php      # Ping/check agent → device
├── Http/
│   └── AgentApiClient.php       # HTTP client verso server (retry + backoff)
├── Support/
│   ├── DeviceResultBuilder.php  # Costruzione schema `device_result`
│   ├── ChunkEncoder.php         # Encoder chunking
│   ├── ChunkDecoder.php         # Decoder chunks
│   └── Logger.php               # Log JSON formattato (stderr + mirror server)
├── config/                      # Config locale agent (env, preset)
├── bin/
│   └── netvibe-agent            # CLI principale (entrypoint)
├── tests/                       # Test specifici agent (unit + integrazione protocol)
└── docs/                        # Documentazione agent
```

Lo stato attuale del `remote-agent/` è più semplice; il PDL definisce la struttura target. Nuove funzionalità (es. SNMP Fase 1, `device_result` completo, chunking) devono seguire questa organizzazione; il codice legacy deve essere migrato progressivamente.  
In aggiunta, il PDL fissa come **contratto vincolante**:

- un file `.env.example` per l’agent, nella root `remote-agent/`, con almeno:

  ```env
  SERVER_URL=http://ipam:9693
  AGENT_API_TOKEN=
  LOG_LEVEL=info
  AGENT_POLL_INTERVAL_SECONDS=5
  AGENT_CONFIG_REFRESH_SECONDS=60
  ```

- un `docker-compose.yml` (o `compose.yml`) dedicato all’agent, non incluso nello stack server principale, con servizio:

  ```yaml
services:
  netvibe-web:
    build:
      context: .
      dockerfile: Dockerfile.app
    env_file: .env
    ports:
      - "9693:8080"
    volumes:
      - netvibe_storage:/var/www/html/storage
      - netvibe_uploads:/var/www/html/public/uploads
    profiles: ["prod"]

  netvibe-web-dev:
    build:
      context: .
      dockerfile: Dockerfile.app
    env_file: .env
    ports:
      - "9693:8080"
    volumes:
      - .:/var/www/html
      - ./storage:/var/www/html/storage
      - ./public/uploads:/var/www/html/public/uploads
    profiles: ["dev"]

  netvibe-worker:
    build:
      context: .
      dockerfile: Dockerfile.app
    env_file: .env
    volumes:
      - netvibe_storage:/var/www/html/storage
      - netvibe_uploads:/var/www/html/public/uploads
    profiles: ["prod", "worker"]

  netvibe-worker-dev:
    build:
      context: .
      dockerfile: Dockerfile.app
    env_file: .env
    volumes:
      - .:/var/www/html
      - ./storage:/var/www/html/storage
      - ./public/uploads:/var/www/html/public/uploads
    profiles: ["dev"]

volumes:
  dbdata:
  redisdata:
  netvibe_storage:
  netvibe_uploads:
```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.app
    env_file: .env
    ports:
      - "8080:80"
    volumes:
      - .:/var/www/html
      - storage:/var/www/html/storage
    depends_on:
      - db
      - redis
    profiles: ["dev", "prod"]

  worker:
    build:
      context: .
      dockerfile: Dockerfile.app
    env_file: .env
    volumes:
      - .:/var/www/html
    depends_on:
      - app
      - redis
    profiles: ["prod", "dev", "worker"]

  db:
    image: mariadb:11
    environment:
      MYSQL_DATABASE: netvibe
      MYSQL_USER: netvibe
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - dbdata:/var/lib/mysql
    profiles: ["dev", "prod", "test"]

  redis:
    image: redis:8.4
    command: ["redis-server", "--appendonly", "no"]
    volumes:
      - redisdata:/data
    profiles: ["dev", "prod"]

  test-db:
    image: mariadb:11
    environment:
      MYSQL_DATABASE: netvibe_test
      MYSQL_USER: netvibe
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root
    tmpfs:
      - /var/lib/mysql
    profiles: ["test"]

volumes:
  storage:
  dbdata:
  redisdata:
```

Note:

- Non esiste più un `docker-compose.yml` legacy: il file canonico è `compose.yml`.  
- Il remote-agent può avere un proprio `compose.yml` separato o essere gestito con `docker run` dedicato; la sua definizione non è inclusa nello stack server principale.

### 2.9 Bootstrap e comandi di sviluppo

Per uniformare l’esperienza degli sviluppatori e degli agent:

- **Ambiente dev**
  - `docker compose --profile dev up -d`
  - `docker compose exec netvibe-web-dev composer install`
  - `docker compose exec netvibe-web-dev php artisan migrate`
  - `docker compose exec netvibe-web-dev npm run dev`
- **Ambiente test**
  - `docker compose --profile test up -d`
  - `docker compose exec netvibe-web-dev php artisan test` (oppure gli script `./dev/test*` descritti nel capitolo Testing/QA)
- **Build/Deploy produzione**
  - `docker compose --profile prod up -d --build`
  - `docker compose exec netvibe-web php artisan migrate --force`

Qualsiasi documentazione, script o guida che faccia riferimento a `docker-compose.yml` o a comandi `docker-compose` è da considerarsi legacy e va aggiornata a `compose.yml` / `docker compose`.

---

## 3. Storage & volumi

### 3.1 Layout storage Laravel

- `storage/app`  
  - File applicativi e user-generated: exports, reports, backup DB, etichette PDF, ecc.  
  - Sottocartella dedicata per backup DB, es. `storage/app/backups`.  
  - Sottocartella per exports, es. `storage/app/exports`.  
  - Altre cartelle specializzate possono essere aggiunte, mantenendo la radice su volume Docker.
- `storage/logs`  
  - Log Laravel (server), configurati per seguire il formato unificato ove possibile.  
  - Log access/errore di nginx rimangono a livello di immagine, fuori scope applicativo.

Tutta la directory `storage` è montata su un Docker named volume, es. `app-storage`.

### 3.2 Pro e contro del volume dedicato

- **Pro**
  - Persistenza tra riavvii/ricostruzioni di container.  
  - Backup e restore a livello volume (snapshot) semplificati.  
  - Isolamento dei dati applicativi rispetto alla root dell’immagine.
- **Contro**
  - Richiede una configurazione `docker-compose` (o equivalente) consapevole dei volumi.  
  - In ambienti shared hosting (es. CloudPanel) può richiedere binding manuale della directory.

### 3.3 File generati e retention

- Tutti i file generati dall’app (PDF, CSV, XLSX, backup DB, exports di log, ecc.) vivono sotto il volume storage.  
- Tutti questi file sono considerati **volatili** (rigenerabili) e non devono essere l’unico meccanismo di backup.  
- Esiste un sistema di **retention configurabile via UI** che:
  - Permette di impostare dopo quanti giorni eliminare automaticamente i file generati (default 7 giorni).  
  - Può avere parametri distinti per:
    - backup DB (`backup_retention_days`),  
    - log esportati,  
    - exports PDF/CSV (`file_export_retention_days`, `TODO: nome esatto setting`).
- La cancellazione avviene tramite job schedulato (vedi Scheduled Tasks / cron).

---

## 4. Initial Setup & Wizard

### 4.1 Condizioni di attivazione

All’avvio del container/app, la base URL `/` reindirizza al **Setup Wizard** se:

- Il database risulta vuoto / migrazioni non eseguite (`php artisan migrate` non ancora eseguito con successo).  
- Oppure esiste il DB ma **non esiste alcun utente admin** (es. nessun utente con ruolo `admin` o equivalente).

Se entrambe le condizioni sono false, `/` punta alla dashboard Filament normale.

### 4.2 Passi del wizard

Il wizard Fase 1 guida l’utente attraverso i seguenti step:

1. **Preflight**
   - PHP version & estensioni (openssl, pdo, mbstring, tokenizer, xml).  
   - Permessi su `.env`, `storage/`, `bootstrap/cache/`.  
   - Visualizzazione di un pannello con esito dei test (pass/fail).
2. **Migrazioni DB**
   - Esegue `php artisan migrate --force`.  
   - Mostra stato di avanzamento e output sintetico.  
   - In caso di errore, espone messaggio dettagliato + link alla documentazione.
3. **Creazione primo utente admin**
   - Form con: nome, email, password (policy minime).  
   - Helptext localizzabile (en/it).
4. **Creazione primo Customer e Location**
   - Creazione del primo `Customer`.  
   - Creazione di una `Location` di default (“Default”) associata al customer (rinominabile in seguito).
5. **Configurazione base app**
   - `app_url` (URL pubblico dell’istanza).  
   - `locale` (en/it) per UI e traduzioni.  
   - Scelta queue driver:
     - `redis` (raccomandato).  
     - `sync` solo per ambienti non‑production (con warning chiaro).  
   - Avviso su necessità di worker e scheduler (vedi passo cron).

Tutti i campi del wizard hanno **helptext dettagliati** e localizzabili (en/it).

### 4.3 Wizard e ambienti senza Redis (CloudPanel, shared hosting)

Se Redis non è raggiungibile durante il wizard:

- Il wizard propone automaticamente un fallback di configurazione **solo per ambienti non-production**:
  - `SESSION_DRIVER=file`  
  - `QUEUE_CONNECTION=sync`  
  - `CACHE_DRIVER=file`
- In produzione (`APP_ENV=production`):
  - `QUEUE_CONNECTION=sync` non è supportato: il wizard mostra un warning e indica che discovery/export/backup/notifications saranno disabilitati fino alla configurazione di Redis.  
  - È esplicitato che per un uso “completo” del prodotto in produzione è **necessario** configurare Redis come backend di queue/cache.

### 4.4 Cron e schedule detection

Il sistema richiede che `php artisan schedule:run` sia eseguito almeno una volta al minuto.

Se l’app rileva (es. tramite una timestamp di ultima esecuzione mantenuta in settings/tabella) che `schedule:run` **non** viene eseguito:

- Il wizard mostra:
  - Uno snippet cron standard:

    ```bash
    * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
    ```

  - Uno snippet tipico per pannelli tipo cPanel/hosting:

    ```bash
    * * * * * php -q /home/USER/app/artisan schedule:run
    ```

- La pagina spiega che senza scheduler attivo:
  - I job schedulati (discovery pianificata, prune log, retention backup, ecc.) non verranno eseguiti.  
  - Le notifiche asincrone potrebbero non essere inviate.

### 4.5 Pannello “Diagnostic / Debug log”

All’interno del wizard è presente un pannello dedicato alla diagnostica:

- Mostra gli ultimi errori di bootstrap rilevanti, ad esempio:
  - errori di connessione DB;  
  - errori di connessione Redis;  
  - errori nella configurazione email;  
  - errori di permessi su `storage`.
- Fornisce un pulsante **Refresh** che rilancia i test di connessione.  
- Tutti i messaggi sono localizzabili (en/it) e legati al formato log unificato (vedi sezione Logging).

### 4.6 Fase 2 (estensioni wizard)

Le seguenti funzionalità sono previste come estensioni opzionali (Fase 2), non obbligatorie al primo avvio:

- Test connessione email (SMTP):
  - configurazione SMTP minimale (host, port, encryption, user/pass);  
  - pulsante “Send test email” con feedback di successo/fallimento.
- Test integrazione Telegram:
  - configurazione `telegram_bot_token` e `telegram_chat_id`;  
  - pulsante “Send test Telegram message” con log sintetico.
- Diagnostica avanzata:
  - riepilogo health di DB/Redis/queue/scheduler based su `system_state`.  
  - suggerimenti di configurazione per ambienti particolari.

Stato installazione (`system_state`):

```json
{
  "installed": true,
  "configured": true,
  "last_scheduler_run_at": "2025-01-01T12:00:00Z",
  "last_worker_heartbeat_at": "2025-01-01T12:00:10Z",
  "last_health_check_at": "2025-01-01T12:01:00Z"
}
```

- `CheckSystemInstalled` utilizza almeno `installed` e `configured` per determinare se reindirizzare a `/setup` o alla dashboard.  
- I campi `last_*` sono usati dalla System Health page, non dal wizard base.

---

## 5. Logging, Activity Log & Audit

### 5.1 Formato log unificato

Tutti i log applicativi (server e agent) adottano un **formato JSON strutturato**, ad esempio:

```json
{
  "timestamp": "2025-01-01T12:34:56Z",
  "level": "INFO",
  "component": "SERVER_DISCOVERY",
  "message": "Discovery job completed",
  "context": {
    "job_id": "uuid",
    "device_id": 42,
    "tenant_id": 3
  }
}
```

- `timestamp` è sempre in UTC, formato ISO8601.  
- `level ∈ {DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL}`.  
- `component` esempi: `AGENT_CORE`, `AGENT_MIKROTIK`, `SERVER_DISCOVERY`, `SERVER_INGEST`, `SERVER_UI`, `SERVER_JOB`, `SERVER_SCHEDULE`.  
- `message`:
  - Sempre in inglese, chiaro e sintetico.  
  - Gli eventi “normali” possono avere emoji alla fine (es. `Job completed ✅`), ma le emoji non devono essere l’unico veicolo di informazione.  
- `context` contiene chiavi aggiuntive (tenant_id, device_id, job_id, ecc.) usate per tracing e debugging.

Tutti i canali di log “nostri” (applicativi) sono configurati per usare questo formato JSON; eventuali deviazioni (log di sistema/terze parti) sono documentate separatamente.

### 5.2 Logging agent

- L’agent scrive sempre su **STDERR** usando il formato unificato sopra.  
- Per ciascun agent, la UI consente di configurare:
  - un `LOG_LEVEL` minimo (globale)  
  - eventuali override per singolo agent (`TODO: verificare/esporre chiavi settings`).
- L’agent può, opzionalmente, inviare log al server via endpoint REST:
  - `POST /api/v1/agent/logs`  
  - Il payload contiene livello, messaggio, contesto JSON, riferimento allo ScanAgent.
- Il server:
  - Persist i log in `agent_logs`.  
  - Aggiorna `last_seen` per l’agent ad ogni log o ping.  
  - Può generare notifiche (es. Telegram) quando il livello è ≥ soglia configurata (`agent_alert_level` nelle GeneralSettings).

La retention dei log agent è gestita dal console scheduler (vedi `docs/06_OBSERVABILITY_AND_TESTS.md`), parametrizzata da impostazioni tipo `prune_logs_days`.

### 5.3 Activity Log / Audit (Spatie Activitylog)

- Il sistema usa **Spatie Activitylog** per tracciare in modo strutturato le azioni utente.  
- Eventi tracciati (non esaustivo ma vincolante come minima copertura):
  - Creazione, modifica, eliminazione di:
    - Subnet, IPAddress, VLAN  
    - Device, Rack, Port, Link  
    - WAN/ISP (entità lato DCIM)  
    - Settings (general, discovery, notifications, backup, ecc.)  
    - ScanAgent/Agent, Location, Customer
  - Decisioni di merge discovery:
    - Approva / rifiuta un `DiscoveryEvent` (merge decision per IP, MAC, device, porta, VLAN...).  
  - Operazioni di sistema:
    - Avvio di un backup DB.  
    - Generazione di export (PDF/CSV/XLSX) per entity critiche.  
    - Creazione/modifica/cancellazione di ScheduledTask.
- **Retention Activity Log**
  - Configurabile via setting, ad es. `audit_log_retention_days`.  
  - Job schedulato di prune che rimuove record oltre la retention.

### 5.4 UI Activity Log

- **Resource globale “Activity Log”**  
  - Visibile a ruoli amministrativi.  
  - Filtro per `causer` (utente), `subject` (entità), `event` e data.  
  - Possibilità di vedere il diff (old/new values) quando disponibile.
- **Relation “Activity” per entità chiave**  
  - Ogni Resource Filament per Device, Subnet, VLAN, IP, Rack, Location, Customer, ecc. espone una relation “Activity” che filtra il log per quell’entità.  
  - Vista tipica: tabella in read-only con timestamp, user, tipo evento, descrizione sintetica.

---

## 6. IPAM e DCIM: separazione concettuale

### 6.1 IPAM

IPAM è responsabile di:

- `subnets`  
  - Rappresentazione CIDR (rete + mask) con conversione decimale/stringa (IpConversionService).  
  - Relazioni con VLAN, ScanAgent (per discovery) e customer/location.  
  - Flag di discovery (es. `discover_subnet`) e parametri ICMP.  
  - Gateway detection:  
    - Il gateway può essere dedotto (es. `.1`) o indicato esplicitamente da un IP marcato come gateway.
- `ip_addresses`  
  - Rappresentazione decimale + `ip_str`.  
  - Tipi (es. host, gateway, DNS, reserved, ecc. — elenco preciso in codice).  
  - Associazione a subnet, device/port, customer e location.  
  - Stato (free, used, reserved, orphan, ecc.) + `last_seen`.
- `vlans`  
  - Scoped per customer.  
  - Associazione opzionale a location (vedi L2).  
  - Opzionale flag per indicare se la VLAN è globale o limitata ad una location (*TODO: definire schema esatto flag*).
- **Regole di auto-creation & exclusions**
  - Auto‑creazione subnet `/24` durante ingest ARP/DHCP:
    - quando durante l’ingest (ARP/DHCP) compare un IP `A.B.C.X` che non ricade in alcuna subnet esistente del tenant, il sistema deve creare automaticamente la subnet `A.B.C.0/24`;  
    - la subnet generata deve avere:
      - `customer_id` uguale a quello del device / ScanAgent che ha eseguito la discovery,  
      - `discover_subnet = true`,  
      - `scan_agent_id` uguale all’agent che ha eseguito la discovery,  
      - `created_by_discovery = true` (campo boolean esplicito a schema);  
    - se la subnet `A.B.C.0/24` è presente in `SubnetBlacklist` per quel `customer_id`:
      - non deve essere ricreata automaticamente,  
      - non deve più essere popolata dalla discovery (niente nuovi IP o aggiornamenti).  
  - Regole di esclusione per prefissi, IP o VLAN che non devono essere auto-creati (blacklist configurabili per customer, vedi `SubnetBlacklist`/`VlanBlacklist`).

### 6.2 DCIM

DCIM gestisce la parte fisica:

- `device_models`  
  - Template hardware tipizzati (porte, layout).  
  - Quick-create con normalizzazione enum (type, vendor, form factor, ecc.).
- `devices`  
  - Tenant-aware: `customer_id` inferito da session/location/rack.  
  - Genera porte on-create (GenerateDevicePortsAction).  
  - Fields di monitor/auth (credential profile, management_ip, scan_agent_id, auth_status, ecc.).  
  - Integrazione con discovery per last_seen, OS version, model, serial, ecc.
- `ports`  
  - Metadati VLAN/topologia: `pvid`, `tagged_vlans` (array normalizzato), stato link, connected_to_port_id.  
  - UI per connect/disconnect via ManageLinkService, con guardie su self/duplicati.
- `links`  
  - Connessione tra due porte, con `cable_type` (dictionary).  
  - Validazioni per evitare duplicati e self-link.
- `racks`  
  - Relazione con customer/location.  
  - Contengono devices con posizione (U, lato).

### 6.3 VLAN e L2 scope

- La VLAN è un’entità IPAM (`vlans`), ma la sua applicazione su porte è gestita **solo** in DCIM (Ports).  
- Configurazione VLAN su porta:
  - Campo PVID (untagged).  
  - Campo `tagged_vlans` (array di VLAN ID) per trunk/tagged.  
  - Le opzioni di scelta VLAN sono filtrate per customer e, se previsto, per location.
- L2Domain è **eliminato come entità separata**:
  - L’ambito L2 è modellato tramite `location` + eventuali flag sulle VLAN (globali vs per location).  
  - Eventuali residui di VRF/L2Domain devono essere considerati debito tecnico e rimossi dal codice (vedi `docs/99_DEBT_AND_INCONSISTENCIES.md`).

### 6.4 Integrazione DCIM con SNMP (Fase 1)

SNMP Fase 1 ha come obiettivo arricchire il modello DCIM esistente, senza introdurre una seconda copia di entità device/port. Tutti i dati SNMP confluiscono nelle stesse entità usate da Mikrotik REST e dalla modellazione manuale.

- Scope vendor supportati:
  - Ubiquiti UniFi Network Controller (solo via controller; nessun accesso SNMP diretto agli switch/AP).  
  - Cisco (MIB-II standard).  
  - Fortigate (SNMP standard; MIB proprietarie fuori scope per Fase 1).  
  - HP/Aruba (MIB-II standard).
- Versioni SNMP supportate:
  - **SNMPv1** (massima compatibilità).  
  - **SNMPv2c** (migliori prestazioni).  
  - SNMPv3 **non** è implementato nella prima fase.
- Dati modellati in DCIM:
  - Porte fisiche e virtuali (interfaces) con:
    - `name`, `index`, `type`, `speed`, `status`, `mac`.  
    - Flag derivati: `is_physical`, `is_virtual`.  
  - Metadata device:
    - `hostname` (da `sysName` o mapping equivalente).  
    - `vendor` (da `sysObjectID`, es. `1.3.6.1.4.1.41112` ⇒ UniFi).  
    - `model` (parsato da `sysDescr`).  
    - `last_seen_snmp` (timestamp ultima discovery SNMP).
- Tabella schema (estensione prevista):
  - Tabella `snmp_settings` (vedi §7.14) legata a `devices` 1:1.  
  - Estensioni alla tabella `ports` (`device_ports` concettualmente):
    - `snmp_if_index` (index).  
    - `snmp_type`, `snmp_speed`, `snmp_status`.  
    - Flag `is_physical`, `is_virtual`.  
    - Campi di mapping opzionali (TODO verificare schema effettivo).  
  - Fino all’allineamento effettivo dello schema, queste colonne sono considerate obiettivi PDL e vanno marcate come `TODO` in migrazioni/implementazione.

### 6.5 UI DCIM e porte basate su SNMP

L’integrazione SNMP si riflette nella UI DCIM (Ports e vista rack):

- Ogni porta SNMP (o derivata da Mikrotik REST) diventa una riga/elemento della griglia porte nella Ports relation e nelle viste rack.  
- Stato colore porte:
  - verde = `ifOperStatus` up.  
  - rosso = `ifOperStatus` down.  
  - giallo = stato “degraded” o indeterminato (es. errori input/output, half-duplex, mismatch velocità).  
  - Se non disponibile alcun dato SNMP/Mikrotik, lo stato viene mostrato come “unknown” (colore neutro).
- Icona porta:
  - ethernet: `ifType` mappato a interfaccia rame (es. `ethernetCsmacd`).  
  - sfp/fibra: `ifType` mappato a porta ottica.  
  - wireless: `ifType = 71` (IEEE 802.11) tipico per AP UniFi.  
  - power/PoE: icona dedicata quando il dato PoE è disponibile.
- Hover / dettagli porta:
  - Mostra `speed`, descrizione (da SNMP: ifAlias/ifDescr), MAC, tipo porta.  
  - Mostra VLAN se note (da Mikrotik REST via DCIM → VLAN mapping).  
  - Può mostrare eventuali campi custom OID (es. `poe_power`, `temperature`) quando configurati.

Tutti questi elementi sono realizzati esclusivamente con componenti Filament (Badge, Icon, Infolist, Table), senza CSS custom.

### 6.6 DeviceResource — layout tabs e viste collegate

La `DeviceResource` unica (cluster “Racks & Devices”) espone una vista per‑device organizzata come segue (coerente con `docs/PDL_UX_UI.md §6.2`):

- Tab **Overview** (Infolist read‑only):  
  - mostra campi chiave del modello `Device` (`hostname`, `management_ip`, vendor/model/serial, stato health/auth, uptime);  
  - riflette lo stato dell’ultimo ingest/merge (ultimo job, canale, timestamp) senza consentire editing diretto.
- Tab **Discovery**:  
  - mostra lo storico discovery sintetico (Last scan, Health summary, REST status, SNMP status);  
  - include un viewer JSON del `device_result` più recente (pretty‑print, con enfasi su `schema_version` ed `errors[]`);  
  - espone azioni async obbligatorie: `Check connectivity`, `Full discovery`, `Test SNMP`.
- Tab **Networking**:  
  - espone i campi connessi a `management_ip` e alla topologia interfacce;  
  - ospita il `PortsRelationManager` come tabella principale per le porte del device, includendo `last_seen` e `discovered_by_scan_agent_id` come colonne dati;  
  - funge da punto di ingresso alla vista rack e ai link fisici.
- Tab **SNMP Settings**:  
  - ospita lo schema `snmp_settings` (connection, port name priority, custom OIDs) come descritto in §7.10;  
  - offre un entrypoint coerente per il job `snmp_discovery` e per la validazione config SNMP.

Questa struttura è vincolante: eventuali varianti di DeviceResource devono convergere su questo layout, e qualsiasi campo aggiuntivo va modellato come estensione di uno dei tab sopra (non come nuove resource parallele).

---

## 7. Agent, Discovery & Mikrotik REST

### 7.1 Agent design (riepilogo)

Riferimento di dettaglio: `docs/01_INFRASTRUCTURE.md`.

- Ogni agent è legato a un customer (no agent globali non scopiati) e opzionalmente a una location.  
- Config minima via env (contratto definitivo):
  - `SERVER_URL` (base URL dell’app, **senza** `/api` o `/api/v1`; l’agent aggiunge sempre `/api/v1/...` in modo autonomo).  
  - `AGENT_API_TOKEN` (PAT generata da UI).  
  - `LOG_LEVEL` opzionale.  
  - `AGENT_POLL_INTERVAL_SECONDS` (frequenza polling jobs, default 5s se non impostato).  
  - `AGENT_CONFIG_REFRESH_SECONDS` (frequenza polling `/api/v1/agent/config`, default 60s se non impostato).
- Tutte le altre impostazioni (polling, timeout, TLS verify toggle, limiti di dataset) sono spinte dal server e persistite lato agent (config file/cache) — dove non ancora implementato, usare `TODO`.
- Stati operativi agent:
  - Disconnesso a lungo: `last_seen` oltre soglia → badge rosso, possibile alert Telegram.  
  - Disabilitato da UI: il server non assegna job; ingest rifiutato (es. HTTP 403), l’agent entra in idle con backoff lungo.  
  - Crash/container bloccato: si fa affidamento su healthcheck + alert; l’agent è stateless salvo la configurazione persistita su volume.
- Canali:
  - Solo HTTPS verso il control plane (server).  
  - Nessun canale ingress richiesto verso l’agent.

#### 7.1.1 Configurazione runtime agent: `/api/v1/agent/config`

Endpoint di configurazione:

- `GET /api/v1/agent/config`
  - L’agent lo chiama circa ogni 60 secondi.  
  - Supporta header `If-None-Match` per usare ETag ed evitare di trasferire sempre la config completa.

Payload Fase 1 (contratto IMPLEMENTATO lato server):

```json
{
  "agent_id": "uuid",
  "min_agent_version": "1.0.0",
  "min_supported_schema_version": "1.0.0",

  "log_level": "info",

  "timeout_rest_ms": 8000,
  "timeout_snmp_ms": 5000,
  "tls_verify": false,

  "enable_rest": true,
  "enable_snmp": true,

  "max_records_per_dataset": 5000,

  "features": {
    "snmp_fase1": true,
    "chunking": true,
    "gzip": true
  }
}
```

Regole:

- `min_agent_version` e `min_supported_schema_version` sono letti da `SecuritySettings` e sono usati lato agent per decidere se la versione è compatibile col server.  
- `timeout_rest_ms`, `timeout_snmp_ms` e `max_records_per_dataset` provengono da `DiscoverySettings` e possono essere sovrascritti via `ScanAgent.settings` (chiavi `timeout_rest_ms`, `timeout_snmp_ms`, `max_records_per_dataset`).  
- `tls_verify` proviene da `SecuritySettings->agent_tls_verify` ed è sovrascrivibile via `ScanAgent.settings["tls_verify"]`.  
- `log_level` utilizza la priorità:
  - `ScanAgent.log_level`  
  - altrimenti `ScanAgent.settings["log_level"]`  
  - altrimenti default `"info"`.  
- `features` è costruito da `FeatureFlagSettings` (chiavi `snmp_fase1`, `chunking`, `gzip`).  
- In caso di conflitto tra global e agent:
  - vince l’impostazione specifica per agent (`ScanAgent.settings`).  
- L’agent:
  - ignora campi sconosciuti (forward compatibility).  
  - può usare ETag/If-None-Match per ridurre traffico: se la config non cambia, il server risponde `304 Not Modified` con solo header `ETag` aggiornato.

### 7.2 Timeouts Mikrotik REST

- Timeout massimo sul lato Mikrotik REST: **60s**.  
- L’agent deve utilizzare un timeout **inferiore** (50–55s) per evitare che la richiesta lato Laravel resti appesa più a lungo dell’API di Mikrotik.  
- Alcuni comandi (es. ping) supportano durata specificata nella richiesta; in Fase 1:
  - Non si usa ping via REST, oppure  
  - Si impone una durata massima molto bassa (`TODO: definire eventuale limite se implementato`).

### 7.3 MikroTik REST API examples

Per Mikrotik RouterOS 7, le chiamate REST di interesse sono:

- `/rest/system/resource` (system & hardware)  
- `/rest/system/identity`  
- `/rest/ip/arp`  
- `/rest/ip/address`  
- `/rest/ip/dhcp-server/lease`  
- `/rest/interface/bridge/vlan`  
- `/rest/ip/neighbor`  
- `/rest/interface/bridge/host`  
- Caso di errore: `401 Wrong Password` su `/rest/system/resource`.

Per ogni endpoint il PDL richiede:

1. **Command** (curl)  
2. **Sample Response** (JSON grezzo).

I blocchi JSON reali non sono inclusi in questo repository; per non violare il vincolo “non inventare”, si lasciano placeholder espliciti da riempire con output catturato da un device Mikrotik reale.

Esempio struttura per `/rest/system/resource`:

#### `/rest/system/resource`

**Command (curl)**  

```bash
curl -k -u "USER:PASSWORD" \
  "https://<DEVICE_IP>/rest/system/resource"
```

**Sample Response (JSON)**  

```json
/* TODO: incollare qui il JSON reale restituito dal device Mikrotik per /rest/system/resource */
```

La stessa struttura deve essere replicata nel PDL per:

- `/rest/system/identity`  
- `/rest/ip/arp`  
- `/rest/ip/address`  
- `/rest/ip/dhcp-server/lease`  
- `/rest/interface/bridge/vlan`  
- `/rest/ip/neighbor`  
- `/rest/interface/bridge/host`  
- `401 Wrong Password` su `/rest/system/resource` come esempio di errore:

```bash
curl -k -u "USER:WRONG_PASSWORD" \
  "https://<DEVICE_IP>/rest/system/resource"
```

```json
/* TODO: incollare qui il JSON reale di risposta 401 Wrong Password */
```

Fino a quando questi esempi non verranno popolati con output reale, il comportamento in codice deve rifarsi alla documentazione ufficiale Mikrotik e alle strutture di normalizzazione descritte sotto.

### 7.4 Normalizzazione JSON Mikrotik lato agent

L’agent è responsabile di:

- **Rimozione di campi interni**
  - Rimuovere `.id` da tutti i record Mikrotik.
- **Normalizzazione nomi chiave**
  - `mac-address` → `mac`  
  - `address` → `ip` (solo per ARP; altrove si mantiene il significato originario)  
  - `network` viene conservato come `network`  
  - `vlan-ids` → array di interi `vlan_ids`  
  - `tagged`, `untagged` → array di nomi interfacce (stringhe)
- **Conversione booleani**
  - Stringhe `"true"` / `"false"` vengono convertite in booleani reali `true`/`false`.
- **Conversione tempi**
  - Campi testuali di tempo (es. `uptime`, `expires-after`, `last-seen`, `age`) sono convertiti in:
    - un valore testuale “originale”, es. `uptime: "1d2h30m"`,  
    - un campo derivato `*_seconds`, es. `uptime_seconds: 95400`.
- **Strutturazione per blocco**
  - Ogni blocco (es. ARP, DHCP leases, neighbors, bridge hosts) è racchiuso in un oggetto con questa struttura:

    ```json
    "arp": {
      "items": [ /* array di record normalizzati */ ],
      "partial": false,
      "limit": null
    }
    ```

  - I nomi dei blocchi corrispondono al tipo di dato:
    - `arp`, `dhcp_leases`, `neighbors`, `bridge_hosts`, `vlans`, `ip_addresses`, `interfaces`, ecc.

Questa normalizzazione produce un payload interno consistente, indipendente dalla rappresentazione nativa Mikrotik.

### 7.5 Dataset grandi e partial dataset

Vincoli:

- Non è possibile sapere in anticipo la dimensione del dataset (es. numero di voci ARP).  
- Non esiste paginazione nativa nella REST Mikrotik per questi endpoint.  
- Non si può fare affidamento su filtri tipo `?interface=...` o simili (non documentati / non garantiti).

Strategia lato agent:

- L’agent legge lo stream JSON restituito da Mikrotik.  
- Conta gli elementi man mano che li normalizza.  
- Se il numero di elementi supera `MAX_RECORDS_PER_DATASET` (es. 2000, configurabile via server):
  - Trunca la lista  
  - Imposta `partial: true`  
  - Imposta `limit: MAX_RECORDS_PER_DATASET`

Esempio nel PDL:

```json
"arp": {
  "items": [ { /* primi 2000 record */ } ],
  "partial": true,
  "limit": 2000
}
```

Obblighi lato server:

- Il server deve gestire esplicitamente i partial dataset:
  - Loggare che non tutti gli elementi sono stati acquisiti (es. log livello WARNING su `SERVER_INGEST`).  
  - Esporre in UI (es. in DiscoveryEvent o nei dettagli discovery) un’indicazione che il dataset è incompleto.  
  - Non assumere mai che i dati siano completi quando `partial=true`.

`MAX_RECORDS_PER_DATASET` viene considerato un parametro configurabile (es. in DiscoverySettings); se non ancora presente, marcarlo come `TODO` e introdurlo in modo consistente.

### 7.6 Formato `device_result` agent → server

Il `device_result` è il payload **unico e normalizzato** che l’agent invia al server per ogni job di discovery device-centrico.

#### 7.6.0 Schema globale

Contratto Fase 1 (tutte le sezioni presenti, anche se vuote):

```json
{
  "schema_version": "1.0.0",
  "device": { },
  "interfaces": { "items": [], "partial": false },
  "vlans": { "items": [], "partial": false },
  "arp": { "items": [], "partial": false, "limit": null },
  "dhcp_leases": { "items": [], "partial": false, "limit": null },
  "neighbors": { "items": [], "partial": false },
  "bridge_hosts": { "items": [], "partial": false },
  "snmp": { "device": {}, "interfaces": { "items": [], "partial": false }, "errors": [] },
  "errors": []
}
```

- `schema_version`: stringa **semver** (`"1.0.0"` nella Fase 1).
  - Ogni **major** può introdurre breaking changes.  
  - Il server mantiene un `min_supported_schema_version` e rifiuta payload con major inferiore (HTTP `426` + body descrittivo).  
  - Minor/patch devono essere gestite in modalità forward‑compatible (il server ignora campi sconosciuti).  
  - L’agent legge `min_supported_schema_version` da `/api/v1/agent/config` e, se non compatibile, smette di inviare discovery (solo log/health) finché non viene aggiornato.

#### 7.6.1 Sezione `device` (OBBLIGATORIA)

```json
"device": {
  "ip": "10.0.0.1",
  "identity": "r1-siteA",
  "model": "hAP ax2",
  "serial": "ABC123456",
  "os_version": "7.20.5 (stable)",
  "board": "hAP ax^2",
  "platform": "mikrotik",
  "uptime_seconds": 123456,
  "architecture": "arm64"
}
```

- `ip`: string IPv4/IPv6 — obbligatorio.  
- `identity`: hostname del device.  
- `model`: stringa modello.  
- `serial`: serial number o `null`.  
- `os_version`: versione sistema operativo/firmware.  
- `board`: board/platform specifica o `null`.  
- `platform`: stringa (es. `mikrotik`, `unifi`, `generic`).  
- `uptime_seconds`: intero con uptime già convertito in secondi.  
- `architecture`: architettura CPU (es. `arm`, `arm64`, `x86_64`) o `null`.

#### 7.6.2 Sezione `interfaces`

```json
"interfaces": {
  "items": [
    {
      "index": 1,
      "name": "ether1",
      "type": "physical",
      "mac": "AA:BB:CC:DD:EE:FF",
      "speed_bps": 1000000000,
      "admin_status": "up",
      "oper_status": "up",
      "description": "Uplink ISP"
    }
  ],
  "partial": false
}
```

- `items[]`:
  - `index`: intero stabile per l’interfaccia (SNMP ifIndex o equivalente).  
  - `name`: nome interfaccia (es. `ether1`, `sfp1`, `br0`).  
  - `type`: enum `physical|bridge|vlan|loopback|virtual|other`.  
  - `mac`: MAC address o `null` (es. interfacce virtuali).  
  - `speed_bps`: velocità nominale in bps (`null` se sconosciuta).  
  - `admin_status`: `up|down|unknown`.  
  - `oper_status`: `up|down|unknown`.  
  - `description`: descrizione/alias (`ifAlias` o equivalente), o `null`.
- `partial`: `true` se l’elenco è stato troncato per limiti di dataset; altrimenti `false`.

#### 7.6.3 Sezione `vlans`

Fase 1: solo elenco VLAN (senza tagging/untagging per-port).

```json
"vlans": {
  "items": [
    { "vlan_id": 10, "name": "Users" }
  ],
  "partial": false
}
```

- `items[]`:
  - `vlan_id`: intero 1–4094.  
  - `name`: nome VLAN o `null`.  
- `partial`: sempre `false` in Fase 1 (se la sorgente non supporta elenco completo, la VLAN list può essere vuota).

#### 7.6.4 Sezione `arp`

```json
"arp": {
  "items": [
    {
      "ip": "10.0.0.100",
      "mac": "AA:BB:CC:DD:EE:01",
      "interface": "bridge1",
      "last_seen_seconds": 60
    }
  ],
  "partial": false,
  "limit": null
}
```

- `items[]`:
  - `ip`: IPv4 (ip host).  
  - `mac`: MAC address.  
  - `interface`: nome interfaccia locale dove è stato visto l’host.  
  - `last_seen_seconds`: `null` o età in secondi dall’ultima osservazione.  
- `partial`: `true` se lista troncata per limiti (es. MAX_RECORDS_PER_DATASET).  
- `limit`: `null` o intero con numero massimo di record inclusi quando `partial=true`.

#### 7.6.5 Sezione `dhcp_leases`

```json
"dhcp_leases": {
  "items": [
    {
      "ip": "10.0.0.101",
      "mac": "AA:BB:CC:DD:EE:02",
      "hostname": "host-101",
      "expires_at": "2025-01-01T13:45:00Z"
    }
  ],
  "partial": false,
  "limit": null
}
```

- `items[]`:
  - `ip`: IPv4.  
  - `mac`: MAC address.  
  - `hostname`: hostname del lease o `null`.  
  - `expires_at`: timestamp ISO8601 di scadenza o `null` (lease statico/infinito).  
- `partial`/`limit`: analogo a `arp`.

#### 7.6.6 Sezione `neighbors`

```json
"neighbors": {
  "items": [
    {
      "local_interface": "ether1",
      "remote_name": "SW-CORE",
      "remote_port": "Gi1/0/1",
      "remote_mac": "AA:BB:CC:DD:EE:FF",
      "remote_ip": "192.0.2.10"
    }
  ],
  "partial": false
}
```

- `items[]`:
  - `local_interface`: nome interfaccia locale.  
  - `remote_name`: nome remoto (LLDP/MNDP/CDP/controller API) o `null`.  
  - `remote_port`: nome porta remota o `null`.  
  - `remote_mac`: MAC remoto o `null`.  
  - `remote_ip`: IP remoto o `null`.
- `partial`: `true` se la lista è stata troncata o non completa; Fase 1 può usarlo anche come flag “dati incompleti”.

#### 7.6.7 Sezione `bridge_hosts` (Mikrotik)

```json
"bridge_hosts": {
  "items": [
    {
      "mac": "AA:BB:CC:DD:EE:03",
      "interface": "bridge1",
      "vlan_id": 10,
      "is_static": false
    }
  ],
  "partial": false
}
```

- `items[]`:
  - `mac`: MAC host.  
  - `interface`: ponte/interfaccia bridge.  
  - `vlan_id`: VLAN associata o `null`.  
  - `is_static`: boolean (MAC statico o dinamico).
- `partial`: come sopra.

#### 7.6.8 Modellazione VLAN ↔ Bridge ↔ Porte (Mikrotik)

Questa sezione definisce il contratto definitivo per la modellazione dei bridge e la fonte dati autorizzata per dispositivi Mikrotik RouterOS 7.

1) API RouterOS 7 da utilizzare (solo queste)

- Usare esclusivamente le API REST ufficiali e questi endpoint come fonte autorevole per la topologia bridge/VLAN/porte:

  - `/rest/interface/bridge`
  - `/rest/interface/bridge/vlan`
  - `/rest/interface/bridge/ports`
  - `/rest/interface`

- Non fare assunzioni su endpoint diversi; SNMP può solo arricchire, non contraddire i dati provenienti dalle API REST.

2) Struttura obbligatoria in `device_result`

Il `device_result` deve includere una sezione `bridges` con la seguente struttura obbligatoria (esempio):

```json
"bridges": {
  "items": [
    {
      "name": "bridge",
      "vlan_mode": "802.1q",
      "ports": [
        { "name": "ether1", "pvid": 10, "tagged": [], "untagged": true },
        { "name": "ether2", "pvid": 20, "tagged": [20,30], "untagged": false }
      ],
      "vlans": [
        {
          "vlan_id": 10,
          "tagged_ports": ["ether2"],
          "untagged_ports": ["ether1"]
        }
      ]
    }
  ],
  "partial": false
}
```

- Regole aggiuntive:
  - un device può avere 0, 1 o più bridge;
  - ogni bridge elenca le porte associate, il PVID di ciascuna porta, le porte `tagged`/`untagged` e l'elenco delle VLAN mappate su quel bridge;
  - questa sezione è separata dalla sezione `vlans` già esistente in `device_result` (che rimane un elenco "flat" di VLAN);
  - evitare duplicazioni, cicli o strutture ridondanti tra `bridges` e le altre sezioni di `device_result`.

3) Ingest lato server — contratto definitivo

- `IngestMikrotikTopologyAction` deve consumare la sezione `bridges` (quando disponibile) e:
  - aggiornare le porte (PVID, tagging, stato amministrativo/operativo, nome/descriptions);
  - aggiornare le relazioni VLAN ↔ porta ↔ bridge nel modello DCIM/IPAM;
  - registrare `DiscoveryEvent` quando vengono rilevati conflitti o configurazioni incoerenti (es. stessa porta in più bridge in conflitto, VLAN mappata in modo inconsistente rispetto alle porte);

- Regole di priorità e fonte autorevole:
  - i dati REST RouterOS 7 sono la fonte autorevole per bridges/ports/vlan mapping;
  - SNMP può integrare o arricchire i dati ma non deve contraddire i dati REST; in caso di contraddizione, il server genera un `DiscoveryEvent` e registra il conflitto per review;
  - `MergePolicyService` applica regole di merging/precedenza (es. `LOCKED beats remote`, `REST beats SNMP`, `Newest wins`) e deve essere il punto unico per la logica di merge;
  - qualsiasi conflitto o incoerenza deve tradursi in `DiscoveryEvent` strutturato e non in aggiornamenti silenti.

Nota: queste specifiche sono definitive e prevalgono su frammenti precedenti del PDL; l'implementazione server deve rimuovere code paths e mapping legacy incompatibili con questo contratto.

#### 7.6.8 Sezione `errors[]`

```json
"errors": [
  {
    "code": "NET_TIMEOUT",
    "source": "mikrotik_rest",
    "message": "Timeout while calling /rest/system/resource",
    "details": {
      "timeout_ms": 5000,
      "attempt": 1
    }
  }
]
```

- `code`: stringa enum (vedi error taxonomy §7.6.2).  
- `source`: enum `mikrotik_rest | snmp | unifi_api | agent | server`.  
- `message`: string breve in inglese.  
- `details`: oggetto arbitrario con meta-dati (es. timeout, url, vendor), o `null`.

Il server:

- ignora campi sconosciuti (forward compatibility);  
- non fallisce se una sezione ha `items: []` ma rispetta le indicazioni `partial/limit`;  
- logga e visualizza in UI gli errori significativi;  
- **non accetta più** payload legacy alternativi (es. `arp_table`, `dhcp_leases` fuori da `device_result`): tali formati sono considerati debito e vanno gestiti solo attraverso layer di compatibilità temporaneo, da rimuovere una volta migrati tutti gli agent.

#### 7.6.1 Trasporto, gzip e limiti di dimensione

- Tutti i `device_result` sono inviati su HTTP(S) in JSON.  
- **gzip**:
  - È opzionale per payload piccoli.  
  - Diventa **obbligatorio** per payload con dimensione post-compressione superiore a 1 MB.  
  - L’agent deve indicare `Content-Encoding: gzip` quando applica la compressione.
- **Chunking** (protocollo dettagliato nel design agent):
  - L’agent deve dividere in chunk i payload molto grandi (es. ≥ 1 MB post-compress), seguendo una strategia di `transaction_id` + `chunk_index/chunk_total` e checksum globale.  
  - Il server ricostruisce i chunk in modo atomico; se non riceve tutti i chunk entro un timeout configurabile, marca l’operazione come `CHUNK_INCOMPLETE`.
- **Limite lato server**:
  - Il server applica un limite massimo di 20 MB per il payload post-compress (somma dei chunk).  
  - Se viene superato il limite, risponde con HTTP `413 Payload Too Large` e un body JSON che include il campo `max_allowed_bytes`.  
  - L’agent deve reagire riducendo il dataset (es. via `MAX_RECORDS_PER_DATASET`) e ritentando.

#### 7.6.2 Error taxonomy (Fase 1) & HTTP mapping

Enum errori Fase 1 (lista chiusa):

- **Connectivity**
  - `NET_TIMEOUT`  
  - `UNREACHABLE`  
  - `TLS_ERROR`  
  - `CONNECTION_REFUSED`
- **REST/Mikrotik**
  - `AUTH_ERROR`  
  - `API_UNAVAILABLE`  
  - `API_ACCESS_DENIED`  
  - `API_VERSION_TOO_LOW`  
  - `SCHEMA_MISMATCH`  
  - `INVALID_RESPONSE_FORMAT`  
  - `INCOMPLETE_DATASET`
- **SNMP**
  - `SNMP_TIMEOUT`  
  - `SNMP_AUTH_ERROR`  
  - `SNMP_UNSUPPORTED`  
  - `SNMP_INVALID_OID`
- **Transport/payload**
  - `PAYLOAD_TOO_LARGE`  
  - `CHUNK_INCOMPLETE`  
  - `CHUNK_CHECKSUM_FAILED`
- **Server/Internal**
  - `INTERNAL_ERROR`  
  - `DATABASE_ERROR`  
  - `MERGE_CONFLICT`

Mapping principali errore → HTTP status:

- `API_VERSION_TOO_LOW` → `426 Upgrade Required`  
- `PAYLOAD_TOO_LARGE` → `413 Payload Too Large`  
- `AUTH_ERROR` / `SNMP_AUTH_ERROR` → `401 Unauthorized`  
- `API_ACCESS_DENIED` → `403 Forbidden`  
- `INVALID_RESPONSE_FORMAT` → `422 Unprocessable Entity`  
- `NET_TIMEOUT` / `SNMP_TIMEOUT` → `504 Gateway Timeout`  
- `API_UNAVAILABLE` → `503 Service Unavailable`  
- `CHUNK_INCOMPLETE` → `408 Request Timeout`  
- `INTERNAL_ERROR` / `DATABASE_ERROR` / `MERGE_CONFLICT` → `500 Internal Server Error`

#### 7.6.3 DiscoveryEvent: tipi & categorie

Tipi di DiscoveryEvent (lista chiusa Fase 1):

- `ip_conflict`  
- `mac_conflict`  
- `hostname_change`  
- `serial_change`  
- `vlan_change`  
- `interface_status_change`  
- `wan_change`  
- `merge_conflict`  
- `connectivity_error`  
- `protocol_error` (REST/SNMP)  
- `schema_error`

Categorie:

- `connectivity`  
- `rest`  
- `snmp`  
- `merge`  
- `system`

`MergePolicyService` genera DiscoveryEvents popolando:

- `type` (uno dei sopra)  
- `category`  
- payload diff old/new strutturato per campo.  

La UI usa queste informazioni per filtrare e presentare correttamente gli eventi.

Schema definitivo DiscoveryEvent (estensione Fase 1):

```php
[
  'agent_job_id' => UUID|null, // FK → agent_jobs.id
  'device_id' => int|null,
  'ip_address_id' => int|null,
  'port_id' => int|null,
  'event_type' => string, // uno degli enum di tipo
  'field' => string|null, // es. "hostname", "mac_address", "pvid"
  'old_value' => mixed,
  'new_value' => mixed,
  'source' => "REST|SNMP|Manual",
  'severity' => "info|warning|error",
]
```

Regole di correlazione:
- Ogni `DiscoveryEvent` generato durante l’ingest DEVE avere `agent_job_id = $job->id` quando l'evento è creato contestualmente ad un job di discovery. Se l’evento è globale (non associato a job device-specifico) `agent_job_id` sarà `null`.

### 7.7 Discovery & Merge Pipeline

Flusso completo:

1. **Configurazione Device**
   - L’utente crea o modifica un `Device` in DCIM e configura:
     - `management_ip`  
     - Credenziali tramite `CredentialProfile` (username/password o equivalente cifrato).
   - Il device è associato a un `ScanAgent` esplicito o implicito (regole in `DispatchDeviceJobAction`).
2. **Lancio job di discovery**
   - L’utente lancia manualmente un job di discovery dal Device (azione Filament)  
   - Oppure un job schedulato (vedi ScheduledTasks) scatena il discovery.
   - Il server crea un `ScanDeviceJob` (nome simbolico; implementazione effettiva in `agent_jobs`) e lo mette in coda.
3. **Esecuzione da parte dell’agent**
   - Un agent assegnato al customer/location polla jobs tramite `AgentJobController@index`.  
   - Riceve il job con:
     - `device_id`  
     - `management_ip`  
     - Credenziali (da `CredentialProfile`)  
     - Timeout e parametri discovery.
   - L’agent contatta Mikrotik via REST, esegue le chiamate previste (/rest/system/resource, /rest/ip/arp, ecc.), normalizza i dati e costruisce il `device_result`.  
   - Il `device_result` viene compresso (es. gzip) e inviato al server tramite `AgentJobController@storeResult` o endpoint dedicato.
4. **Ingest lato server**
   - Il server valida `schema_version`.  
   - Passa il payload a `ProcessAgentJobResultAction`, che:
     - Aggiorna lo stato di connettività del device (auth_status, last_seen).  
     - Chiama azioni di ingest specifiche (es. IngestMikrotikTopologyAction, IngestMikrotikArpAction, IngestMikrotikInventoryAction).
   - In base alle regole configurate (auto-create, exclusions, tenancy), crea o aggiorna:
     - Device/Ports/Subnet/VLAN/IP addresses.
5. **DiscoveryEvents (conflitti)**
   - Per ogni mismatch o caso non auto-risolvibile (MAC mismatch, IP orfano, VLAN inattesa, device sconosciuto, ecc.) viene creato un `DiscoveryEvent`.  
   - Esempi di tipologia evento (nomi indicativi, verificare col codice): `MAC_MISMATCH`, `ORPHAN_IP`, `UNMANAGED_DEVICE`, ecc.
6. **UI di merge**
   - L’utente vede i `DiscoveryEvents` in Filament (DiscoveryEventResource).  
   - Aprendo un evento, vede una **vista comparativa campo-per-campo**:
     - Valore attuale in DB.  
     - Valore remoto (da agent/Mikrotik).  
   - L’utente può:
     - Scegliere per ogni campo se mantenere il valore locale o prendere quello remoto (checkbox, toggle).  
     - Applicare template di decisione:
       - “Trust remote per hostname & serial”  
       - “Mantieni IP manuali, aggiorna solo last_seen”  
       - Altri template definiti in base ai casi.
7. **Post-merge**
   - Dopo il merge:
     - I dati vengono aggiornati in DB (in transazione).  
     - Viene creato un record di ActivityLog dettagliato con il diff applicato.  
     - L’evento viene marcato come risolto (es. stato `RESOLVED`).

### 7.8 Merge Policy Service (LOCKED / REST / SNMP / Newest)

La logica di merge tra dati locali e dati di discovery (REST Mikrotik, SNMP, UniFi, subnet scan) è centralizzata in un componente dedicato, es. `MergePolicyService`. Le regole contrattuali di base sono:

- **LOCKED beats remote**
  - Se un campo è marcato come “locked/manual” sull’entità (es. hostname, descrizione, ruolo IP), i valori di discovery **non** possono sovrascriverlo automaticamente.  
  - L’unico modo per cambiare il valore è tramite azione esplicita dell’utente nella UI di merge (DiscoveryEvent).
- **REST beats SNMP**
  - Per i device Mikrotik, REST è la fonte autorevole per:
    - identity (hostname, model, serial, board, os_version).  
    - struttura interfacce (interface list, bridge vlan, neighbor).  
  - SNMP è considerato arricchimento secondario (es. PoE, counters, dettagli fisici) e non può contraddire permanentemente REST su identity.  
  - In caso di conflitto REST vs SNMP su un campo dove entrambi sono autorevoli (es. stato porta), `MergePolicyService` applica una regola esplicita (es. “SNMP per physical status”) e marca il conflitto per visibilità in UI.
- **Newest wins**
  - Tra due fonti non locked e della stessa “priorità” (es. due discovery REST successive), vince il dato più recente (`observed_at` maggiore, o `last_seen_*` più recente).  
  - Questo principio si applica anche tra discovery Mikrotik e discovery subnet scan quando lo stesso campo è aggiornato.
- **DiscoveryEvents**
  - Vengono generati **solo** per conflitti “realmente rilevanti”, cioè quando:
    - il valore remoto differisce dal locale per un campo non locked **e** la politica predefinita non può decidere automaticamente;  
    - oppure il cambiamento ha impatto operativo (es. spostamento di un IP o di una porta, cambio WAN mapping).  
  - `MergePolicyService` fornisce alla UI una vista strutturata `old_value/new_value/source/last_seen` per ciascun campo in conflitto.

Queste regole devono essere implementate una sola volta nel servizio di merge e riusate da tutte le pipeline (Mikrotik REST, SNMP, UniFi, ICMP subnet scan) per evitare divergenze.

#### 7.8.1 API MergePolicyService & MergeResult

Firma ufficiale Fase 1:

```php
public function mergeDevice(
    \App\Models\Device $device,
    array $local,
    array $remote,
    \App\Enums\DataSource $source // rest|snmp|controller|agent
): \App\Merge\MergeResult;

public function mergeIpAddress(
    \App\Models\IpAddress $ip,
    array $local,
    array $remote,
    \App\Enums\DataSource $source
): \App\Merge\MergeResult;
```

Locked fields (per-campo, Fase 1):

- `hostname_locked`, `comment_locked`, `location_locked`, `serial_locked` (e analoghi per altri campi sensibili).  
- Per VLAN/IP non è previsto locking in Fase 1 (i cambi passano sempre da DiscoveryEvent se non auto‑approvati).

`MergeResult`:

```php
final class MergeResult
{
    /** @var array<string, array{old:mixed,new:mixed}> */
    public array $updated_fields = [];

    /** @var array<string, string> Skipped con reason (locked, conflict, policy) */
    public array $skipped_fields = [];

    /** @var \App\Models\DiscoveryEvent[] */
    public array $events = [];
}
```

- `updated_fields`: mappa campo → coppia `old/new` applicata.  
- `skipped_fields`: mappa campo → motivo (es. `locked`, `conflict`, `policy`).  
- `events`: lista di DiscoveryEvent già pronti, creati dal MergePolicyService.

La responsabilità di creare DiscoveryEvent è del servizio di merge; le azioni ingest devono solo:

- passare `local` (stato DB) + `remote` (device_result) al servizio;  
- applicare il risultato (`updated_fields`) e salvare;  
- non generare eventi in modo ad‑hoc.

Mapping MergeResult → DiscoveryEvent (contratto finale)

Quando `MergePolicyService` produce un `MergeResult` con `events`, il server deve tradurre questi eventi nella tabella `discovery_events` usando la seguente mappatura di tipo (Fase 1):

- Tipi evento generati dal MergeResult → DiscoveryEvent:
  - `field_updated` — campo aggiornato automaticamente
  - `field_conflict` — conflitto tra locale/remoto che necessita visibilità (log)
  - `field_locked_skipped` — valore remoto ignorato per campo locked
  - `entity_created` — creazione di entità (IP, Port, Subnet, VLAN)
  - `entity_deleted` — cancellazione di entità (raramente automatico; generalmente log)

Schema definitivo di mappatura DiscoveryEvent (Fase 1):

```php
[
  'agent_job_id' => UUID|null,
  'device_id' => int|null,
  'ip_address_id' => int|null,
  'port_id' => int|null,
  'event_type' => string, // field_updated|field_conflict|field_locked_skipped|entity_created|entity_deleted
  'field' => string|null,
  'old_value' => mixed,
  'new_value' => mixed,
  'source' => "REST|SNMP|Manual",
  'severity' => "info|warning|error",
]
```

Approval flow (Fase 1 — lettura sola)

- In Fase 1 i `DiscoveryEvent` vengono creati come log/info per la UI. Non esiste un workflow di approvazione o applicazione automatica: gli eventi sono SOLO read-only e servono per audit/diagnosi. La Fase 2 potrà introdurre approval/rollback.

### 7.9 IP change lato UI (management_ip autorevole)

Regola vincolante:

- `Device.management_ip` è l’indirizzo autorevole da usare per la discovery.

Comportamenti:

- Se l’utente cambia il `management_ip` via UI:
  - Il prossimo job userà il nuovo IP senza ulteriori azioni.  
  - L’agent non tenta indirizzi vecchi o fallback.  
  - Eventuali errori storici riferiti al vecchio IP rimangono solo come log/DiscoveryEvent.
  - Se il Mikrotik cambia IP di management senza che l’utente aggiorni la UI:
  - La discovery fallisce con un errore di tipo `AUTH_ERROR` (se credenziali errate) o `NET_TIMEOUT` (se non raggiungibile).  
  - Viene generato un DiscoveryEvent o notifica di errore.  
  - L’utente deve correggere a mano l’IP in UI; non esistono tentativi automatici su IP alternativi.

### 7.9 SNMP Discovery (Fase 1) — Scope e job

SNMP Discovery Fase 1 affianca la pipeline Mikrotik REST solo per i device che hanno SNMP abilitato e per i quali il profilo vendor è compatibile. Per ogni job di discovery, l’agent può eseguire in parallelo:

- `mikrotik_discovery` (REST Mikrotik)  
- `snmp_discovery` (SNMP generico)  
- `subnet_scan` (ICMP/fping)

Ognuno è opzionale; l’agent restituisce **un unico `device_result`** che contiene tutti i dataset raccolti. Il server poi separa/elabora i dati per modulo (IPAM, DCIM, SNMP, Mikrotik).

Scope SNMP Fase 1:

OID obbligatori (Fase 1):

- `1.3.6.1.2.1.1.5.0` → `sysName`
- `1.3.6.1.2.1.1.1.0` → `sysDescr`
- `1.3.6.1.2.1.1.2.0` → `sysObjectID`

Interfacce (ifTable obbligatorie - OID):

- `ifIndex` — `1.3.6.1.2.1.2.2.1.1`
- `ifDescr` — `1.3.6.1.2.1.2.2.1.2`
- `ifName` — `1.3.6.1.2.1.31.1.1.1.1`
- `ifType` — `1.3.6.1.2.1.2.2.1.3`
- `ifSpeed` — `1.3.6.1.2.1.2.2.1.5`
- `ifOperStatus` — `1.3.6.1.2.1.2.2.1.8`
- `ifAdminStatus` — `1.3.6.1.2.1.2.2.1.7`

OID opzionali Fase 1 (se disponibili):

- temperatura dispositivo (vendor specific)
- `1.3.6.1.2.1.1.3.0` → uptime SNMP

Struttura obbligatoria `device_result.snmp` (Fase 1):

```json
"snmp": {
  "device": {
    "sys_name": "switch01",
    "sys_descr": "HP ...",
    "sys_object_id": "1.3.6.1.4.1....",
    "uptime_seconds": 12345
  },
  "interfaces": {
    "items": [
      {
        "if_index": 1,
        "name": "1",
        "descr": "uplink",
        "type": "ethernet-csmacd",
        "speed_bps": 1000000000,
        "admin_status": "up",
        "oper_status": "up"
      }
    ],
    "partial": false
  },
  "errors": []
}
```

- Versione supportata:
  - **SNMPv2c** in Fase 1.  
  - SNMPv3 è pianificato per Fase 2 (non parte del contratto attuale).  
- Community SNMP:
  - Memorizzata in forma cifrata, riutilizzando lo stesso meccanismo di cifratura delle credenziali Mikrotik (CredentialProfile).
- Vendor:
  - Fase 1 si focalizza su SNMP “standard” (MIB-II) per apparati generici.  
  - Profili avanzati per Fortigate, Cisco, HP/Aruba sono considerati **Fase 2** e non vincolanti qui; il PDL può descriverli come obiettivo ma non come funzionalità garantita.  
  - Per UniFi, la fonte dati è il **controller API**, non SNMP: SNMP per UniFi non è supportato.

SNMP è eseguito **solo** se abilitato per quel device (vedi SNMP settings in DCIM).

### 7.10 Configurazione SNMP nel Device (Filament UI)

Ogni `Device` ha una tab “SNMP Settings” in Filament con i seguenti campi.

#### 7.10.1 Parametri connessione

- `Enable SNMP` (toggle)  
  - Abilita o disabilita la discovery SNMP per il device.
- `Host/IP` (text)  
  - IP del device SNMP (controller o apparato SNMP standard, a seconda del profilo vendor).
- `Port` (number, default 161)  
- `Version` (select: `v2c` nella Fase 1; opzione SNMPv3 introdotta in Fase 2).  
- `Community` (text/password)  
  - Stringa community, memorizzata in forma cifrata.
- `Timeout` (ms, default 3000)  
- `Retries` (integer, default 1)

Questi campi sono persistiti nella tabella `snmp_settings` (vedi §7.14).

#### 7.10.2 Port Name Priority (risoluzione nome porta)

Il naming delle porte varia per vendor; l’utente può configurare l’ordine di priorità:

- Lista di chiavi SNMP: `[ifAlias]`, `[ifDescr]`, `[ifName]` in ordine personalizzabile.  
- UI: componente Filament con Drag & Drop reorder.  
- L’agent applica la regola:

```text
name = primo campo non vuoto secondo la lista di priorità
```

Il valore effettivamente usato viene loggato e mostrato nella UI di Test SNMP.

#### 7.10.3 Custom OIDs

L’utente può definire OID arbitrari da interrogare **a livello device** (non per porta) in Fase 1:

- UI: Filament Repeater con campi:
  - `oid` (es. `.1.3.6.1.4.1.xxxxx`)  
  - `field_name` (es. `poe_power`, `temperature`, `uplink`)  
  - `expected_type` (`string`, `int`, `gauge`, `boolean`)
- I custom OID sono memorizzati come JSON nella colonna `custom_oids` di `snmp_settings`.  
- L’agent, durante la discovery SNMP, interroga questi OID e aggiunge i risultati al dataset `metadata` (es. temperatura device, stato UPS).  
- Il supporto a OID per‑porta è considerato Fase 2 e non fa parte del contratto di Fase 1.

#### 7.10.4 Test SNMP (tool UI)

Il pulsante “Test SNMP” sulla tab SNMP Settings esegue:

1. SNMP → `sysDescr`  
2. SNMP → `ifTable` (`ifName`, `ifDescr`, `ifAlias`)  
3. SNMP → primi 3 custom OID (se presenti)

La risposta è mostrata in un overlay che contiene:

- Valori grezzi (sysDescr, elenco porte campione).  
- Scelte fatte dal resolver di nome porta (es. “Port name resolved from ifAlias”).  
- Errori (SNMP_DISABLED, AUTH_ERROR, SNMP_TIMEOUT, ecc.).  
- Tempo di risposta totale.  
- Estratto dei debug log (formato unificato).

### 7.11 Errori SNMP e DiscoveryEvent

Codici errori implementati dall’agent lato SNMP (da riportare in `device_result.errors[]` e in DiscoveryEvent):

- `SNMP_DISABLED` — porta 161 chiusa o servizio SNMP disattivo.  
- `AUTH_ERROR` — community errata o accesso negato.  
- `SNMP_TIMEOUT` — nessuna risposta entro `timeout`.  
- `SNMP_UNSUPPORTED` — MIB fondamentali (MIB-II) assenti o incomplete.  
- `SNMP_DEVICE_NOT_ADOPTED` — per UniFi Controller: device non adottato / non presente nel controller.  
- `NET_UNREACHABLE` — controller o device SNMP irraggiungibile (routing/firewall).  
- `API_VERSION_TOO_LOW` — rilevante per Mikrotik REST, riportato per completezza; non ha impatto su SNMP.  
- `SCHEMA_MISMATCH` — payload SNMP non conforme allo schema atteso (es. tipi errati).  
- `INTERNAL_ERROR` — eccezioni non previste lato agent.

Tutti questi errori:

- Generano un `DiscoveryEvent` con categoria `Connectivity/SNMP`.  
- Sono loggati su `SERVER_INGEST`/`SERVER_DISCOVERY` con livello almeno `WARNING`.  
- Possono generare notifiche Telegram (vedi §12) in base alle impostazioni di notifications.

### 7.12 Agent — SNMP Pipeline (dettaglio tecnico)

Pipeline lato agent per SNMP:

```text
BEGIN SNMP DISCOVERY
→ Validate SNMP config
→ Ping test host
→ SNMP GET sysDescr
→ SNMP GET sysName
→ SNMP GET sysObjectID
→ SNMP WALK ifTable (ifDescr, ifAlias, ifName, ifSpeed, ifOperStatus, ifType)
→ Resolve port names using priority rules
→ Execute custom OIDs (if any)
→ Normalize dataset:
      - interfaces[]
      - device identity
      - metadata
→ Compress JSON (gzip)
→ Send payload to server
END
```

Dettagli:

- `Validate SNMP config` controlla che `enabled=true`, community non vuota, host/ip valido, versione supportata (SNMPv2c in Fase 1).  
- `Ping test host` è facoltativo ma raccomandato; in caso di esito negativo può generare `UNREACHABLE`.  
- `ifTable` è interrogata almeno per: `ifDescr`, `ifAlias`, `ifName`, `ifSpeed`, `ifOperStatus`, `ifType`.  
- I nomi porta sono risolti usando `port_name_priority` come descritto in §7.10.2.  
- I custom OID sono eseguiti **solo a livello device** in Fase 1; OID per‑porta sono fuori scope.  
- Il dataset normalizzato viene fuso nel `device_result` comune (vedi §7.6), popolando unicamente:
  - `device` (sysName/sysDescr/sysObjectID → identity/model/vendor).  
  - `interfaces` (index/name/type/speed/oper_status).  
  - eventuali campi `metadata` derivati da custom OID globali (es. temperatura), che vivono in `device`/`errors`.

Fuori scope Fase 1 (esplicito):

- OID per‑porta (PoE, temperatura porta, counters avanzati).  
- LLDP/CDP/SNMP neighbors (vengono trattati come parte di pipeline separate, non in SNMP Fase 1).  
- VLAN via SNMP, routing tables, VRF, Fortigate/Cisco vendor-specific MIB.

### 7.13 Server — SNMP Parsing & Merge

Pipeline lato server:

**Step 1 — Validazione schema**

- Verifica che siano presenti i dataset minimi:
  - `device.identity` (da sysDescr/sysName/sysObjectID).  
  - `interfaces[*].index`  
  - `interfaces[*].name`

In caso contrario, viene generato `SCHEMA_MISMATCH`.

**Step 2 — Normalizzazione porta**

Per ogni porta SNMP:

- `name` = `resolve_priority(ifAlias, ifDescr, ifName)` (regola già applicata dall’agent, ma verificabile lato server).  
- `speed` = `ifSpeed` (normalizzato in bps).  
- `status` = `ifOperStatus` (up/down/unknown).  
- `type` = mapping `ifType` → categorie interne (`ethernet`, `sfp`, `virtual`, `wireless`, `other`).  
- `mac` = ottenuto da MIB specifiche o da dataset Mikrotik/ARP (fallback).

**Step 3 — Creazione/aggiornamento porte DCIM**

- Per ogni entry SNMP, il server crea o aggiorna una porta DCIM esistente (`ports`):  
  - Match primario su `device_id + snmp_if_index`.  
  - In mancanza di index, match secondario su `device_id + name`.  
  - In caso di conflitto (porta con stesso nome ma index differente), viene generato un `DiscoveryEvent`.

**Step 4 — Aggiornamento campi device**

- Aggiorna:
  - `hostname` (se non locked e se SNMP è considerato autorevole).  
  - `vendor` (da `sysObjectID`, es. `1.3.6.1.4.1.41112` ⇒ UniFi).  
  - `model` (parsato da `sysDescr`).  
  - `last_seen_snmp` (timestamp corrente).

Le regole di precedenza (SNMP vs Mikrotik vs manuale) seguono quelle di merge discovery (field-level lock, template decisions).

**Step 5 — Merge con dataset Mikrotik**

Quando per lo stesso device è disponibile anche dataset Mikrotik:

- SNMP arricchisce:
  - Porte (eper status, type, speed, identity).  
  - Metadati device.  
  - Eventuali custom metriche (PoE, temperature) usate a livello DCIM.
- Mikrotik arricchisce:
  - VLAN sulle porte.  
  - ARP, DHCP leases, IP neighbors.  
  - Bridge Host Table.

Il merge finale produce una vista unificata delle porte in DCIM/IPAM; conflitti vengono gestiti tramite DiscoveryEvents e template di merge.

### 7.14 Schema dati SNMP (estensioni)

Schema dati previsto per SNMP (da implementare/validare con migrazioni reali):

- **Tabella `snmp_settings`**

  - `device_id` (FK verso `devices`).  
  - `enabled` (boolean).  
  - `host` (string).  
  - `port` (int).  
  - `version` (string, valori `v1`/`v2c`).  
  - `community` (encrypted string).  
  - `timeout` (int, ms).  
  - `retries` (int).  
  - `port_name_priority` (JSON array, es. `["ifAlias", "ifDescr", "ifName"]`).  
  - `custom_oids` (JSON array di oggetti `{oid, field_name, expected_type}`).

  La struttura è implementata nella migrazione `2025_12_20_000103_create_snmp_settings_table.php` e nel modello `App\Models\SnmpSettings`. La colonna `community` utilizza lo stesso cast `encrypted` già usato da `CredentialProfile`.

- **Estensioni alla tabella `ports` (`device_ports`)**

  - `snmp_if_index` (int).  
  - `snmp_type` (string).  
  - `snmp_speed` (bigint).  
  - `snmp_status` (string).  
  - `is_physical` (bool).  
  - `is_virtual` (bool).  
  - Eventuali campi per PoE per porta (`poe_supported`, `poe_power`) derivati da custom OID (TODO: definire schema definitivo).

Queste estensioni devono essere riflettute in `docs/02_DATABASE_SCHEMA.md` quando implementate.

### 7.15 UniFi Controller e interoperabilità Mikrotik + SNMP

**UniFi Controller**

- Per UniFi Network, la fonte dati è il **controller API**, non SNMP.  
- La modellazione specifica di endpoint UniFi (client, devices, switches) è fuori dallo scope di questa versione del PDL, ma la regola è che:
  - discovery via UniFi non utilizza SNMP;  
  - il payload lato agent viene comunque normalizzato in `device_result` nelle stesse sezioni (interfaces, vlans, neighbors, ecc.) dove possibile.

**Interoperabilità Mikrotik + SNMP**

- Device Mikrotik:
  - REST Mikrotik è la fonte primaria; quando REST funziona, SNMP per Mikrotik **non** viene eseguito.  
  - SNMP per Mikrotik può essere usato solo come fallback quando REST è indisponibile (scenario legacy) o come arricchimento mirato in Fase 2.  
  - I dati vengono fusi in `device_result` (REST + eventuale SNMP) secondo le regole di merge policy.
- Device SNMP standard (Cisco/Forti/HP generici):
  - Discovery via SNMP v2c (MIB-II).  
  - I dati sono mappati alle stesse entità DCIM/IPAM (porte, device identity, last_seen_snmp).

SNMP viene eseguito solo se `snmp_settings.enabled = true`; in caso contrario il job può comunque includere Mikrotik discovery (REST) e subnet scan.

### 7.16 Profili di capacità device (CapabilityProfile)

Per semplificare le logiche di discovery e merge, ogni device viene associato a un profilo di capacità (`CapabilityProfile`), scelto in base a vendor/versione/config.

Enum Fase 1:

- `MIKROTIK_REST_FULL`  
- `MIKROTIK_SNMP_LEGACY`  
- `UNIFI_CONTROLLER_API`  
- `SNMP_STANDARD`  
- `GENERIC_IP_SCAN`

Capacità per profilo:

| Profilo               | device | interfaces | vlans | arp | dhcp_leases | neighbors | bridge_hosts |
|-----------------------|--------|-----------|-------|-----|-------------|-----------|--------------|
| MIKROTIK_REST_FULL    | ✔︎      | ✔︎         | ✔︎     | ✔︎   | ✔︎           | ✔︎         | ✔︎            |
| MIKROTIK_SNMP_LEGACY  | ✔︎      | ✔︎         | ✖︎     | ✔︎   | ✖︎           | ✖︎         | ✖︎            |
| UNIFI_CONTROLLER_API  | ✔︎      | ✔︎         | ✖︎     | ✖︎   | ✖︎           | ✔︎         | ✖︎            |
| SNMP_STANDARD         | ✔︎      | ✔︎         | ✖︎     | (✔︎?)| ✖︎           | ✖︎         | ✖︎            |
| GENERIC_IP_SCAN       | partial| ✖︎         | ✖︎     | partial via fping/ingest | ✖︎ | ✖︎ | ✖︎ |

Note:

- `MIKROTIK_REST_FULL` è il target principale per device Mikrotik RouterOS 7 con REST attivo.  
- `MIKROTIK_SNMP_LEGACY` copre Mikrotik più vecchi o con REST disabilitato, limitandosi a identity + interfaces + ARP via SNMP.  
- `UNIFI_CONTROLLER_API` usa solo API del controller; ARP/DHCP non sono disponibili nella Fase 1.  
- `SNMP_STANDARD` copre apparati generici SNMPv2c con MIB-II; ARP via SNMP è opzionale e dipende dalle MIB esposte.  
- `GENERIC_IP_SCAN` rappresenta lo scenario di sola scansione ICMP/fping (no REST/SNMP), con informazioni parziali (es. host alive).

---

## 8. Scheduled Tasks (job schedulati per customer)

### 8.1 Entità ScheduledTask

Si introduce un’entità **ScheduledTask** con i seguenti campi minimi:

- `customer_id` (obbligatorio)  
- `location_id` (opzionale)  
- `type`  
  - Es. `SCAN_SUBNET`, `DISCOVER_DEVICE`, `REFRESH_MIKROTIK`, `PRUNE_LOGS`, ecc.  
  - L’elenco esatto deve essere allineato al codice (`TODO: definire enum/const delle tipologie`).
- `parameters` (JSON)  
  - Es. `{"device_id": 42}`, `{"subnet_id": 10}`, ecc.  
  - Il contenuto e le chiavi sono specifiche del tipo di task.
- `cron_expression` o DSL custom  
  - Es. formato cron standard (`* * * * *`).  
  - In alternativa, DSL tipo “every 5 minutes”, “hourly”, “daily at 02:00” (`TODO: definire scelta finale`).
- `enabled` (bool)  
- `last_run_at` (timestamp)  
- `last_status` (es. `SUCCESS`, `FAILED`, `SKIPPED_*`).  
- `last_error` (testo/JSON con ultimo errore significativo).

### 8.2 Scheduler Laravel

- Il Laravel scheduler esegue un job **`RunScheduledTasks`** ogni minuto.  
- Il job:
  - Valuta per ogni `ScheduledTask` se “è ora” di eseguirlo, in base a `cron_expression`/DSL e `enabled`.  
  - Esegue controlli pre-condizione:
    - Esistenza delle entità target (device, subnet, ecc.).  
    - Presenza di almeno un agent online compatibile con il customer/location (per i task di discovery).  
  - Se i prerequisiti non sono soddisfatti:
    - Marca il run come `SKIPPED_*` (es. `SKIPPED_NO_AGENT`, `SKIPPED_MISSING_TARGET`).  
    - Logga l’evento (formato unificato) e aggiorna `last_status`/`last_error`.
  - Se i prerequisiti sono soddisfatti:
    - Dispatcha il job applicativo (es. ScanSubnetJob, ScanDeviceJob) sulla queue configurata.

### 8.3 UI Scheduled Tasks

- Resource Filament “Scheduled Tasks”:
  - Form di configurazione con:
    - Scelta customer (tenant) e, opzionalmente, location.  
    - Tipo di task, parametri (via JSON o form strutturato).  
    - Cron expression/DSL + campo enabled.  
    - Helptext che spiega l’impatto del task.
  - Tab “Runs”:
    - Storico esecuzioni con data/ora, durata, status, eventuale errore.  
    - Filtri per status e periodo.  
    - Possibile action “Run now” per esecuzione immediata di un task.

---

## 9. DCIM: vista rack stile ECCM (Filament v4)

### 9.1 Requisiti UI

La vista rack deve essere realizzata **esclusivamente** con componenti Filament v4 (Table, Infolist, Repeaters, Badges, Icon, ecc.), senza CSS custom.

Per ogni rack:

- **Colonna U (unità)**  
  - Visualizza la posizione (U) del device nel rack.  
  - Può essere resa come colonna numerica o come “slot” verticale.
- **Colonna Device Info**  
  - hostname  
  - modello  
  - vendor  
  - management IP  
  - location (Customer/Location di appartenenza)
- **Colonna Ports**
  - Griglia di porte per device, con:
    - Badge per ogni porta (es. nome porta).  
    - Colore del badge per stato link (up/down/unknown).  
    - Icona per tipo porta (ethernet vs sfp vs power), usando le icone previste dal tema Filament.

### 9.2 Implementazione in Filament

Possibile struttura (orientativa, dipendente dall’API Filament v4 effettiva):

- **RackResource**:
  - Tabella principale con colonna U e Device Info.  
  - Infolist/Relation che mostra il dettaglio delle porte per device.
- **Componenti Filament usati**:
  - `Tables\Table` + `Tables\Columns\TextColumn` / `IconColumn` / `BadgeColumn`.  
  - `Infolists\Infolist` per rappresentazione dettagli rack+device.  
  - `Repeaters` per visualizzare la lista porte per device.  
  - `Icon` fields per distinguere ethernet/sfp/power.  
  - Nessuna estensione grafica custom al di fuori del supporto nativo Filament.

---

## 10. Export, PDF e HTML2Media

### 10.1 Export CSV/XLSX

- Per le tabelle chiave (almeno):
  - Subnets  
  - IP Addresses  
  - Devices  
  - Ports  
  - DiscoveryEvents  
  - Settings
- Si usano le **Table Export Actions** native di Filament v4:
  - Configurate direttamente sulle Table delle rispettive Resource.  
  - Permettono export CSV/XLSX con filtri e ordinamenti attuali.  
  - I file generati sono salvati in `storage/app/exports` con nome strutturato (es. `TYPE_TIMESTAMP_USERID.ext`).
  - Fase 1: export implementati per Subnets, IP Addresses, Devices, Ports, DiscoveryEvents; Settings seguirà in una fase successiva mantenendo lo stesso pattern.

### 10.2 Export PDF (torgodly/html2media)

- Per la generazione di PDF si usa il plugin `torgodly/html2media`.  
- I PDF sono generati da Blade view con TailwindCSS, in modo compatibile con il renderer.  
- È previsto supporto multi-template, ad es.:
  - Template IPAM (subnets/IP per cliente).  
  - Template rack (layout DCIM stile ECCM).  
  - Template labels (etichette con QR e info porta/device).
- Opzioni di configurazione:
  - Logo MSP (setting globale).  
  - Header/footer personalizzabili.  
  - Orientamento (portrait/landscape).  
  - Scelta colonne (checkbox via UI prima del render).  
  - Box firma opzionale (checkbox).
 - Fase 1:
   - implementato almeno un template PDF IPAM per le Subnet (vista `exports/subnets`), invocato da un’azione Html2Media sulla tabella Subnets;  
   - ulteriori template (rack, labels) e opzioni avanzate (logo MSP, firma, header/footer custom per area) restano vincolanti a livello di design ma verranno implementati in step successivi, riutilizzando sempre Html2Media.

### 10.3 UI Export

- In ogni area chiave (IPAM, DCIM, Discovery, Settings) esiste una tab/section “Exports” che offre:
  - Action “Generate PDF”:
    - Apre un form con opzioni:
      - Template  
      - Colonne da includere  
      - Orientamento  
      - Override logo (se consentito)  
      - Firma sì/no
  - Pulsante “Export CSV/XLSX” che richiama le Table Export Actions.  
  - Opzionale “recent exports”:
    - Elenco minimale degli ultimi N file generati, con link di download (rispettando la retention).

---

## 11. Backup & Restore (spatie/db-dumper)

### 11.1 Backup principale

- Il **backup principale** dei dati è quello del database MariaDB.  
- Si raccomanda un backup esterno:
  - `mysqldump`, snapshot a livello volume/provider, backup gestiti dallo stack hosting.

### 11.2 Backup on-demand dall’app

L’app offre un meccanismo di backup on-demand basato su **spatie/db-dumper**:

- Genera un file `.sql.gz` in `storage/app/backups`.  
- Il file è scaricabile via UI se l’utente ha permessi adeguati (es. ruolo admin).  
- Le opzioni (database, compressione, ecc.) seguono le capacità del pacchetto Spatie.

Per ogni backup:

- Viene scritto un record in ActivityLog, es. “User X created DB backup Y”.  
- Possono essere inviate notifiche Telegram/email (vedi Notifications).

### 11.3 Retention backup

- La retention dei backup è configurabile (es. `backup_retention_days`).  
- Un job schedulato elimina i file più vecchi della soglia, con logging:
  - `[INFO] [SERVER_SCHEDULE] Deleted backup file ...`  
  - In caso di errore (permessi, file mancanti) → log `WARNING` o `ERROR`.

---

## 12. Notifications & Telegram Integration

### 12.1 Config necessari

Impostazioni minime:

- `telegram_bot_token`  
- `telegram_chat_id` (può essere utente, gruppo o canale)

Questi parametri sono gestiti via pagina Settings (es. gruppo `notifications`).

### 12.2 Tipi di notifiche

Configurabili via UI; minima copertura:

- Agent offline / online.  
- Discovery errors (es. job fallito, `NET_TIMEOUT`, `AUTH_ERROR`).  
- Merge conflict created (nuovo `DiscoveryEvent`).  
- Merge resolved (evento risolto).  
- Backup completed (success/fail).  
- Export completed (PDF/CSV/XLSX pronto).  
- Altri eventi di sistema rilevanti (es. scheduled task failed).

Ogni tipo di notifica può essere abilitato/disabilitato per Telegram e/o email, con granularità globale e/o per customer (dove implementato).

### 12.3 Template messaggi e placeholder

I messaggi Telegram sono generati tramite template testuali con placeholder, ad es.:

```text
🚨 *Agent Offline*

Customer: {{ customer.name }}
Agent: {{ agent.name }}
Location: {{ location.name }}
Last seen: {{ agent.last_seen_human }}

🔗 Open Agent: {{ app.url }}/agents/{{ agent.id }}
```

Set di placeholder disponibili (da mantenere coerente col codice; dove non ancora allineato usare `TODO`):

- **Agent events**
  - `agent.id`, `agent.name`, `agent.last_seen`, `agent.last_seen_human`, `agent.status`  
  - `customer.id`, `customer.name`  
  - `location.id`, `location.name`
- **Device events**
  - `device.id`, `device.hostname`, `device.management_ip`, `device.model`, `device.vendor`  
  - `device.mac`, `device.last_seen`, `device.power_sources`, `device.ups_status`, `device.snmp_status`  
  - `customer.*`, `location.*` come sopra
- **Discovery events**
  - `event.id`, `event.type`, `event.status`, `event.ip`, `event.mac`, `event.description`  
  - `event.created_at_human`, `event.resolution` (se presente)
- **WAN-level / device WAN**
  - `device.wan_ip`, `device.wan_provider`, `device.wan_technology`, `device.wan_public_ip`, `device.wan_nat_mappings`
- **Port-level**
  - `port.name`, `port.type`, `port.speed`, `port.status`, `port.poe`
- **System**
  - `app.url` (da setting `app_url`)  
  - `date.now` (timestamp human friendly)  
  - `backup.filename`, `backup.size_human`  
  - `export.filename`, `export.type`

I placeholder non mappati devono essere lasciati invariati o sostituiti con stringa vuota, non devono causare errori runtime.

Validazione template:

- I template sono validati prima del salvataggio; in caso di placeholder sconosciuti o sintassi non valida:
  - il sistema rifiuta il salvataggio **oppure**  
  - usa un template di default e logga l’errore in modo esplicito.  
- In caso di errore a runtime durante il rendering di un template:
  - viene utilizzato il template di default per quel tipo di notifica;  
  - l’errore viene loggato (incluso il template problematico) senza bloccare l’invio di altre notifiche.

### 12.4 Inline Keyboard (bottoni)

Per alcuni messaggi sono previsti bottoni inline Telegram, es.:

```json
{
  "inline_keyboard": [
    [
      { "text": "Open Agent", "url": "https://app/agents/1" },
      { "text": "Open Event", "url": "https://app/events/2" }
    ]
  ]
}
```

I link:

- Devono sempre usare `app.url` configurato a livello applicativo.  
- Devono essere multi-tenant safe (includere ID tenant se richiesto dalla route).

### 12.5 Comandi bot (Fase 2)

Il PDL prevede la possibilità (Fase 2, opzionale) di supportare comandi bot, ad es.:

- `/health` — restituisce lo stato sintetico dell’istanza (agent online, job falliti).  
- `/last_event` — mostra l’ultimo DiscoveryEvent non risolto.  
- `/agent_status <customer>` — stato agent per un dato customer.

Struttura prevista:

- Endpoint webhook Telegram che riceve update.  
- Dispatcher che mappa comando → handler.  
- Output testuale + eventuali inline keyboard.

Dove non ancora implementato, queste parti devono essere marcate come `TODO` e non documentate in UI come disponibili.

Rate limiting (Fase 2):

- È previsto un meccanismo di rate limit lato server per le notifiche Telegram, ad esempio:
  - massimo 30 messaggi/minuto per tenant;  
  - raggruppamento automatico di eventi simili (es. “5 devices offline”) in un singolo messaggio.  
- Il dettaglio implementativo del rate limit e dell’aggregazione verrà definito in Fase 2; per Fase 1 è sufficiente prevedere la possibilità di introdurlo senza rompere i template.

---

## 13. Logging di sistema, metriche e health

Logging:

- Logging Laravel configurato per:
  - output JSON strutturato (vedi §5.1).  
  - eccezioni API rese JSON quando presente header Authorization/JSON (`bootstrap/app.php`).

Health & System Health page:

- Esiste una pagina dedicata “System Health” in Filament, non un widget di dashboard.
  - Contiene un pulsante “Run Health Check Now”.  
  - Mostra l’ultimo risultato (con timestamp) e badge UP/DOWN per:
    - scheduler  
    - worker/queue  
    - Redis (se configurato)  
    - DB e storage.  
  - Non utilizza polling aggressivo; eventuale auto-refresh è lento (≥ 90 s) e limitato a questa pagina.
- Scheduler:
  - Ogni esecuzione di `php artisan schedule:run` aggiorna un campo persistito (es. `system_state.last_scheduler_run_at`).  
  - La System Health page valuta la distanza temporale e mostra:
    - OK se `last_scheduler_run_at` < 3 min fa.  
    - WARNING se tra 3 e 10 min.  
    - CRITICAL se > 10 min.
- Worker:
  - Il worker esegue periodicamente (es. ogni minuto) un `WorkerHeartbeatJob` che aggiorna `system_state.last_worker_heartbeat_at`.
  - La System Health page applica soglie analoghe a quelle del scheduler per determinare lo stato del worker.

  - Heartbeat jobs & HealthCheckService (contratto):

    - `WorkerHeartbeatJob`:
      - queue: `low`
      - frequency: every 60s
      - updates `system_state.last_worker_heartbeat_at`

    - `SchedulerHeartbeatJob`:
      - queue: `default`
      - frequency: every 60s
      - updates `system_state.last_scheduler_run_at`

    - `HealthCheckService`:
      - aggregates: worker heartbeat last_seen, scheduler last_seen, agent online/offline (using `agent_offline_threshold_seconds`), queue backlog (using `DiscoverySettings` thresholds)
      - exposes `public function getSystemHealthSummary(): array` for UI and `/health` API consumption

Agent health:

- `last_seen` per ogni `ScanAgent` viene aggiornato su:
  - polling config (`/api/v1/agent/config`)  
  - polling job (`/api/v1/agent/jobs`)  
  - log ingest (`/api/v1/agent/logs`)  
  - discovery ingest (`/api/v1/agent/jobs/{id}/result`).
- La UI mostra un badge health (LED) vicino al nome cliente/TenantSwitcher, basato su una soglia configurabile (es. agent offline se `last_seen` > N minuti).

Metriche:

- Non è previsto un endpoint Prometheus (`/metrics`).  
- Monitoring è basato su:
  - log JSON (centralizzabili in sistemi esterni tipo ELK/Sentry).  
  - System Health page.  
  - Job Center (stato dei job di discovery/export/backup).  
  - eventuali notifiche (es. Telegram) per eventi critici.

---

## 14. Debito & rischi

Questa sezione elenca debiti e rischi residui, da tenere allineati con `docs/99_DEBT_AND_INCONSISTENCIES.md`.

- **TLS verify agent → device**
  - MikrotikApiDriver e client HTTP possono avere `verify` disabilitato di default per compatibilità con certificati self-signed.  
  - Rischio MITM. In Fase 1 questo comportamento può essere tollerato solo in ambienti controllati; la Fase 2 prevede:
    - TLS verify **attivo** by-default;  
    - possibilità, esplicita e tracciata, di disattivarlo per singolo agent o device con warning in UI.
- **Filtri Mikrotik REST**
  - Filtri REST tipo `?interface=...` non sono affidabili/documentati.  
  - L’implementazione deve assumere che non esista paginazione/filtraggio server-side e usare sempre la logica di partial dataset.
- **SNMP Fase 1**
  - Solo SNMPv2c è supportato nella Fase 1; SNMPv3 resta un obiettivo di Fase 2.  
  - Alcuni vendor potranno non essere pienamente supportati (MIB proprietarie, mapping incompleto); questa è una limitazione nota.  
  - Discovery principale per Mikrotik resta via REST; SNMP per Mikrotik è usato solo come fallback o arricchimento controllato, nel rispetto delle regole di merge policy.
- **DeviceResource duplicata**
  - La DeviceResource duplicata (`infra-devices`) è stata rimossa; resta solo la risorsa canonica sotto il gruppo Racks & Devices.  
  - Rif. `docs/05_UI_UX_FILAMENT.md` e `docs/99_DEBT_AND_INCONSISTENCIES.md`.
- **Concetti legacy (sections, VRF, L2Domain, debug endpoints)**
  - `sections`, VRF, L2Domain e debug endpoints tipo `/_debug/*` sono considerati concetti non più usati e devono essere rimossi dal codice e dalla UI.  
  - Il PDL non li considera più parte del modello dati attivo.  
  - Eventuali residui vanno gestiti come migrazioni di pulizia e aggiornamenti di documentazione.
- **Modalità `QUEUE_CONNECTION=sync`**
  - Ammessa solo in ambienti `local`/`testing`.  
  - In produzione, quando `QUEUE_CONNECTION=sync`, l’app disabilita automaticamente discovery/export/backup/notifications e mostra un banner di errore.  
  - È obbligatorio configurare Redis per avere il comportamento completo in produzione.
- **PAT agent & flussi agent/server**
  - I token degli agent (PAT) devono essere rigenerabili e mostrati in chiaro **una sola volta** al momento della creazione; in seguito, solo mascherati.  
  - Le chiamate sono sempre **agent → server**; non esistono flussi server → agent, né connessioni in ingresso verso l’agent.  
  - In caso di leak di un PAT, l’impatto è limitato alla possibilità di inviare risultati e log; il server deve validare comunque tenant_id/device_id su ogni ingest.
- **QR code & deep links**
  - I QR e i deep link generati (per device/ports/etc.) puntano sempre a pagine che richiedono autenticazione Filament/Laravel standard.  
  - Modalità “public view” non è supportata in Fase 1; eventuali viste pubbliche future devono essere strettamente minimali e configurabili.

---

## 15. Testing, QA & Development Workflow

Questa sezione definisce come deve funzionare il sistema di testing e il workflow di sviluppo associato. È vincolante per:

- backend Laravel (modelli, servizi, policies, tenancy, job)  
- UI Filament/Livewire  
- codice dell’agent remoto  
- pipeline di discovery, ingest e merge  
- worker/queue e container Docker di supporto

### 15.1 Architettura testing (container & database)

- I test vengono eseguiti **sempre in ambiente Docker**, con almeno:
  - `netvibe-web` (app): esegue HTTP, Filament, API, test unit/integration/snapshot/E2E (Dusk), migrazioni, seeding, setup tenancy.  
  - `netvibe-worker` (worker): esegue i job di queue reali durante gli integration/E2E test (discovery, merge, export).  
  - `db_test`: database dedicato ai test, isolato dal DB principale.
- Database:
  - `main`: sviluppo locale, istanza reale app.  
  - `test`: usato da Pest/Dusk/automation, viene cancellato e ricreato per le suite di test più distruttive.
- Motivo:
  - alcuni test distruggono tabelle, utenti, tenant, configurazioni; il DB test deve poter essere ricreato senza impattare l’ambiente reale.

### 15.2 Tipologie di test

Il PDL richiede quattro livelli di test.

#### 15.2.1 Unit Tests (Pest) – Livello 1

Obiettivo:

- Validare classi “pure” e logica core:
  - servizi (es. `MergePolicyService`, normalizzatori Mikrotik/SNMP, IpConversionService).  
  - value objects (IP, Subnet, MAC, VLAN).  
  - parser/normalizer/translator.  
  - error taxonomy.  
  - regole di merge (LOCKED/REST/SNMP/newest).  
  - validazioni custom.

Caratteristiche:

- Devono essere veloci, preferibilmente senza DB (o con DB in-memory).  
- Nessuna UI coinvolta.  
- Ambiente: container `app`.

#### 15.2.2 Integration Tests (Pest) – Livello 2

Obiettivo:

- Verificare:
  - interazione corretta dei modelli col DB (relazioni, vincoli, cascade).  
  - applicazione dei TenantScope.  
  - query generate dalle Filament Resources.  
  - ingest dei job agent (`AgentJob`) e loro processamento via worker.  
  - rispetto del contratto API definito nel PDL (device_result, tassonomia errori, codici HTTP).

Caratteristiche:

- Usano `db_test`.  
- Migrano il DB (fresh) per ogni suite/gruppo di test.  
- Possono richiedere `netvibe-worker` attivo per testare la queue “reale”.

Esempi:

- Creare un `Device`, associare un `ScanAgent`, lanciare un discovery → verificare l’`AgentJob` creato, il payload e lo stato.  
- Ingestare un payload Mikrotik normalizzato (`device_result`) → verificare Device/Port/Subnet aggiornati.  
- Verificare la logica di blacklist VLAN/Subnet.

#### 15.2.3 UI E2E Tests (Laravel Dusk) – Livello 3

Obiettivo:

- Validare il comportamento reale della UI:
  - wizard di setup.  
  - pannelli Filament (Resource, Page, relation manager).  
  - modali, slideOver, repeater, tab.  
  - azioni discovery/job/export.  
  - rack rendering.  
  - validazioni lato client (conferma visuale degli errori).

Motivazione:

- Filament usa Livewire: solo un test E2E browser (Dusk) replica correttamente click/input/async/lifecycle dei componenti.

Caratteristiche:

- Eseguiti via Laravel Dusk (Chromium headless) nel container app.  
- Usano `db_test` e, se necessario, il worker per verificare code reali.

#### 15.2.4 Snapshot Tests (Pest Snapshot) – Livello 4

Obiettivo:

- Garantire stabilità di:
  - tabelle Filament (HTML/JSON generato).  
  - output HTML di viste critiche (rack view, discovery diff).  
  - JSON di view agent-job.  
  - risposte API contrattualizzate (schema, struttura).

Caratteristiche:

- Eventuali cambiamenti non intenzionali in UI/HTML/JSON fanno fallire i test e obbligano a rivedere/rigenerare gli snapshot consapevolmente.
 - Strumento ufficiale: `spatie/pest-plugin-snapshots` (plugin snapshot per Pest) o equivalente scelto nel repo; tutti gli snapshot devono passare da un unico plugin per evitare frammentazione.

#### 15.2.5 Visual Regression (Fase 2, opzionale)

- Possibile integrazione futura con strumenti tipo Playwright/Chromatic per comparare screenshot/pixel.  
- Non è vincolante per Fase 1 ma il PDL deve lasciare spazio a questa evoluzione.

### 15.3 Struttura test & script di esecuzione

Struttura cartelle consigliata:

```text
tests/
 ├─ Unit/
 ├─ Integration/
 ├─ Feature/
 ├─ Browser/           ← Dusk tests
 ├─ Snapshots/
 ├─ Helpers/
 └─ Fixtures/
```

- `Unit/`: test di livello 1.  
- `Integration/`: test DB/queue/API.  
- `Feature/`: test applicativi di alto livello non necessariamente E2E.  
- `Browser/`: test Dusk.  
- `Snapshots/`: snapshot per output HTML/JSON.  
- `Helpers/`, `Fixtures/`: helper condivisi (factory extra, loader payload agent, fixture Mikrotik/SNMP).

Script sviluppatore ufficiali:

- `./dev/test`  
  - Esegue l’intera suite: lint + Pest (unit/integration) + Dusk (quando configurato).  
- `./dev/test-fast`  
  - Esegue solo i test rapidi (unit + feature base, senza Dusk).  
- `./dev/test-ui`  
  - Esegue i test legati alla UI: Filament tests + snapshot.  
- `./dev/test-agent`  
  - Esegue test per agent-protocol e compatibilità schema `device_result`, verificando anche che non ci siano riferimenti a pattern legacy.  
- `./dev/test-ci`  
  - Entry point usato in CI, orchestrando i passi sopra in modo coerente per l’ambiente CI.

Database di test:

- È possibile usare due DB separati:
  - `testing.sqlite` (o equivalente) per unit/feature test leggeri;  
  - `testing_full.sqlite` (o DB dedicato) per integration/distruttivi (wizard, reset, migrazioni complesse).  
- Gli script di test devono assicurarsi che il DB corretto sia selezionato (`.env.testing`) e ripulito (`migrate:fresh`) prima di suite che modificano schema/dati in modo significativo.

### 15.4 Testing dell’agent remoto

- L’agent può essere lanciato in locale puntando all’istanza app di test, con un file `.env.test-agent` dedicato, ad es.:
  - `SERVER_URL=http://app-test:8080`  
  - `AGENT_API_TOKEN=...`  
- Scenari da coprire:
  - ingest di payload Mikrotik reali (catturati da lab).  
  - verifica dei timeouts (Mikrotik REST, agent→server).  
  - gestione errori (AUTH_ERROR, NET_TIMEOUT, API_VERSION_TOO_LOW, CHUNK_INCOMPLETE, PAYLOAD_TOO_LARGE).  
- I test possono essere in forma di:
  - integration test sul server (stub agent) + fixture JSON;  
  - oppure test end-to-end con agent reale in container test collegato all’app test.

### 15.5 Metodologia “Vibe Coding” e criteri di refactoring

Principio guida:

- Il PDL è la fonte di verità. Il codice esistente **non** è autorevole se contraddice il PDL.  
- Se qualcosa è sporco, duplicato, legacy, si **rifattorizza o riscrive**, non si stratifica.

Quando **riscrivere da zero**:

- Codice spaghetti, senza test, funzioni monolitiche (200+ righe).  
- Pipeline ingest/merge monolitica che non rispetta il nuovo modello (device_result, MergePolicyService).  
- Accoppiamento forte tra UI e logica (es. Filament form che contiene regole di merge).  
- Pattern anti‑SRP / anti‑SOLID evidenti.

Quando **mantenere e rifinire**:

- Services ben modellati e testabili (IpConversionService, MacAddressService, ecc.).  
- Filament Resources coerenti con pattern moderni.  
- Helpers o azioni già usate e coperte da test, anche se non “perfette”.

Strategia:

- Se un bug è **strutturale**, si fa un deep dive immediato:
  - scrivere un test che riproduce il bug;  
  - refactoring + fix;  
  - aggiornare tutti i test correlati.  
- Se il problema è minore (“pelo”), si può sistemare in passata durante altro lavoro, senza bloccare l’intero sprint.

Best practices:

- Progressi incrementali sempre accompagnati da test.  
- Refactoring continuo, non rimandato a backlog indefinito.  
- Ogni feature è “done” solo se coperta da almeno test unit + integration, e Dusk se tocca UI.  
- Evitare over‑architecture: privilegiare soluzioni pulite, semplici e ben testate.

### 15.6 Requisiti di test per nuove feature

Per ogni nuova feature significativa:

- **Unit test** per la logica core (services, normalizzatori, merge policy, ecc.).  
- **Integration test** per flusso DB/queue (job, ingest, tenancy).  
- **Dusk test** se coinvolge UI (Filament Resource/Page/Action).  
- **Snapshot test** se la feature introduce o modifica output HTML/JSON stabile (liste, diff, report, API contrattualizzate).  
- **Fixture** per payload agent o API (Mikrotik, SNMP, UniFi controller).  
- **Test multi-tenant** quando la feature è sensibile al tenant (scoping, visibilità, cross-tenant).

### 15.7 Strumenti diagnostici & CI/CD

- Client-side JS error logging:
  - È previsto un endpoint `/client-js-error` per raccogliere errori JS/Livewire rilevanti in dev/prod (da implementare).  
  - Non è obbligatorio nella primissima iterazione, ma il PDL lo considera parte del toolkit desiderato.
- Telescope:
  - Usato in dev per debugging richieste, query, eccezioni, cache, job.  
  - Disabilitato in produzione.
- Sentry (o equivalente):
  - Raccomandato per errori server + JS in ambienti reali.  
  - Non obbligatorio per l’adozione base, ma il PDL lo considera un obiettivo.
- CI/CD (es. GitHub Actions):
  - Deve eseguire almeno:
    - `composer validate`  
    - code style (es. `pint`)  
    - analisi statica (`phpstan` almeno a livello 5, compatibilmente con lo stato del codice)  
    - `pest` con coverage minimale  
    - `php artisan dusk` (preferibilmente su branch principali / matrix selezionata per ridurre tempi)  
  - Fase 2 può includere:
    - visual regression.  
    - build container + smoke test container prima del deploy.

### 15.8 Regola Anti-Regressione Filament v3

NetVibe utilizza **unicamente Filament v4**. Qualsiasi utilizzo di sintassi, componenti, API o classi di Filament v3 è proibito.

L’ambiente di sviluppo e la CI includono:

1. Un linter custom (`./dev/check-filament-version`) che:
   - verifica che `composer.json` utilizzi `"filament/filament": "^4.0"`.  
   - cerca pattern di codice tipici di Filament v3 (namespace/classi/metodi deprecati).  
   - fallisce se trova riferimenti a componenti v3.
2. Un test automatico anti-v3 (`tests/Feature/Filament/ComponentVersionTest.php`) che:
   - scansiona `app/Filament` alla ricerca di pattern vietati (es. `Filament\\Forms\\Components` v3, `getHeaderActions`, `make()->schema` v3).  
   - fallisce se trova qualunque match.
3. Documentazione interna obbligatoria:
   - `/docs/docs-filamentv4` contiene best practice, catalogo componenti e differenze tra v3 e v4, con snippet “certificati”.  
   - `/docs/docs-laravelv12` contiene convenzioni Laravel 12 (routing, policies, queue, testing).
4. Script test dedicati (`./dev/test-ui`, `./dev/test-agent`) che includono questi controlli.

Ogni pull request che introduce riferimenti a Filament v3 deve essere respinta o corretta prima del merge.

---

## 16. Note finali

- Tutte le nuove feature o modifiche devono aggiornare questo PDL per mantenere allineato il “contratto” tra codice e documento.  
- Dove il codice diverge dal PDL, va aperto un ticket di allineamento specificando se è il codice o il PDL ad essere obsolete.  
- Per parti non ancora definite chiaramente (es. struttura dettagliata di alcuni settings, esempi JSON Mikrotik), è obbligatorio mantenere placeholder `TODO`/`FIXME` nel PDL fino alla definizione definitiva.
