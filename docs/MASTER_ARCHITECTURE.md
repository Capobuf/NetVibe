# MASTER_ARCHITECTURE — NetVibe (COMPLETE, from FULL_PROJECT_CONTEXT.txt)

> Questo documento è stato generato esclusivamente dal dump completo del progetto `FULL_PROJECT_CONTEXT.txt`.
> Tutto il contenuto qui riportato proviene dal codice e dalla documentazione inclusi nel dump; non sono state usate fonti esterne.

---

## 1) Panoramica ad alto livello

NetVibe è un'applicazione Laravel (Laravel 12) che è stata originariamente derivata dalla famiglia "Modern IPAM" e rebrandata nel progetto attuale. Dal dump si evince un'app business-grade per IPAM, discovery e gestione infrastrutturale con i seguenti vincoli e tecnologie:

- Backend: Laravel 12
- UI / Admin: Filament v4 + Livewire (Wizard, Schemas, Resource/RelationManagers, Widgets)
- High-performance server: Laravel Octane integrations (FrankenPHP, RoadRunner, Swoole)
- Queue / Background processing: Laravel queues (Redis consigliato), job workers
- Settings and configuration: Spatie Laravel Settings (DB + Redis cache repository), EnvEditor per scrittura di .env
- Docker helpers / Remote Scanning Agent: container in docker/remote-agent e script remote_scanner.php

Architettura modulare: l'app è organizzata in cluster Filament (app/Filament/Clusters) che raggruppano risorse, pagine e logic domain-specific per IPAM, Discovery, Organization e Racks.

---

## 2) Mappa dei Cluster e delle risorse

Il codice nel dump include i seguenti cluster Filament (principalmente dentro app/Filament/Clusters) e risorse importanti:

- Cluster: Ipam
  - Subnets (Resource + Pages + RelationManagers)
  - IpAddresses (Resource + Pages + Schema + Widgets)
  - Vlans
  - L2Domains

- Cluster: Discovery
  - ScanAgents (ScanAgentResource con AgentLogs RelationManager)
  - DiscoveryEvents
  - Discovery settings page (ManageDiscoverySettings)

- Cluster: Organization
  - Customers
  - Locations

- Cluster: Racks
  - Devices
  - DeviceModels
  - Racks + views for visualising layouts

In aggiunta a queste risorse principali, il progetto contiene una ricca collezione di Widgets (ad es. IpAddressVisualGrid) e Pages personalizzate per scenari operativi (import/export, bulk actions, reports).

---

## 3) Mappa dei servizi e Provider chiave

Dal dump emergono: 

- `App\Services\Sys*` (SystemHealthCheckService e simili): health checks e controllo prerequisiti
- `App\Services\EnvEditor`: utilità per scrittura/aggiornamento `.env` (usata nel Setup wizard)
- `App\Services\IpConversionService` & wrappers per scanner (fping, nmap, ecc.)
- `App\Settings\*`: Spatie Settings (GeneralSettings, DiscoverySettings, ...) per opzioni persistenti

Providers e integrazioni principali sono registrati nel progetto in modo da supportare la discovery, le impostazioni e i job: Spatie settings provider, Filament service provider, Octane provider, etc.

---

## 4) Remote Agent — design e funzionamento

Il codice include un modulo `docker/remote-agent` completo di `docker-compose.yml` e `remote_scanner.php` (script CLI). Il funzionamento osservato nel dump:

- Il container `netvibe-remote-agent` esegue `remote_scanner.php`, che può essere lanciato come task periodico o `--once` per scansioni on-demand
- Il remote agent si autentica (es. tokens) con l'app principale per inviare risultati di scansione/ingest; i token vengono generati con scopes tipo `ipam:ingest` per autorizzare l'operazione sul target
- L'approccio supporta deployment remoto di agenti in network segment isolati o in client edge per effettuare discovery dove il server non ha accesso diretto alla rete

Implicazioni: il sistema è progettato per flussi distribuiti dove gli agenti eseguono scansioni fuori dal server principale e ri-portano i dati.

---

## 5) Flusso di setup (Setup wizard — UX / Technical flow)

Entrypoint e design:

- Route di setup: `/setup` -> Livewire `App\Livewire\SetupPage` (wizard multi-step)
- Steps del wizard (come implementati):
  1. System Health — check del sistema e prerequisiti (service->checkAll())
  2. Database — modulo per inserire DB host/port/name/user/password; test di connessione con `DB::connection('mysql_test')->getPdo()`
  3. Migrations — status view / pulsante per lanciare le migrazioni
  4. Admin Account — creazione dell'utente admin (check `User::exists()` e `User::create(...)`)
  5. Site Config — GeneralSettings via Spatie (site_name, site_color, option per demo data)
  6. Summary / Finish — completamento e redirect

Comportamento rilevato (dal dump):

- Connessione DB di test: il wizard crea una connessione temporanea `mysql_test` per verificare le credenziali e salva immediatamente i valori via `EnvEditor` se la connessione è OK.
- Runtime: dopo scrittura `.env`, `Config::set()` e `DB::purge('mysql'); DB::reconnect('mysql');` vengono eseguiti per allineare runtime alla nuova configurazione.
- Migrations: l'implementazione mostra `Artisan::call('migrate:fresh', ['--force' => true]);` eseguito direttamente (sincrono) dal componente Livewire e notifica via Filament Notifications.
- Seed demo data: opzionale, invocato con `Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);`

UX Notes:

- Le schermate sono costruite con Filament/Schemas (Wizard, Steps, Placeholders) e custom views (`resources/views/livewire/_setup_*`) che permettono di mostrare stato di salute, DB test e progresso migratorio.
- Il pulsante finale invoca `finishSetup` che esegue la creazione admin e salva GeneralSettings — tutto è consolidato come un flow lineare lato UI.

Problemi osservati:

- `migrate:fresh` è distruttivo e può essere lento; l'esecuzione sincrona nella stessa request Livewire può portare a timeout/long-running operations.
- Scrivere `.env` direttamente e cambiare runtime config richiede attenzione ai permessi, atomicità e gestione di errori (rollback parziale in caso di failure).

STATUS: STABLE — migration ordering issue for `settings` was fixed (creation migration moved earlier) and SetupPage was hardened for Day‑0; future work should move long-running migrations into background jobs after bootstrap.

---> Requisito operativo: il wizard deve essere reso idempotente e le operazioni lunghe dovrebbero essere eseguite in lavoro asincrono (queue/Job) con reporting di stato.

---

## 6) Job/Background workers & discovery lifecycle

Il progetto include numerosi jobs per scansioni e ingest:

- `app/Jobs/ScanSubnetJob` e analoghi gestiscono scansioni di rete, parsing e salvaguardia dei risultati (retry, logging, eccezioni gestite)
- I Jobs leggono dalla configurazione `App\Settings\DiscoverySettings` (timeout/retries, prune logs days) per comportamenti runtime
- I flussi di ingest usano Redis/queue e sono compatibili con Octane/multi-worker — la strategia di deploy prevede workers separati per conservare performance.

Observability & logging:

- Notifiche Filament, log centralizzati (Log::error), e relazione AgentLogs per persistere gli output di agenti remoti

---

## 7) Migrations, Settings table e discrepanze riscontrate (audit dal dump)

Punti osservati nel dump:

- La configurazione `config/settings.php` è impostata per usare repository DB (Spatie\LaravelSettings) e prevede anche repository Redis cache per miglior performance.
- Nel dump ci sono più migration per la tabella `settings` (es. `database/migrations/2024_01_01_000001_create_settings_table.php` e `database/migrations/2025_11_29_133422_create_settings_table.php`). Entrambe le migration controllano `Schema::hasTable('settings')` prima di creare la tabella e menzionano la colonna `payload` (usata da Spatie settings per salvare JSON).
- È presente una migration `2025_02_01_000005_add_discovery_settings.php` che inserisce valori default (`updateOrInsert`) nella tabella `settings` per il gruppo discovery.

Anomalia da indagare prima delle modifiche del wizard:

- La presenza di multiple migration che creano la stessa tabella può causare conflitti se alcune di queste sono state rimosse manualmente in altre branche: assicurarsi che la migration presente in ambiente di produzione sia coerente con il DB repository usato.
- Se un vecchio migration `settings` è stato rimosso manualmente (come hai menzionato), bisogna verificare che le colonne attese dal codice (soprattutto `payload`) e le eventuali migration che fanno `updateOrInsert` siano presenti o duplicate correttamente.

---

## 8) Sicurezza, permessi e vulnerabilità operative

- L'uso di `EnvEditor` per scrivere `.env` implica che il processo che esegue PHP (web server / CLI worker) abbia permessi di scrittura sul file; va studiata la sicurezza e i limiti per container deployment.
- I token remote-agent e i scopes (`ipam:ingest`) vanno gestiti con rotazione e revoca in caso di compromesso.

---

## 9) Raccomandazioni (prioritarie, basate sul dump)

1. Non eseguire `migrate:fresh` nella request Livewire — eseguire via job queue con reporting di stato e progress.
2. Rendere idempotente `finishSetup`: controllare esistenza di admin, `updateOrCreate` per settings e proteggere da race conditions con locking.
3. Consolidare migration `settings` e rimuovere eventuali duplicati/ambiguità: mantenere un singolo migration canonico che definisca la tabella `settings` con `payload` e gli indici necessari.
4. Aggiungere lock (cache/backed) quando si lancia migration in ambienti multi worker/Octane.
5. Proteggere `EnvEditor` con audit e validazione: backup automatico del `.env` prima della scrittura.

---

## 10) Azioni possibili e next steps (se vuoi che proceda)

1. Rifattorizzare `app/Livewire/SetupPage.php` per:
   - Spostare `migrate`/`db:seed` in Job asincrono, usare timeout e retry con stato persistente
   - Fallire e riportare errori in modo leggibile e idempotente
   - Evitare references a colonne non presenti e non assumere che improrogabili migration siano sincronamente eseguite

2. Consolidare `database/migrations` relative a `settings` e verificare che le operations `updateOrInsert` usate in migrations siano coerenti con la tabella effettiva.

3. Aggiungere test e integrazione per il flow di setup (unit e Feature test) per prevenire regressioni di configurazione.

---

Documento generato da: FULL_PROJECT_CONTEXT.txt (lettura sequenziale completa del dump). Se vuoi, procedo ora con la rifattorizzazione del file `app/Livewire/SetupPage.php` per renderlo idempotente, resistente ai timeout, e adeguato alle migrazioni (task successivo a questa documentazione).
# MASTER_ARCHITECTURE — NetVibe (derived from FULL_PROJECT_CONTEXT.txt)

> NOTE: this document is created strictly from the project dump (FULL_PROJECT_CONTEXT.txt). It describes the architecture as found in that dump, not external assumptions.

## Executive summary

NetVibe is a Laravel application (Laravel 12) that exposes both web UI and API surfaces and uses Filament v4 + Livewire for admin/setup flows. It uses Laravel Octane for high-performance serving (FrankenPHP/Swoole/RoadRunner supported), Redis queues for background processing, and Spatie-style application settings. The app contains IPAM / discovery clusters, background jobs for ingest / discovery and multiple Filament modules (Discovery, IPAM, Organization, Racks) with accompanying Livewire pages and job workers.

## High-level technology stack

- Framework: Laravel 12
- UI / Admin: Filament v4 + Livewire
- High-performance server: Laravel Octane (supports FrankenPHP, RoadRunner, Swoole/Open Swoole)
- Background processing: Laravel queues (Redis recommended) and job workers
- Settings: Spatie-style settings (GeneralSettings etc.)
- Storage: Relational DB (MySQL / SQLite noted in docs); migrations and seeders used extensively
- Search/other optional services: Scout / Meilisearch / Typesense mentioned in dump docs
- Deployment & local development helpers: Sail, Docker compose

## Primary application areas and responsibilities

1. UI Layer
   - Admin / setup pages implemented as Livewire components (notably `app/Livewire/SetupPage.php`).
   - Filament resources provide CRUD and admin interfaces for IPAM, racks, organization, and discovery flows.

2. Boot & Serving
   - App is Octane-aware; Octane configuration and docs (FrankenPHP, RoadRunner, Swoole) exist and are part of the project design.
   - Octane considerations include worker management, memory leak guidance, and concurrency (concurrent tasks, table cache, ticks/intervals).

3. Configuration & Environment
   - `EnvEditor` and code paths that write/update `.env` exist (setup flow writes DB credentials & other values).
   - Spatie settings and GeneralSettings classes are used to persist application-level configuration.

4. Data & Migrations
   - Migrations and schema dumping documented; `migrate`, `migrate:fresh`, `migrate:refresh`, `schema:dump` behavior is relevant.
   - Database seeds included and used as part of setup (option to load demo data).

5. Setup Wizard (Important)
   - `app/Livewire/SetupPage.php` is the interactive setup page used for initial configuration.
   - Responsibilities found in dump:
     - Test database connectivity using temporary connection creation.
     - Write database settings to `.env` via `EnvEditor`, then reconfigure runtime connection.
     - Execute migrations (the dump shows `migrate:fresh` usage) and optionally run seeds.
     - Create the initial admin user and set `setupCompleted` flags in GeneralSettings.
   - Risks identified in dump: long-running migrations (`migrate:fresh`) executed inside Livewire requests can time out; setup code must be made idempotent; writing `.env` and direct config manipulation needs careful handling.

6. Queueing and Background Jobs
   - Discovery and ingestion systems use background jobs and queues to process heavy work and long-running tasks.
   - Codebase and docs reference queue drivers, workers, and job properties (`tries`, `timeout`, `maxExceptions` etc.).

7. Integrations & Optional Services
   - Notifications, Passport/Sanctum for API auth, Socialite for OAuth, Scout for search engines (Meili/Typesense), Reverb for realtime/WebSockets (scaling via Redis), and more appear in project docs.

## Data flows & lifecycle (extracted from file)

- Initial setup flow:
  1. User runs Setup via `/setup` (Livewire `SetupPage`).
  2. The wizard tests database connection, writes `.env` values (DB+credentials) via `EnvEditor`.
  3. Setup invokes migrations (currently `migrate:fresh` in dump), and optionally seeds demo data.
  4. Initial admin user created if missing; GeneralSettings updated; `setupCompleted` set.

- Discovery/IPAM workflows:
  - Discovery/ingest jobs are queued for background processing; these are dispatched to Redis / queue drivers and processed by workers.

- High load / concurrency:
  - Octane and concurrent tasks (Swoole tasks, FrankenPHP, RoadRunner) are supported with configuration for worker counts, task-workers, tick intervals, and maximum execution time.

## Known issues & hard constraints (from dump)

- Blocking operations in setup:
  - `migrate:fresh` is destructive and long-running; running this synchronously in a Livewire request can cause timeouts and non-recoverable UI states.
  - Setup currently writes `.env` directly — sensitive; must ensure it's safe and transactional when possible.

- Idempotency concerns:
  - Setup page creates admin, seeds demo data — these operations must be idempotent to allow retries and safe re-runs.

- Queue & lock usage:
  - For migrations or heavy operations across multiple servers, use `--isolated` or centralised cache locks; queue workers and atomic locks required for single-server tasks.

## Recommendations (based only on dump content)

- Move long-running operations (migrations / seeders) out of synchronous Livewire request into background process or queue with progress reporting / status polling to avoid HTTP timeouts.
- Make setup idempotent by checking existence prior to destructive operations, and by adjusting seeder behavior to skip already-created resources.
- Avoid destructive commands like `migrate:fresh` unless the environment is explicitly known and the user confirmed; prefer `migrate` and targeted migrations.
- Use application-level locks (cache-backed) to prevent concurrent migrations across multiple machines (Octane / multi-server deployments).
- Document setup prerequisites (DB access, queue worker running) and surface warnings in the UI before running destructive operations.

## Next artifacts planned (in user's workflow)

- Implement a safe, idempotent setup flow for `app/Livewire/SetupPage.php` (migrate out of request, create admin safely, avoid writing to missing columns, add timeouts/backoff).
- Add in-app progress / status tracking for long-running setup tasks using a queued job and a status table or settings value.

---

Generated from: FULL_PROJECT_CONTEXT.txt (complete sequential read) — do not rely on this doc for context outside the project's dump. If you want, I can now safely refactor `app/Livewire/SetupPage.php` to make the setup idempotent, add migration timeout/queueing, and remove references to deleted settings columns (next todo).