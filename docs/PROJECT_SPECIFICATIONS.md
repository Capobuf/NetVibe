# NetVibe — PROJECT_SPECIFICATIONS.md

Ultimo aggiornamento: basato esclusivamente su FULL_PROJECT_CONTEXT.txt (dump del progetto).

Nota importante: questo documento è un "manuale tecnico vivente" generato a partire unicamente dal contenuto del dump fornito in FULL_PROJECT_CONTEXT.txt. Non sono state usate altre fonti esterne — la *verità* qui è il dump.

---

## Indice

1. Scopo e contesto
2. Panoramica tecnologia e stack
3. Regole operative (Container-first / "The Docker Law")
4. Architettura ad alto livello
5. Discovery Engine — flusso completo
6. IPAM — modelli, servizi e regole di business
7. Racks & Devices — modelli, visualizzazione e relazioni
8. Setup & Wizard (Livewire) — comportamento e rischi
9. Job, queueing e scheduling
10. Sicurezza e autenticazione (Sanctum, agent tokens)
11. Database, migrazioni e problemi noti
12. Filament admin (clusters/resources) e UI
13. Servizi utili e wrapper (fping, ip conversion, env editor)
14. Test, CI, e osservabilità
15. Problemi conosciuti e mitigazioni
16. Runbook di debug rapido
17. Allegati: file chiave e mappe di riferimento

---

## 1) Scopo e contesto

Questo documento descrive, in modo molto dettagliato, le principali componenti architetturali, il comportamento e gli edge-case rilevanti per il progetto NetVibe così come riportato nel FULL_PROJECT_CONTEXT.txt. È pensato come un documento operativo per sviluppatori, SRE e auditor.

Le linee guida sono: leggere il dump sequenzialmente e riflettere esattamente il comportamento trovato nel codice e nella documentazione interna.

## 2) Panoramica tecnologia e stack

- Linguaggio: PHP (≥8.2)
- Framework: Laravel 12
- Admin UI: Filament v4 + Livewire
- Queue/Cache: Redis (driver predefinito), uso intensivo di job queued (ShouldQueue)
- DB relazionale: MySQL / MariaDB (test DB: netvibe_testing)
- Container / local dev: Docker + Laravel Sail (container-first rule)
- Webserver: ServerSideUp (Nginx + PHP-FPM) - immagine `serversideup/php:8.4-fpm-nginx`
- Sicurezza: Laravel Sanctum per autenticazione agent/API
- Utility IP: mlocati/ip-lib + BCMath per conversioni numeriche IPv4

## 3) Regole operative (Container-first / "The Docker Law")

Il progetto impone la regola operativa che *tutte* le comandi di sviluppo e produzione devono essere eseguiti dentro i container. Questo implica:
- Non eseguire php, composer, artisan, npm/local build direttamente sull'host
- Se si osservano errori in Docker, analizzare logs dei container (Nginx, PHP-FPM/FrankenPHP) — i wizard sono noti per freeze silenziosi in Docker

Questa è una convenzione ripetuta in più documenti del dump (setup, sail, docker-compose).

## 4) Architettura ad alto livello

La piattaforma è divisa in:
- Interfaccia amministrativa (Filament resources & Livewire components)
- IPAM: Subnet, IpAddress, Vlan, L2Domain e servizi (IpConversionService)
- Discovery Engine: Scan agents remoti -> API ingest -> background job -> ingest action -> DB (DiscoveryEvent, IpAddress)
- Racks / DCIM: Rack, Device, DeviceModel e widget di visualizzazione
- Setup e bootstrap: Livewire-based SetupPage che configura DB e deploya migrator/seeder
- Background processing: Laravel queue (Redis), Scheduled tasks (prune agent logs etc.)
- Runtime container: `netvibe-web` usa l'immagine `serversideup/php:8.4-fpm-nginx`. I permessi sono gestiti nativamente tramite le variabili d'ambiente `PUID`/`PGID` (User Mapping) per allinearsi all'host. Il container espone la porta 8080 (non 80) per il traffico HTTP interno, che viene poi gestito dal reverse proxy (Nginx Proxy Manager).

## 5) Discovery Engine — flusso completo

Componenti principali:
- ScanAgent (modello) — identifica l'agente remoto che esegue i ping/scans
- Endpoint ingest API: app/Http/Controllers/Api/DiscoveryIngestController.php
- Job: app/Jobs/ProcessDiscoveryIngestJob.php
- Action: app/Actions/Discovery/IngestScanResultsAction.php
- Model persistente: DiscoveryEvent
- Modello target che viene aggiornato/creato: IpAddress

Flusso (schematizzato):
1. Agent remoto invia risultati al controller API tramite DiscoveryResultsRequest
2. Controller valida e mette in coda ProcessDiscoveryIngestJob (risposta 202 Accepted)
3. Job esegue IngestScanResultsAction::execute() che processa i single results
4. L'action converte l'IP in decimale (IpConversionService->toDecimal()) e cerca ip esistente
5. Se ip esiste e incontra conflitti (diverso MAC) -> crea DiscoveryEvent PENDING o registra un conflitto
6. Se il record è nuovo o auto-import è abilitato -> crea/aggiorna IpAddress e setta DiscoveryEvent come APPROVED (o simile)

Concorrency & idempotency:
- L'ingest usa DB transaction e lockForUpdate per evitare duplicati / race condition
- Casi speciali: MAC mismatch — l'engine evita sovrascritture non intenzionali, generando DiscoveryEvent separati per revisione oppure approvando import automatizzato

Note di implementazione:
- IngestScanResultsAction è il cuore: si appoggia su IpConversionService per valide conversioni e to/from decimal
- ProcessDiscoveryIngestJob è ShouldQueue -> delega al worker per throughput

#### Diagramma di sequenza (Mermaid) — Discovery / Ingest flow

La seguente sequenza illustra l'interazione tra agenti remoti, API, queue, job e action responsabili dell'ingestione dei risultati di scansione:

```mermaid
sequenceDiagram
      participant Agent as ScanAgent
      participant API as DiscoveryIngestController
      participant Queue as Redis/Queue
      participant Worker as QueueWorker
      participant Job as ProcessDiscoveryIngestJob
      participant Action as IngestScanResultsAction
      participant DB as Database
      participant DE as DiscoveryEvent

      Agent->>API: POST /api/discovery/ingest (DiscoveryResultsRequest)
      API->>Queue: dispatch(ProcessDiscoveryIngestJob)
      Note right of API: returns 202 Accepted to agent

      Queue->>Worker: worker dequeues job
      Worker->>Job: execute job
      Job->>Action: IngestScanResultsAction::execute(results)

      Action->>DB: BEGIN TRANSACTION; SELECT ... FOR UPDATE to check existing IpAddress
      DB-->>Action: existing IP found / not found

      alt MAC mismatch / conflict
         Action->>DE: create DiscoveryEvent(status = PENDING, payload_raw)
      else auto-import allowed OR new IP
         Action->>DB: create/update IpAddress
         Action->>DE: create DiscoveryEvent(status = APPROVED)
      end

      Action->>DB: COMMIT
```

## 6) IPAM — modelli, servizi e regole di business

Modelli chiave:
- Subnet (app/Models/Subnet.php): gestione CIDR e relazioni (ipaddresses, vlan, l2domain)
- IpAddress (app/Models/IpAddress.php): mutatori/accessors — campo decimale + ip_str accessor; soft deletes
- Vlan, L2Domain, Customer, Location, ecc.

Servizi:
- app/Services/IpConversionService.php — funzioni principali:
  - toDecimal(ip) -> converte IPv4 in decimal (BCMath + ip-lib)
  - fromDecimal(decimal) -> converte decimal -> ip string
  - parseCidr(cidr) / toCidr()
  - calculateUsableHosts(cidr) — calcola hosts disponibili
  - isIpInSubnet(ip, cidr) — verifica membership
  - getNextFreeIp(subnet, opts) — ricerca primo ip libero (limite di scansione per performance)

Comportamenti e limiti:
- getNextFreeIp è progettata per IPv4 primariamente e limita la scansione a ~500 indirizzi per ragioni di performance
- Si fa uso intensivo di ip-lib per parsing CIDR e calcoli di range

UI & UX:
- Filament offre viste e widget (IpAddressVisualGrid) per gestione e visualizzazione delle IP

## 7) Racks & Devices — modelli, visualizzazione e relazioni

Modelli: Rack, Device, DeviceModel
- Rack: contiene devices, può avere relation managers/visual widgets per rappresentazione grafica
- Device: offre relazioni con IP (ip_addresses), con Rack e DeviceModel
- DeviceModel: catalogo modelli di device usati per popolare moduli di creazione

UI:
- RackVisual widget e infolist personalizzate per visualizzare stato, occupazione, posizioni
- Filament resources con forms e relation managers per gestione devices nel rack

Regole observabili:
- observer per Subnet un tempo usato per prefill ora deprecato — prefill rimosso per evitare operazioni costose

## 8) Setup & Wizard (Livewire) — comportamento e rischi

Flow di Setup (Livewire app/Livewire/SetupPage.php):
1. Health checks e preflight
2. DB configuration e test di connessione (scrive in .env usando EnvEditor se OK)
3. Esegui migrations (migrate o migrate:fresh) — opzione per reset completo (richiede conferma)
4. Creazione admin utente e demo seeder (opzionale)

Meccaniche di sicurezza:
- Lock sulla chiave Cache 'netvibe.setup.migrations' per evitare esecuzioni parallele
- Protezioni per evitare migrate:fresh in produzione a meno che non sia confermato
- set_time_limit usato per aumentare il timeout a 300s per cover migrations lunghe

Rischi e note operative (espresse nel dump):
- EnvEditor modifica .env in place (non usa scritture atomiche né backup automatici) — rischio di corruzione / perdite di configurazione
- Le operazioni di migrate / migrate:fresh vengono eseguite in contesto sincrono durante la richiesta Livewire: se i task sono lunghi questo può causare UI freeze (specialmente dentro container Docker). Il dump segnala un 'Silent Failure' nell'ambiente Docker al submit finale del wizard — i logs di Nginx/FrankenPHP devono essere controllati per diagnostica.

### Raccomandazione operativa (da doc rilevata)
- Convertire step lunghi in background jobs o usare feedback di progress/reporting per evitare freeze UI
- Evitare scrivere .env senza backup e uso di operazioni atomiche

## 9) Job, queueing e scheduling

Jobs principali osservati:
- ProcessDiscoveryIngestJob (delegato all'action ingest)
- ScanSubnetJob (scan asincrono di subnet)
- PruneAgentLogsJob (pulizia agent logs)

Meccanismi:
- I jobs implementano ShouldQueue (esecuzione su redis queue)
- Scheduling: uso di Laravel Scheduler (schedule:list etc.) per task periodici e mantenimento

Queue: default Redis, opzioni per queue specifiche (es. scout queue)

Operazioni di manutenzione:
- Predisposizione di workers: php artisan queue:work redis --queue=default
- Assicurarsi che gli scheduler girino in container dedicato oppure su host orchestration

## 10) Sicurezza e autenticazione

API agent authentication:
- Token-based (Sanctum) usato per autenticare agent remoti — PersonalAccessToken su utenti/agent
- Endpoint ingest effettua validazioni tramite DiscoveryResultsRequest

Rischi da codice:
- Exec/process wrappers (Scanners/FpingWrapper) eseguono comandi di sistema — bisogna sanificare input e gestire exit codes 3/4 come fatal

Accessi privilegiati:
- Setup wizard può scrivere .env e lanciare migrate:fresh — restrizioni e conferme sono state implementate, ma sono operazioni ad alto rischio

## 11) Database, migrazioni e problemi noti

Caratteristiche DB:
- Uso di MySQL/MariaDB
- Soft-deletes implementate su modelli critici (IpAddress etc.)
- Constraint e foreign-keys con ON DELETE RESTRICT in moltettabelle

Problemi rilevati nel dump (AUDIT_REPORT.md):
- Migrazioni duplicate per settings table e possibili drift (rischio di schema copy / dup)
- Frequent use di migrate:fresh nei test e CI — può essere costoso e pericoloso

Raccomandazioni (estratte dal dump):
- Evitare migrazioni duplicate; consolidare la sequenza e la cronologia
- Evitare migrate:fresh in ambienti condivisi/CI senza isolare dati

### DB SCHEMA VISUALIZZATO (Mermaid ERD)

Di seguito trovi un diagramma ER (Mermaid) che rappresenta le entità principali e le loro relazioni — centrato su IPAM, Discovery e DCIM:

```mermaid
erDiagram
      CUSTOMER ||--o{ SUBNET : "owns"
      VRF ||--o{ SUBNET : "contains"
      SUBNET ||--o{ IP_ADDRESSES : "contains"
      DEVICE ||--o{ IP_ADDRESSES : "has"
      RACK ||--o{ DEVICE : "hosts"
      CUSTOMER ||--o{ DEVICE : "owns"

      CUSTOMER {
         bigint id PK
         string name
         string slug
      }

      VRF {
         bigint id PK
         string name
         string rd
      }

      SUBNET {
         bigint id PK
         bigint vrf_id FK
         decimal subnet(39,0)
         int mask
         string cidr
         string name
      }

      IP_ADDRESSES {
         bigint id PK
         bigint subnet_id FK
         decimal ip_addr(39,0)
         string ip_str
         enum type
         bigint device_id FK (nullable)
         string hostname
      }

      RACK {
         bigint id PK
         string name
         string location
      }

      DEVICE {
         bigint id PK
         bigint rack_id FK
         string name
         string model
         string serial
      }
```

Nota: lo schema è una rappresentazione semplificata e riflette la struttura e i vincoli osservati nelle migration e nei model (es. `ip_addr` è un decimal(39,0) per rendere compatibile IPv6, `subnet` è rappresentato come decimal e `unique(subnet_id, ip_addr)` è imposto).

#### Schema testuale — tabelle critiche

1) ip_addresses

 - id: bigint (PK)
 - subnet_id: foreignId -> subnets.id (onDelete cascade)
 - ip_addr: decimal(39,0) — decimal string per supportare IPv6
 - ip_str: string (nullable) — rappresentazione testuale originale
 - type: enum('host','gateway','broadcast','reserved','dhcp') default 'host'
 - hostname: string (nullable)
 - description: text (nullable)
 - device_id: foreignId -> devices.id (nullable, onDelete set null)
 - timestamps + softDeletes

 Indici/constraints:
 - unique(subnet_id, ip_addr)
 - index(ip_addr)

2) settings

 - id: bigint (PK)
 - group: string
 - name: string
 - locked: boolean (default false)
 - payload: json (Spatie-style storage)
 - timestamps

 Indici/constraints:
 - unique(group, name)

3) discovery_events

 - id: bigint (PK)
 - scan_agent_id: unsignedBigInteger (nullable) indexed -> scan_agents.id
 - ip_addr: string
 - mac_address_current: string (nullable)
 - hostname_current: string (nullable)
 - status: enum('PENDING','APPROVED','DISCARDED') default 'PENDING'
 - conflict_type: string (nullable)
 - payload_raw: json (nullable) — raw scan payload stored for audit/review
 - timestamps + softDeletes

 Indici/constraints:
 - index(scan_agent_id)
 - foreign key on scan_agent_id -> scan_agents.id (onDelete cascade)

## 12) Filament admin (clusters/resources) e UI

Cluster principali (area admin):
- Discovery cluster: ScanAgentResource, DiscoveryEventResource
- IPAM cluster: SubnetResource, IpAddressResource, Widgets per visual grid
- Racks cluster: RackResource, DeviceResource, DeviceModelResource
- Organization / Customers / Locations resources

Caratteristiche:
- Resource forms, tables, relation managers e widgets personalizzati
- Livewire-based pages e widget che forniscono cruscotti (Visual Grid / RackVisual)

## 13) Servizi utili e wrapper

IpConversionService (vedi §6) — centro logico per conversioni e calcoli IP

Fping wrapper (app/Services/Scanners/FpingWrapper.php):
- Esegue il binary fping via Process::run
- Interpreta gli exit codes (3/4 come fatali)
- Ritorna elenco di IP "alive"

EnvEditor (app/Services/EnvEditor.php):
- Scrive .env in place; non atomico, non necessariamente sicuro in caso di errori concorrenti

SystemHealthCheckService: helper per Programmatic health checks usato in SetupPage

## 14) Test, CI, e osservabilità

Test note:
- Molti test eseguono migrate:fresh per assicurare uno stato noto
- phpunit.xml e strumenti (Pest) configurati

CI:
- Composer scripts e pipeline che eseguono migrator/seeder/test

Logging & Observability:
- Agent logs (AgentLog model) raccolti e possono essere pruneati periodicamente

## 15) Problemi conosciuti e mitigazioni

Problemi individuati nel dump e suggerimenti:

1) Runtime .env editing (EnvEditor)
   - Problema: scrittura sul file .env non atomica porta a corruption se interrotta
   - Mitigazione: usare file temporanei + rename atomico, backup prima della modifica, validazione post-write

2) Wizard esegue migrazioni sincronamente (UI freeze in Docker)
   - Problema: lunghe migrazioni bloccano la request -> UI freeze / silent failure
   - Mitigazione: spostare lungo-running work in jobs gestiti dal queue worker o fornire polling con status endpoint; incrementare timeouts e log verbosity

3) Exec system calls (fping wrapper / agent remote)
   - Problema: rischio di injection e crash se il binary restituisce exitcode non gestito
   - Mitigazione: sanitizzare, validare / timeout e tradurre exit codes in errori applicativi gestibili

4) Migrations duplicates / settings table drift
   - Problema: possibile drift schema e duplication
   - Mitigazione: consolidare migrations, creare single source of truth per settings schema

5) Login Loop dietro Nginx Proxy Manager
   - Problema: Laravel non rileva correttamente HTTPS o il middleware di autenticazione invalida la sessione.
   - Mitigazione: Configurare `TrustProxies` su `*` in `bootstrap/app.php` e, se necessario, disabilitare `AuthenticateSession` in `AdminPanelProvider` per evitare race condition con Redis.

## 16) Runbook di debug rapido

- Rabbit / Redis queue worker non processa jobs: eseguire dentro container: sail artisan queue:work redis --queue=default
- Discovery ingests non appaiono: controllare redis queue, ProcessDiscoveryIngestJob stefll, logs di workers e DB row su DiscoveryEvent
- Wizard freeze su Docker: ispezionare logs del container `netvibe-web` (nginx e php-fpm).
- Validazione permessi: assicurarsi che `netvibe-web` giri con `PUID`/`PGID` allineati all'host (es. 1000:1000) e che il container esponga la porta 8080. I log si trovano in `/var/log/nginx` o via stdout del container.

## 17) Allegati — file chiave e riferimenti (estratti dal dump)

I file più rilevanti presi direttamente dal dump:

- app/Actions/Discovery/IngestScanResultsAction.php — ingest flow & idempotency
- app/Jobs/ProcessDiscoveryIngestJob.php — job wrapper
- app/Http/Controllers/Api/DiscoveryIngestController.php — ingest API
- app/Models/DiscoveryEvent.php, IpAddress.php, Subnet.php, ScanAgent.php — domain models
- app/Services/IpConversionService.php — ip <-> decimal / CIDR / helper functions
- app/Services/Scanners/FpingWrapper.php — fping wrapper
- app/Livewire/SetupPage.php — setup wizard logic
- app/Services/EnvEditor.php — .env edit wrapper
- app/Providers/AppServiceProvider.php — providers and livewire/filament integrations
- docs/AUDIT_REPORT.md — enumerazione di problemi e raccomandazioni osservate

---

Se desideri, posso ora:

1. Generare una versione estesa di questo documento che contenga estratti di codice e snippet (solo dal dump) per i punti più critici (es. ingest action, ip conversion algorithms). ✅
2. Produrre una check-list operativa per mettere in sicurezza il Setup Wizard e EnvEditor (azioni prioritarie). ✅

Dimmi quale preferisci e procedo (o procedo direttamente a includere ulteriori estratti in questo file).

---

## Operational Runbook ✅

Sezione di azioni operative e check-list ridotte per i problemi più critici riscontrati nel codice e negli ambienti container (Docker / Sail). Queste procedure sono pensate per essere eseguite dentro i container (container-first).

### 1) Wizard freeze / UI silent failure (container Docker)

Sintomo: la pagina del SetupWizard resta bloccata al submit finale; l'interfaccia non risponde.

Checklist rapida (ordine raccomandato):

1. Controlla log applicativi nei container (inserirsi nel container o usare sail/docker-compose):

```bash
# dentro l'ambiente di sviluppo con Docker Compose
docker compose logs --tail=300 netvibe-web

# Controllare specificamente nginx o php se necessario (dipende dalla configurazione di logging s6)
# Solitamente tutto confluisce in stdout/stderr del container
```

2. Verifica lock migration nel cache (chiave: netvibe.setup.migrations):

```bash
sail artisan tinker --execute="Illuminate\\Support\\Facades\\Cache::get('netvibe.setup.migrations')"
```

3. Se è attivo un lock e steady-state GUI è bloccata -> controlla worker e queue:

```bash
# vedere job falliti / in coda
sail artisan queue:failed
sail artisan queue:work --once
```

4. Se il problema è migrate:mismatch o runtime migrate in corso: apri shell e riesegui lo step di migration (con backup DB) o riprova la migration in background:

```bash
# dentro container: create DB backup prima di qualunque migrate:fresh
sail artisan db:export backup_$(date +%F_%T).sql
# Eseguire migrate manualmente con attenzione
sail artisan migrate --force
```

5. Se la GUI resta bloccata malgrado i worker, raccogli gli stack traces e i logs (php / frankenphp) e riavvia i container come ultimo step: `sail down && sail up -d`.

### 2) Corruzione / reset .env (EnvEditor risks)

Sintomo: variabili di ambiente mancanti o file `.env` corrotto dopo un setup.

Checklist rapida:

1. NON modificare direttamente ".env" senza backup.
   - Se sospetti corruzione, crea subito un backup: `cp .env .env.bak`.

2. Verifica integrità del file `.env` (formato KEY=VALUE, nessun carattere non chiuso, linee interrotte):

```bash
cat -n .env | sed -n '1,200p'
```

3. Se il file è danneggiato, ripristina da `.env.bak` o usa `.env.example` e re-inserisci i valori critici (DB, REDIS, APP_KEY, SANCTUM, etc.)

4. Rigenera la chiave applicativa se necessario: `sail artisan key:generate` (assicurati che APP_KEY venga salvata correttamente in .env)

5. Dopo ripristino, cancellare cache app/config e ricostruire:

```bash
sail artisan config:clear
sail artisan cache:clear
sail artisan config:cache
sail artisan route:cache
```

6. Evitare di permettere riscritture .env non monitorate: introduci policy di backup e scrittura atomica (tempfile + rename) per EnvEditor.

### 3) Forzare manualmente una scansione di una subnet (ScanSubnetJob)

Utilizzo operativo: se devi triggerare immediatamente una scansione in produzione o in dev (isolata), esegui una delle seguenti opzioni:

Opzione A — Dispatch asincrono (worker queue):

```bash
# entrare in tinker dentro container
sail artisan tinker
>>> use App\\Models\\Subnet;
>>> $s = Subnet::find(<ID>);
>>> \\App\\Jobs\\ScanSubnetJob::dispatch($s);
```

Opzione B — Esegui sincronamente (blocking) per debugging:

```bash
sail artisan tinker --execute="\\App\\Jobs\\ScanSubnetJob::dispatchSync(App\\\\Models\\\\Subnet::find(<ID>))"
```

Opzione C — Esegui fping manuale per debug e comparativa (istruttivo quando wrapper fallisce):

```bash
sail shell
which fping || echo 'fping missing'
# controlla scansione diretta
fping -a -g 192.0.2.0/24  # esempio CIDR
```

### 4) Se il discovery ingest non appare in DB (DiscoveryEvent mancante)

Checklist:

1. Controlla le code Redis e lo stato dei workers:

```bash
sail artisan queue:failed
sail artisan horizon:status || echo 'horizon not used'
sail artisan queue:work --once
```

2. Cerca DiscoveryEvent recenti direttamente via query:

```bash
sail artisan tinker --execute="\\App\\Models\\DiscoveryEvent::latest()->limit(10)->get()"
```

3. Se i jobs non vengono processati, riavvia i worker e controlla i logs dei worker per eccezioni.

4. Se sospetti che l'agent non stia inviando dati, controlla lo stato del ScanAgent (ip, last_seen) e test POST direttamente con curl contro l'endpoint ingest (autenticazione token via Sanctum).

### 5) Procedure di escalation e sicurezza

- Mantieni backup regolari del DB e dei file .env prima di interventi che potrebbero cancellare dati (es. migrate:fresh)
- Per interventi distruttivi preferisci ambienti isolati e approvazioni (CI gates)
- In caso di evidenza di corruption o escalate, produrre un ticket con log, dump DB e lista dei passaggi già effettuati

---

Fine dell'Operational Runbook — se vuoi, posso trasformare questi passi in script operativi (bash) o in comandi utili da eseguire dentro un container/shell per automatizzare recovery & checks.
