# Refactoring Roadmap — NetVibe IPAM/DCIM

Questa roadmap traduce il PDL aggiornato in una sequenza di passi concreti, **testabili step‑by‑step** dall’utente.

Principi:

- Il PDL è la fonte di verità; il codice va adattato.  
- Nessuna retro‑compatibilità con vecchi agent/payload è richiesta.  
- Ogni milestone termina con uno **step di verifica manuale** (docker + UI/tests) che l’utente può eseguire prima di passare a quella successiva.  
- Non si implementano blocchi di funzionalità che non sia ancora possibile testare in ambiente dockerizzato.

---

## -1. Incidenti recenti e fix già applicati

Questa sezione tiene traccia dei problemi emersi durante l’adozione del nuovo PDL e della loro risoluzione, così da evitare regressioni.

### I. Setup Wizard / Spatie Settings (SystemState & General)

- **Problema**
  - Durante il wizard comparivano errori tipo:  
    `Tried saving settings 'App\Settings\SystemStateSettings', and the following properties were missing: installed, configured, last_scheduler_run_at, last_worker_heartbeat_at, last_health_check_at`  
    e analoghi per `GeneralSettings` (`site_name`, `site_color`, `app_url`, ecc.).
- **Causa radice**
  - Classi Settings aggiornate secondo PDL ma record esistenti nella tabella `settings` con payload parziale / chiavi mancanti.
- **Risoluzione**
  - In `SetupController` e nelle migrazioni di bootstrap:
    - se il salvataggio di `SystemStateSettings`/`GeneralSettings` genera `MissingSettings`, viene creato/aggiornato il record `settings` con **tutte** le chiavi richieste (valori di default definiti dalle classi Settings), prima di riprovare il salvataggio.  
  - PDL e `01_INFRASTRUCTURE.md` aggiornati per indicare che `SystemStateSettings` è la fonte autorevole di `installed/configured`.
- **Azione preventiva**
  - Aggiungere test feature che eseguono il wizard su DB “sporco” (settings parziali) per verificare che il flusso completi senza errori di `MissingSettings`.

### II. 419 Page Expired dopo setup dietro reverse proxy

- **Problema**
  - Dopo il completamento del setup, alcune richieste POST (es. `/setup/user`) fallivano con errore 419 `Page Expired`.
- **Causa radice**
  - `SESSION_DOMAIN` impostato alla stringa `"null"` in `.env`, in combinazione con accesso tramite reverse proxy HTTPS, portando a cookie di sessione non validi per il dominio reale.
- **Risoluzione**
  - Normalizzazione in `config/session.php`: il valore letterale `"null"` viene trattato come `null` (nessun dominio forzato, Laravel usa il dominio presente nell’header `Host`).  
  - Linee guida in `01_INFRASTRUCTURE.md`:
    - usare `SESSION_DOMAIN=` (vuoto) o non impostarlo affatto;  
    - abilitare `SESSION_SECURE_COOKIE=true` in ambiente HTTPS;  
    - configurare `TRUSTED_PROXIES=*` o elenco esplicito di proxy.
- **Azione preventiva**
  - Checklist infra: prima di accettare nuove istanze, verificare `.env` rispetto a `01_INFRASTRUCTURE.md`.  
  - Possibile test automatico in futuro: warning se `SESSION_DOMAIN="null"`.

### III. Device form / Filament v4 Schemas — errori Group/Fieldset

- **Problema**
  - Durante la creazione di un Device (`/devices/create`) sono comparsi errori:  
    - `Class "Filament\Forms\Components\Group" not found`  
    - `Class "Filament\Forms\Components\Fieldset" not found`.
- **Causa radice**
  - Nella nuova sezione SNMP Fase 1 del `DeviceForm` (cluster Racks) si è usato il pattern v3 (componenti Forms) invece dei componenti v4 di Schemas:
    - import errato di `Group` e `Fieldset` sotto `Filament\Forms\Components\*` anziché `Filament\Schemas\Components\*`.
- **Risoluzione**
  - `app/Filament/Clusters/Racks/Resources/Devices/Schemas/DeviceForm.php`:
    - import corretti:
      - `use Filament\Schemas\Components\Group;`  
      - `use Filament\Schemas\Components\Fieldset;`  
      - `use Filament\Forms\Components\Repeater;`  
    - la sezione SNMP è resa come:
      - `Group::make()->relationship('snmpSettings')->schema([...Fieldset::make('SNMP Settings')->schema([... TextInput / Select / Toggle / Repeater ...])])`.  
    - il type‑hint della callback `afterStateHydrated` è stato aggiornato a `Repeater $component` (v4).
  - Migrazione `2025_12_20_000103_create_snmp_settings_table.php` + modello `App\Models\SnmpSettings` completano il lato dati.
- **Azione preventiva**
  - Ricordare che:
    - i layout/container per Schemas (tabs, group, fieldset, ecc.) vivono sempre sotto `Filament\Schemas\Components\*`;  
    - gli input field continuano a vivere sotto `Filament\Forms\Components\*`.  
  - Mantenere e, se necessario, estendere i test anti‑Filament v3 per includere pattern errati nei file `app/Filament/Clusters/*/Schemas/*`.  
  - Annotato nelle milestone B3/C che ogni nuovo schema deve essere validato contro il linter `./dev/check-filament-version` e i test `ComponentVersionTest`.

## 0. Vincoli trasversali

- **Filament v4 only**: nessun componente o sintassi v3 in `app/Filament/*`. Enforcement via:
  - linter `./dev/check-filament-version`,  
  - test `tests/Feature/Filament/ComponentVersionTest.php`.  
- **Single-node**: una sola istanza `app` + `worker`. Nessun cluster o multi‑web.  
- **Queue**:
  - `QUEUE_CONNECTION=sync` ammesso solo in `local`/`testing`; in produzione discovery/export/backup/notifications vengono disabilitati.  
  - Code: `discovery`, `default`, `low`.  
- **Tenancy**:
  - `customer_id` obbligatorio per tutte le entità tenant‑aware.  
  - Job/API/ingest ricevono sempre un `tenant_id` esplicito; nessuna inferenza da `session()` nei modelli.

---

## 1. Milestone A — Infra, Setup & UI/UX (prima cosa testabile)

Obiettivo: poter avviare lo stack docker con Compose V2, far girare il wizard Fase 1 e arrivare a un’installazione “clean” secondo PDL.

### A.1 Compose V2 & stack container

- **Codice attuale**
  - Esiste ancora la coppia legacy `docker-compose.yml` + `docker-compose.override.yml` con servizi `netvibe-web`, `netvibe-worker`, `netvibe-db`, `netvibe-redis`.  
  - È stato introdotto `compose.yml` root con servizi `app`, `worker`, `db`, `redis`, `test-db`, network `netvibe-network` e `proxy` opzionale; questo file è già usato in produzione (`ipam.zeroloop.it`) per avviare lo stack e raggiungere il wizard.  
  - I profili Compose (`dev/prod/worker/test`) non sono ancora modellati esplicitamente.
- **Obiettivo PDL**
  - File unico `compose.yml` con servizi:
    - `app`, `worker`, `db`, `redis`, `test-db`, volumi `storage/dbdata/redisdata`.  
  - Profili: `dev`, `prod`, `worker`, `test`.  
  - Nessun riferimento a `docker-compose.yml` nella doc o negli script.
- **Azioni**
  - [COMPLETATO] Creare `compose.yml` root basato sulle definizioni correnti, rinominando:
    - `netvibe-web` → `app`,  
    - `netvibe-worker` → `worker`,  
    - `netvibe-db` → `db`,  
    - `netvibe-redis` → `redis`.  
  - [COMPLETATO] Aggiungere servizio `test-db` (MariaDB volatile su `tmpfs`) usato solo per test.  
  - [COMPLETATO] Spostare i Dockerfile server nella root del repo:
    - `docker/php/Dockerfile` → `Dockerfile.app`, utilizzato sia da `app` che da `worker`.  
  - [COMPLETATO] Aggiungere i profili Compose:
    - `app`: `["dev","prod"]`  
    - `worker`: `["dev","prod","worker"]`  
    - `db`: `["dev","prod","test"]`  
    - `redis`: `["dev","prod"]`  
    - `test-db`: `["test"]`  
  - Aggiornare `.env.example` per allineare `DB_HOST`, `DB_DATABASE`, `DB_USERNAME/PASSWORD` ai servizi `db/test-db`.  
  - Deprecare `docker-compose.yml` e `docker-compose.override.yml`: mantenerli solo fino al completamento della migrazione, poi rimuoverli (vedi Milestone A.4).
- **Verifica utente**
  - Eseguire:  
    - `docker compose up -d` (usando `compose.yml` root)  
    - `curl http://localhost:9693/up` (o porta configurata) deve rispondere OK.  
  - Confermare che `app` e `worker` sono up e che `db`/`redis` sono healthy.  
  - Una volta validato stabilmente lo stack con `compose.yml`, si potrà procedere con A.4 per rimuovere i file legacy e, se necessario, introdurre i profili Compose opzionali.

### A.2 Setup Wizard Fase 1 (preflight → migrate → admin → customer/location → app)

- **Codice attuale**
  - `SetupController` con step: preflight, migrate, user, customer, app già funzionanti in ambiente reale.  
  - Views: `resources/views/setup/index.blade.php`, `migrate.blade.php`, `user.blade.php`, `customer.blade.php`, `app.blade.php`.  
  - Preflight ora include controlli aggiuntivi:
    - connessione DB (`DB::connection()->getPdo()`),  
    - connessione Redis se richiesta da session/queue/cache (altrimenti “Not required”).  
  - `CheckSystemInstalled` usa `SystemStateSettings` (`installed` + `configured`) come fonte autorevole, con fallback su `users` solo quando `SystemStateSettings` non è disponibile.  
  - Test base: `tests/Feature/SetupWizardFlowTest.php` esiste ma non è ancora puntato al container `test-db`.
- **Obiettivo PDL**
  - Wizard Fase 1 completo:
    - Preflight: PHP/ext, storage, `.env` scrivibile, DB reachability.  
    - Migrazioni: `php artisan migrate --force`.  
    - Primo admin.  
    - Primo Customer + Location (“Default” se non specificato).  
    - Config base: `app_url`, `locale`, visualizzazione queue driver (read‑only) con hint su Redis.  
  - Stato installazione basato su `SystemStateSettings` (`installed` + `configured`).
- **Azioni**
  - Raffinare i check preflight per includere:
    - connessione DB (tentativo di `DB::connection()->getPdo()` con gestione errori),  
    - presenza Redis (opzionale) con messaggio chiaro in wizard.  
  - Consolidare scrittura stato:
    - `runMigrate()` → `installed = true`.  
    - `storeApp()` → `configured = true`.  
  - Aggiornare messaggistica e helptext secondo PDL (EN/IT, TODO per localizzazione).  
  - Integrare un test HTTP che simula il flusso completo utilizzando `test-db` come backend (aggiornamento di `phpunit.xml`/env testing per puntare a `test-db`).
- **Verifica utente**
  - Con stack `dev` up:
    - Aprire `http://localhost:8080/setup`.  
    - Passare tutti gli step Fase 1 fino al redirect `/`.  
  - Verificare in DB:
    - esiste almeno un `users` admin,  
    - esiste almeno un `customers` + `locations`,  
    - `settings` contiene `system_state` con `installed=true`, `configured=true`.

### A.3 SystemState & CheckSystemInstalled hardening

- **Codice attuale**
  - `SystemStateSettings` già presente con `installed`, `configured`, `last_scheduler_run_at`, `last_worker_heartbeat_at`, `last_health_check_at`.  
  - `CheckSystemInstalled` usa `SystemStateSettings` con fallback su `users`.
- **Obiettivo PDL**
  - `SystemStateSettings` come unica fonte autorevole per “installato” vs “non installato”, con fallback legacy solo per istanze già in uso.  
  - Middleware robusto che:
    - consente sempre percorsi `setup*` finché `configured=false`,  
    - blocca l’accesso a `setup` quando `installed && configured`.
- **Azioni**
  - Rendere esplicita la precedence:
    - se `SystemStateSettings` è disponibile, ignorare completamente la heuristica `Schema::hasTable('users')`.  
    - usare fallback legacy solo se `SystemStateSettings` non è ancora migrato/disponibile.  
  - Aggiungere test che coprano:
    - scenario `installed=false, configured=false` → redirect `/setup`;  
    - scenario `installed=true, configured=true` → nessun redirect da `/`.  
    - accesso diretto a `/setup` quando `configured=true` → redirect `/`.
- **Verifica utente**
  - Dopo aver completato il wizard Fase 1:
    - provare a tornare su `/setup` → deve redirigere a `/`.  
    - cancellare `SystemStateSettings` dal DB (manualmente) e verificare che il fallback non rompa l’istanza (solo per istanze dev/test).

### A.4 Pulizia docker legacy

- **Azioni**
  - [COMPLETATO] Eliminare `docker-compose.yml` e `docker-compose.override.yml` una volta che `compose.yml` è validato.  
  - Aggiornare ogni riferimento nei docs (`README.md`, `SETUP.md`, `docs/01_INFRASTRUCTURE.md`) da `docker-compose` a `docker compose`.  
  - Aggiornare eventuali script `scripts/run-tests.sh` o simili che usano ancora `docker-compose`.
- **Verifica utente**
  - Ripetere: `docker compose --profile dev up -d` con il repo pulito (senza docker-compose legacy).  
  - Confermare che tutti i comandi documentati funzionano.

### A.5 Prime UI DCIM/IPAM & ScanAgent coerenti con PDL

Questa sotto‑milestone introduce i primi adeguamenti UI/UX lato DCIM/IPAM e ScanAgent, senza modificare ancora la pipeline `device_result`.

#### A.5.1 DeviceResource tabs (fase 1, senza merge avanzato)

- **Stato attuale**
  - `DeviceResource` nel cluster Racks espone form, table e ora una pagina di view con Infolist Overview/Discovery di base.  
  - `DeviceForm` include esplicitamente il campo `management_ip` e la tabella Devices mostra `management_ip` come colonna principale (con `ip_addr` relegata a colonna opzionale nascosta di default).  
  - L’Overview read‑only (Infolist) mostra hostname, `management_ip`, modello, serial, stato auth e `last_seen_overall`, oltre all’agent.  
  - La sezione Discovery sull’Infolist mostra solo l’ultimo job (tipo, status, finished_at) e il JSON grezzo di `AgentJob.result` (stub), **non ancora un viewer mirato su `result.device_result`**.
- **Obiettivo**
  - Introdurre il layout tab‑based minimo senza cambiare ancora la pipeline ingest:
    - Overview (Infolist read‑only con hostname, `management_ip`, vendor/model/serial, Health/Auth);  
    - Networking (form con `management_ip` + tabella Ports nel RelationManager);  
    - SNMP Settings (form con sezione SNMP già implementata);  
    - Discovery (stub che mostra informazioni basilari dei job e il JSON grezzo di `result.device_result` se presente, senza ancora logica di merge o validazione schema_version).
- **Azioni**
  - [COMPLETATO PARZIALMENTE]
    - Aggiungere `management_ip` a `DeviceForm` e mostrarlo come colonna principale in DevicesTable, lasciando `ip_addr` come colonna opzionale (allineato al PDL che considera `management_ip` autorevole per discovery).  
    - Implementare `DeviceResource::infolist()` con sezione Overview read‑only e sezione Discovery stub (ultimo job + `AgentJob.result` JSON).  
    - Aggiungere pagina `ViewDevice` che usa l’Infolist (Overview/Discovery) e offre azione Edit in slide‑over.  
  - [TODO]
    - Esplicitare i tab UI “Networking” e “SNMP Settings” secondo `PDL_UX_UI §6.2` (oggi sono concettuali: networking è rappresentato dal RelationManager Ports e dalla presenza di `management_ip` nel form).  
    - Sostituire il JSON grezzo di `AgentJob.result` con una viewer mirata su `result.device_result` (schema_version, sezioni device/interfaces/vlans/arp/dhcp, errors) una volta che la pipeline ingest sarà rifattorizzata su `device_result` in Milestone C.2.  
    - Annotare le ambiguità sulla gestione attuale di `Check connectivity`/`Full discovery` (che oggi usano ancora `ip_addr`) e allinearle a `management_ip` solo quando il refactor agent/server sarà completato.
- **Verifica utente**
  - Aprire un Device in UI e verificare la presenza dei quattro tab concettuali, con Overview e Networking coerenti e senza regressioni nel salvataggio.

#### A.5.2 ScanAgent — Logs RelationManager

- **Stato attuale**
  - Modello `AgentLog` e API `/api/v1/agent/logs` esistono e aggiornano `scan_agents.last_seen`.  
  - `ScanAgentResource::getRelations()` fa riferimento a `RelationManagers\AgentLogsRelationManager::class`, che ora esiste con colonne base ma è stato esteso in questo ciclo.
- **Obiettivo**
  - Implementare il tab “Logs” per ScanAgent conforme a `PDL_UX_UI §6.3`:
    - filtri per `level`, intervallo `created_at`, testo libero;  
    - action “View JSON context” che mostra `context` formattato JSON;  
    - nessuna modifica alle API esistenti.
- **Azioni**
  - [COMPLETATO]
    - Aggiornare `AgentLogsRelationManager` per:
      - colonne: `created_at` (Time), `level` (badge con mapping DEBUG/INFO/WARNING/ERROR/CRITICAL), `message` (limit 50, searchable);  
      - filtri: `SelectFilter` su `level` e `Filter` con intervallo `created_at` (from/until);  
      - azione “View” che apre un modal read‑only con:
        - Time, Level, Message;  
        - `context` serializzato come JSON pretty in una Textarea (`context_json`).  
  - Nessun cambiamento lato `AgentLogController` o modello: si usano solo dati già presenti.
- **Verifica utente**
  - Inviare uno o più log tramite agent o seed e verificare:
    - visualizzazione corretta nel tab Logs;  
    - funzionamento dei filtri;  
    - corretta visualizzazione del JSON context.

#### A.5.3 IPAM/DCIM — Subnet & Ports discovery fields

- **Stato attuale**
  - Modello `Subnet` espone `last_scan` e `last_discovery`; la UI ora li mostra come colonne opzionali.  
  - Modello `Port` espone `last_seen` e `discovered_by_scan_agent_id`; la UI li mostra come colonne opzionali nella tab Ports del Device.  
  - PDL UI/UX richiede che tali campi siano visibili/toggleable, senza ancora cambiare la pipeline discovery.
- **Obiettivo**
  - Allineare:
    - SubnetResource: colonne `last_scan`, `last_discovery` + filtro per `scan_agent`;  
    - PortsRelationManager: colonne `last_seen` e `discovered_by_scan_agent_id` (esposta oggi come `Agent ID`, senza inventare relazioni non descritte nel PDL).
- **Azioni**
  - [COMPLETATO]
    - Estendere `SubnetResource` (tabella) con:
      - colonne toggleable `last_scan` e `last_discovery` (dateTime);  
      - `SelectFilter::make('scan_agent_id')` collegato alla relazione `scanAgent` (Remote Agent).  
    - Estendere `PortsRelationManager` con:
      - colonna `last_seen` (dateTime, toggleable);  
      - colonna `discovered_by_scan_agent_id` (label `Agent ID`, toggleable, hidden di default).  
  - Non è stata introdotta alcuna nuova logica discovery: si espongono solo campi esistenti a schema, coerenti con la Fase 1.
- **Verifica utente**
  - Verificare in UI che le colonne siano visibili o attivabili e che il filtro per Remote Agent sia disponibile nella vista Subnets;  
  - verificare che nella tab Ports dei device compaiano le nuove colonne.

---

## 2. Milestone B — Tenancy & Domain Core

Obiettivo: rendere il modello dati coerente con il PDL (tenancy esplicita, IPAM/DCIM puliti) prima di introdurre il nuovo pipeline `device_result`.

### B.1 TenantResolver & rimozione inferenze da session

- **Codice attuale**
  - Trait `TenantAware`/`TenantScope` attivi nelle query UI; `TenantScope` ora usa `TenantResolverService::currentTenantId()` invece di accedere direttamente alla session.  
  - `TenantResolverService` in Fase 1 legge il tenant corrente solo da `session('tenant_id')`, come previsto dal PDL.  
  - `App\Models\Device::booted()` non usa più `session('tenant_id')`, ma prova a inferire `customer_id` solo da `location_id` / `rack_id` se non impostato esplicitamente.  
  - Gli altri modelli `TenantAware` (`Subnet`, `IpAddress`, `Vlan`, `Rack`, `VlanBlacklist`, `SubnetBlacklist`) non contengono logica di inferenza tenancy né riferimenti diretti alla session.
- **Obiettivo PDL**
  - `tenant_id` esplicito per job/ingest/API.  
  - Nessuna inferenza da session nei modelli; la session serve solo per filtrare la UI.  
  - Introduzione di `TenantResolverService`.
- **Azioni**
  - [COMPLETATO] Introdurre `App\Services\TenantResolverService` che:
    - in Fase 1 legge il tenant corrente da `session('tenant_id')`,  
    - fornisce `currentTenantId()` come API centrale per scope/UI; in futuro potrà integrare header/token senza toccare i modelli.  
  - [COMPLETATO] Aggiornare `TenantScope` per usare `TenantResolverService` invece di accedere direttamente alla sessione.
  - [COMPLETATO] Rimuovere la logica di inferenza da `Device::booted()` basata su `session('tenant_id')`, mantenendo solo l’ereditarietà da `location`/`rack` come fallback.  
  - [COMPLETATO] Verificare che gli altri modelli `TenantAware` non contengano inferenze di tenancy: la logica di scelta `customer_id` rimane confinata ai form Filament (default su `session('tenant_id')`) e non ai model hooks.  
  - Aggiornare i test di tenancy esistenti (`TenantContextTest`, `SubnetScanAgentScopeTest`, ecc.) per allinearsi alla nuova semantica.
- **Verifica utente**
  - Creare due `Customer` differenti, creare device/subnet/ip per ciascuno, cambiare tenant via Tenant Switcher:  
    - verificare che ogni cluster Filament mostri solo i dati del tenant corrente.  
  - Lanciare discovery/IPAM per entrambi i tenant e controllare che i job siano scopiati correttamente.

### B.2 Pulizia IPAM/DCIM (schema e legacy)

- **Codice attuale**
  - Modelli Device/Port/Subnet/IP/Vlan sono stati ripuliti da riferimenti a `sections`, VRF e L2Domain; le migrazioni dedicate rimuovono tabelle/colonne legacy.  
  - `Schema::hasColumn` è usato solo nelle migrazioni per garantire migrazioni idempotenti; non esistono più path runtime che dipendono da `Schema::hasColumn`.  
  - `IngestMikrotikArpAction` gestisce ARP/DHCP e, in assenza di subnet esistenti, può auto‑creare subnet `/24` basate sulla PVID della porta (comportamento già presente e non ancora ridefinito dal PDL aggiornato).  
  - Debito tecnico storico rimanente è tracciato in `docs/99_DEBT_AND_INCONSISTENCIES.md`.
- **Obiettivo PDL**
  - Eliminazione definitiva di `Sections`, VRF, `L2Domain`.  
  - VLAN/Subnet blacklist attiva; auto‑create rispettosa delle exclusions.  
  - Campi `last_seen_*` separati con `last_seen_overall` computed.  
  - Nessun `Schema::hasColumn` in code path runtime.
- **Azioni**
  - [COMPLETATO PARZIALMENTE] Migrazioni per:
    - rimuovere colonne/entità deprecate: `sections` (tabella e `section_id` su `subnets`), VRF/L2Domain già gestiti dalle migrazioni dedicate.  
  - [COMPLETATO PARZIALMENTE] Ripulire i modelli da controlli `Schema::hasColumn` e branch legacy:
    - rimosso `Schema::hasColumn` runtime da `Device::saving()` (affidandosi alle migrazioni per `scan_agent_id`),  
    - rimosso `Schema::hasColumn` runtime da `IngestMikrotikArpAction` per subnets/IP (assumendo schema allineato).  
  - TODO / NOTE:
    - [COMPLETATO PARZIALMENTE] Introdurre campi `last_seen_rest`, `last_seen_snmp`, `last_seen_icmp` e accessor `last_seen_overall` nei device:
      - migrazione `add_last_seen_channels_to_devices` aggiunge le tre colonne,  
      - `Device` cast e accessor `last_seen_overall` restituendo il max tra i canali e il legacy `last_seen_at`,  
      - `ProcessAgentJobResultAction` aggiorna `last_seen_rest` (oltre a `last_seen_at`) per `check_connectivity`.  
    - [COMPLETATO PARZIALMENTE] Introdurre tabelle blacklist VLAN/Subnet:
      - `vlan_blacklists` (customer_id, vlan_id, reason, unique per customer+vlan),  
      - `subnet_blacklists` (customer_id, subnet_cidr, reason, unique per customer+subnet_cidr).  
    - [COMPLETATO] Allineare le Resources Filament IPAM/DCIM per usare i nuovi campi e rendere visibile/configurabile la blacklist:
      - DeviceResource (DevicesTable) mostra ora uno stato `Health` e la colonna `last_seen_overall` basata sui canali last_seen_* secondo PDL.  
      - VlanResource espone una colonna `Blacklisted` e un’azione per aggiungere/rimuovere la VLAN dalla blacklist (`VlanBlacklist`).  
      - SubnetResource espone una colonna `Blacklisted` e un’azione per aggiungere/rimuovere la subnet dalla blacklist (`SubnetBlacklist`), con query ottimizzate (`with subnetBlacklists`).  
    - [NOTA APERTA] Verificare nel PDL definitivo se il comportamento di auto‑creazione subnet `/24` in `IngestMikrotikArpAction` (basato su PVID della porta) deve essere mantenuto, reso configurabile o sostituito da una logica diversa (es. discovery guidato da SubnetResource/Discovery status). Fino a chiarimento il comportamento rimane invariato.
- **Verifica utente**
  - Eseguire `php artisan migrate` su un DB dev.  
  - Navigare IPAM/DCIM per verificare:
    - assenza di riferimenti UI a VRF/L2Domain/Sections,  
    - presenza di colonne/azioni di blacklist su VLAN/Subnet e vista VLAN↔Subnet coerente (raggruppamento per VLAN in SubnetResource),  
    - colonna `Health` e `Last Seen` coerente con last_seen_overall nella vista Devices.  
  - Nota: la verifica completa dei flussi di discovery (aggiornamento automatico last_seen_*, creazione subnet/IP rispettando la blacklist) richiede l’implementazione e il deploy del nuovo remote agent e verrà effettuata nelle milestone C.*.

### B.3 DeviceResource & SNMP Settings Fase 1

- **Codice attuale**
  - `Device` model + `DeviceResource` funzionanti con sezione SNMP Fase 1 integrata nella form (`DeviceForm`), basata sulla relazione 1:1 `Device::snmpSettings()`.  
  - Tabella `snmp_settings` esistente a schema con vincolo `unique(device_id)` e chiavi/valori allineati al PDL (enable, host, port, version, community, timeout, retries, port_name_priority, custom_oids).  
  - SNMP non è ancora implementato nella pipeline agent/backend (niente `SnmpService`, `SnmpDriver` o job `snmp_discovery`): questa parte è demandata alla Milestone C.3.
- **Obiettivo PDL**
  - Tab “Discovery” con toggle REST/SNMP enrichment.  
  - Tab “SNMP Settings” con:
    - Connection (enable, host, port, version, community, timeout, retries),  
    - Port name priority (draggable list),  
    - Custom OIDs (safe vs advanced).  
  - `snmp_settings` 1:1 con `devices`.
- **Azioni**
  - [COMPLETATO] Aggiungere migrazione `snmp_settings` secondo schema PDL.  
  - [COMPLETATO] Implementare `SnmpSettings` model + relazione su `Device`.  
  - [COMPLETATO] Aggiornare `DeviceResource`/`DeviceForm` per includere la sezione “SNMP Settings” (per-device) con:
    - enable, host, port, version, community (masked/encrypted), timeout, retries;
    - port name priority riordinabile (ifAlias/ifDescr/ifName) salvata come JSON array;
    - custom global OIDs definiti tramite repeater (`custom_oids`).  
    Attualmente la sezione è resa come `Group`/`Fieldset` nella form principale; il refactor in tab dedicata “Discovery/SNMP” verrà affrontato insieme al redesign completo della DeviceResource (Milestone C).  
  - [TODO] Aggiungere preset vendor minimi (Cisco/HP/Fortigate/Generic) come azioni che scrivono su `snmp_settings` (verrà implementato in coordinamento con `SnmpService` in Milestone C).  
- **Verifica utente**
  - Per un device:
    - configurare SNMP (fase 1 minima) dalla nuova sezione “SNMP Settings”;  
    - verificare nel DB che la tabella `snmp_settings` contiene i valori attesi (in particolare `enabled`, `host`, `port`, `version`, `community` cifrata, `port_name_priority`, `custom_oids`).  
  - Le verifiche end‑to‑end del job `snmp_discovery` e del bottone “Test SNMP” restano in sospeso finché la pipeline SNMP Fase 1 (Milestone C) e il remote agent non saranno aggiornati.

---

## 3. Milestone C — Discovery & Agent Pipeline

Obiettivo: introdurre `device_result` come contratto rigido, refactor pipeline ingest/merge, implementare SNMP Fase 1 e Job Center.

### C.1 device_result & Remote Agent refactor

- **Codice attuale**
  - `remote-agent/src/Handlers/JobHandler::handleDiscoveryDeep()` costruisce ora un payload `device_result` completo (sezione `device`, `interfaces`, `vlans`, `arp`, `dhcp_leases`, `neighbors`, `bridge_hosts`, `errors`) e lo include in `result['device_result']`, mantenendo temporaneamente anche i blocchi raw per compatibilità ingest corrente.  
  - `remote-agent/src/Console/AgentWorkCommand` applica la config runtime proveniente da `/api/v1/agent/config` (log_level, max_records_per_dataset).  
  - `/api/v1/agent/config` è stato implementato secondo il PDL ed è stato verificato via curl (incluso supporto `ETag` / `If-None-Match`).
- **Obiettivo PDL**
  - `device_result` con struttura rigida (schema_version, device, interfaces, vlans, arp, dhcp_leases, neighbors, bridge_hosts, errors).  
  - Error taxonomy chiusa condivisa (NET_TIMEOUT, AUTH_ERROR, SNMP_TIMEOUT, ecc.).  
  - `/api/v1/agent/config` completo con min_agent_version, timeouts, features.  
  - Supporto chunking + gzip sopra 1MB, limite 20MB post‑compress (la parte chunking viene pianificata come Milestone separata C.3 “Chunking & payload limits”).
- **Azioni (remote-agent)**
  - [COMPLETATO] Introdurre in agent:
    - `Support/DeviceResultBuilder` che costruisce il payload `device_result` da blocchi Mikrotik REST applicando:
      - normalizzazione di device identity / model / serial / uptime_seconds;  
      - mapping delle interfacce (`interfaces.items[]`) con `index`, `name`, `type`, `mac`, `speed_bps`, `admin_status`, `oper_status`, `description`;  
      - estrazione VLAN da `bridge_vlan` (`vlans.items[]`);  
      - normalizzazione ARP (`arp.items[]`) e DHCP leases (`dhcp_leases.items[]`);  
      - normalizzazione neighbors e bridge_hosts;  
      - raccolta errori REST in `errors[]` con `code` mappato alla tassonomia PDL (es. `http_error` → `API_UNAVAILABLE`).  
    - hook runtime `setMaxRecordsPerDataset()` usato da `AgentWorkCommand::applyRuntimeConfig()` per rispettare `max_records_per_dataset` ricevuto da `/api/v1/agent/config`.  
  - [COMPLETATO] Modificare `JobHandler::handleDiscoveryDeep()` per:
    - raccogliere i dati Mikrotik REST (`system/resource`, `system/identity`, `interface`, `interface/bridge/vlan`, `interface/bridge/host`, `ip/neighbor`, `ip/arp`, `ip/dhcp-server/lease`);  
    - passarli a `DeviceResultBuilder` e restituire **solo** `['ok' => true, 'device_result' => [...]]` senza più blocchi raw legacy (`resource`, `identity`, `interfaces`, `bridge_vlan`, `bridge_host`, `ip_neighbor`, `arp_table`, `dhcp_leases`).  
    - Questo rende l’agent compatibile solo con il nuovo modello PDL: qualsiasi ingest legacy che si aspetta i blocchi raw è da considerarsi deprecato e verrà adeguato in C.2.
  - [DEFERITO → C.3] Introdurre `Support/ChunkEncoder`/`ChunkDecoder` e wiring effettivo del chunking/gzip da/verso `NetVibeHttpClient::postJobResult` quando i payload superano le dimensioni stabilite (`max_agent_payload_bytes` in `SecuritySettings`). Questa parte non blocca l’adozione di `device_result` e verrà implementata in una milestone dedicata.  
  - [COMPLETATO] Implementare handling `/api/v1/agent/config` in `AgentWorkCommand` secondo payload PDL:
    - usa `log_level` per settare il logger;  
    - passa `max_records_per_dataset` al `JobHandler` (che lo propaga a `DeviceResultBuilder`).  
    - legge `min_supported_schema_version` e confronta la major con la `schema_version` locale (`1.0.0` Fase 1); se il server richiede una major superiore, l’agent mantiene solo heartbeat/logs e **non polla più jobs di discovery** fino a quando il server non torna compatibile (loggando un errore chiaro).
- **Azioni (server)**
  - [COMPLETATO] Implementare endpoint `/api/v1/agent/config` conforme al contratto PDL:
    - `AgentConfigController@index` ora restituisce solo il payload di configurazione (nessun elenco subnets), costruito da `DiscoverySettings`, `SecuritySettings`, `FeatureFlagSettings` e override `ScanAgent.settings`.  
    - Supporto `If-None-Match` / `ETag` implementato (304 Not Modified quando la config non cambia).  
    - Il campo `log_level` usa la priorità `ScanAgent.log_level` → `ScanAgent.settings["log_level"]` → default `"info"`.  
  - Nota (server):
    - L’adozione effettiva di `device_result` nella pipeline ingest (`AgentJobController@storeResult` e `ProcessAgentJobResultAction`) è gestita nella Milestone C.2 insieme a `MergePolicyService`, in modo da rifattorizzare ingest/merge in un unico passaggio coerente.  
    - Nel frattempo, `result['device_result']` è comunque salvato in `AgentJob.result` ed è disponibile per debugging e per l’implementazione progressiva di C.2.
  - [COMPLETATO PARZIALMENTE] Validazione `schema_version` lato server:
    - `AgentJobController@storeResult` legge `result.device_result.schema_version` e lo confronta con `SecuritySettings::min_supported_schema_version`;  
    - se lo schema ha major inferiore, risponde con HTTP `426` e payload `{ error: "API_VERSION_TOO_LOW", min_supported_schema_version: ... }`;  
    - se manca `schema_version` o è invalido, risponde con HTTP `422` e `error: "INVALID_RESPONSE_FORMAT"`.  
    - TODO: allineare in futuro il mapping completo degli errori a `DiscoveryEvent`/Job metadata secondo la tassonomia errori PDL.
- **Verifica utente**
  - Avviare un container agent di test contro l’istanza dev.  
  - Configurare un device Mikrotik, assegnare ScanAgent, lanciare `discovery_deep`:
    - verificare che il payload `device_result` arrivi correttamente (via log/DB).  
    - in caso di errori di dimensione, controllare che il server risponda con HTTP/ERROR code secondo mapping PDL.

### C.2 MergePolicyService & ingest Mikrotik/SNMP

- **Codice attuale**
  - `MergePolicyService` + `MergeResult` + enum `DiscoverySource` introdotti, ma non ancora utilizzati in modo esteso.  
  - `ProcessAgentJobResultAction` utilizza i payload legacy Mikrotik per `discovery_fast` / `discovery_deep`, mentre l’agent Fase 1 invia ora solo `device_result` per i job `discovery_deep`.  
- **Obiettivo PDL**
  - `MergePolicyService` con API formale (`mergeDevice`, `mergeIpAddress`, …) che:
    - applica regole `LOCKED beats remote`, `REST beats SNMP`, `Newest wins`,  
    - genera `DiscoveryEvent` strutturati.  
  - Ingest basato su `device_result`.
- **Azioni**
  - [COMPLETATO] Introdurre `App\Services\MergePolicyService` + `MergeResult` e `App\Enums\DiscoverySource`:
    - `MergePolicyService::mergeDevice(Device $device, array $local, array $remote, DiscoverySource $source)` gestisce per ora i campi `hostname` e `serial_number`, applicando la regola `LOCKED beats remote` se esistono i flag `hostname_locked` / `serial_locked` (oggi non ancora presenti a schema, quindi la logica è effettivamente “remote wins”).  
    - `MergePolicyService::mergeIpAddress(IpAddress $ip, array $local, array $remote, DiscoverySource $source)` gestisce per ora `mac_address` e `hostname`, con supporto a futuri flag di locking (`mac_locked`, `hostname_locked`).  
    - `MergeResult` traccia `updatedFields`, `skippedFields` e una collezione `events` (per DiscoveryEvent strutturati in Fase 2; attualmente lasciati vuoti).  
  - [COMPLETATO PARZIALMENTE] Refactor `IngestMikrotikInventoryAction` per:
    - utilizzare `MergePolicyService::mergeDevice()` (sorgente `DiscoverySource::Rest`) per aggiornare `hostname` e `serial_number` a partire da `resource`/`identity` (che ora possono essere derivati anche da `device_result`);  
    - mantenere la logica esistente per `DeviceModel` e `auth_extra` (os_version/uptime).  
  - [COMPLETATO PARZIALMENTE] Aggiornare `ProcessAgentJobResultAction` per:
    - estrarre `device_result` da `AgentJob.result` per i job `discovery_deep`;  
    - passare **direttamente** il `device_result`:
      - a `IngestMikrotikTopologyAction::execute($device, $deviceResult)` per la parte topologia (interfaces, neighbors, bridge_hosts);  
      - a `IngestMikrotikArpAction::execute($device, $deviceResult)` per la parte ARP/DHCP;  
    - usare il payload legacy solo come fallback per job non provenienti dall’agent (es. test/unit), senza più layer di mapping interno da `device_result` → `arp_table`/`ip_neighbors`.  
    - per `discovery_fast`, quando è presente `device_result`, utilizzare lo stesso percorso di ingest ARP/DHCP basato su `device_result`; il supporto a payload legacy resta solo come compatibilità minima per job sintetici non agent.  
  - [COMPLETATO PARZIALMENTE] Estendere l’uso di `MergePolicyService` agli ingressi IP:
    - `IngestMikrotikArpAction::upsertDiscoveredIp()` ora, quando trova un `IpAddress` esistente, costruisce `local`/`remote` per `mac_address` e `hostname` e chiama `MergePolicyService::mergeIpAddress($ip, $local, $remote, DiscoverySource::Rest)` prima di aggiornare `last_seen` e `type`;  
    - la creazione di nuovi IP resta invariata; i flag di locking (`mac_locked`, `hostname_locked`) sono demandati a future migrazioni secondo PDL.  
    - TODO: collegare gli `events` prodotti da `MergeResult` a `DiscoveryEvent` strutturati quando il formato UI sarà definito (Milestone successiva).  
  - [COMPLETATO PARZIALMENTE] Allineare le azioni di ingest Topology/ARP per consumare direttamente `device_result`:
    - `IngestMikrotikArpAction` normalizza ora la sorgente preferendo `device_result.arp.items` e `device_result.dhcp_leases.items`, con fallback ai campi legacy `arp_table` / `dhcp_leases` solo se la sezione `device_result` non è presente;  
    - `IngestMikrotikTopologyAction` usa `device_result.interfaces.items` per creare/aggiornare le porte (mac, speed, description, status) e `device_result.neighbors.items` / `device_result.bridge_hosts.items` per costruire i collegamenti MAC↔porta / link IP, mantenendo il parsing `bridge_vlan` solo come compatibilità per vecchi payload `/rest/interface/bridge/vlan` quando ancora presenti;  
    - la logica avanzata di mapping VLAN↔bridge/interfacce rimane esplicitamente rimandata, in coerenza con la nota aperta su Mikrotik bridge VLAN.
  - [TODO] Pulizia chiavi legacy residue:
    - pianificare una fase successiva in cui i riferimenti a chiavi legacy (`bridge_vlan`, `ip_neighbors`, `arp_table`, `dhcp`) nei percorsi di ingest saranno rimossi del tutto, mantenendo come uniche sorgenti:
      - `device_result.vlans.items` per l’elenco VLAN flat (senza mapping per-port in Fase 1);  
      - `device_result.arp` / `device_result.dhcp_leases` / `device_result.neighbors` / `device_result.bridge_hosts` come formato dati canonico;  
    - aggiornare la documentazione tecnica (`docs/03_CORE_LOGIC_IPAM.md` / `docs/04_CORE_LOGIC_DCIM.md`) quando questi percorsi legacy saranno eliminati, per allineare completamente l’implementazione al PDL e ridurre il debito tecnico.
  - [DA FARE] Preparare stub ingest SNMP che utilizza lo stesso servizio per l’enrichment (quando la pipeline SNMP Fase 1 sarà operativa).
  - [NOTA APERTA] Attualmente il builder Mikrotik (`DeviceResultBuilder::buildVlansSection`) si limita a leggere i campi `vlan-ids` restituiti da `/rest/interface/bridge/vlan` senza distinguere quale bridge fisico/logico sia coinvolto; in molti ambienti il bridge non si chiama necessariamente `bridge` o `bridge_vlan`.  
    La logica di associazione VLAN↔bridge/interfacce (e l’eventuale modellazione multi‑bridge) è **esplicitamente rimandata** a una fase successiva, quando verrà chiarito nel PDL come trattare device con più bridge o nomi non standard.  
    Fino ad allora, l’implementazione deve evitare assunzioni implicite sui nomi dei bridge e limitarsi a esporre le VLAN come elenco “flat” (solo `vlan_id`/`name`) come previsto dalla Fase 1.
- **Verifica utente**
  - Eseguire discovery su un device già censito:
    - verificare le differenze nel diff UI dei DiscoveryEvent,  
    - testare un caso di `hostname_locked`, uno di conflitto REST/SNMP, uno di `merge_conflict`.

### C.3 SNMP Fase 1 pipeline

- **Codice attuale**
  - Nessuna pipeline SNMP completa; solo commenti/placeholder.  
  - Nessuna `SnmpService` centralizzata.
- **Obiettivo PDL**
  - SNMP Fase 1 minimale:
    - sysName/sysDescr/sysObjectID,  
    - ifTable (index, name, type, speed, oper_status),  
    - 1–2 OID globali opzionali (es. temperatura).  
  - Enrichment porte DCIM senza duplicare entità.
- **Azioni**
  - Implementare `App\Services\SnmpService` che:
    - usa la configurazione per device/SNMP,  
    - costruisce un dataset normalizzato compatibile con `device_result.interfaces` e metadata device.  
  - Aggiungere job `snmp_discovery` lato server + handler lato agent (SnmpDriver + SnmpEnrichmentJob).  
  - Integrare i risultati nel pipeline ingest tramite `MergePolicyService`.
- **Verifica utente**
  - Configurare SNMP per un device supportato (es. switch generico MIB-II).  
  - Lanciare job `snmp_discovery` e verificare:
    - popolamento/aggiornamento porte,  
    - aggiornamento `last_seen_snmp` e metadata device.

### C.4 Job Center & Logging/Audit

- **Codice attuale**
  - `AgentJob` + API già in uso ma nessun “Job Center” globale.  
  - ActivityLog/AgentLog parzialmente integrati; log formato misto.
- **Obiettivo PDL**
  - Job Center basato su `AgentJob` con filtri per type/scope/status.  
  - ActivityLog tenant‑safe, AgentLog JSON‑only.  
  - Logging unificato server+agent con formato JSON.
- **Azioni**
  - [COMPLETATO] Estendere `AgentJob` con `scope`, `result_metadata`, `error_message`, `tenant_id`:
    - nuova migration `2025_12_09_000020_extend_agent_jobs_for_job_center` che aggiunge le colonne e backfilla `tenant_id` da `devices.customer_id` quando possibile;  
    - modello `AgentJob` aggiornato (`tenant()` relation verso `Customer`, cast `result_metadata` a array);  
    - `DispatchDeviceJobAction` inizializza `tenant_id` a `device->customer_id` e `scope` a `device`.  
  - [COMPLETATO] Creare `JobCenterResource` Filament (cluster Discovery) che mostra:
    - running/pending/completed/failed,  
    - link a device/agent,  
    - slideOver per log/risultati.  
    Implementazione:
    - `App\Filament\Clusters\Discovery\Resources\AgentJobs\AgentJobResource` + `Pages\ListAgentJobs` con sola vista read‑only;  
    - `AgentJobsTable` con colonne per `type`, `scope`, `status` (color mapping che interpreta anche gli status legacy `pending/picked_up`), device, agent, customer, timestamps;  
    - azione `View details` che apre uno slide‑over con payload, result, result_metadata e error_message formattati come JSON.  
  - [COMPLETATO] Aggiornare configurazione logging lato agent per usare il formato JSON unificato:
    - `RemoteAgent\Logging\Logger` ora scrive su STDERR JSON strutturato `{timestamp, level, component, message, context}` e inoltra al sink remoto l’intero record;  
    - `AgentWorkCommand` invia i log a `/api/v1/agent/logs` come `{level, message, context}` dove `context` contiene il record completo compatibile con il PDL.  
  - [COMPLETATO] Estendere `AgentLog` per la multi‑tenancy:
    - nuova migration `2025_12_09_000021_extend_agent_logs_for_tenancy` che aggiunge `tenant_id` (FK verso `customers`) e lo backfilla da `scan_agents.customer_id`;  
    - modello `AgentLog` aggiornato con relation `tenant()`;  
    - `AgentLogController@store` ora salva anche `tenant_id` per ogni log ricevuto.  
  - [COMPLETATO PARZIALMENTE] Estendere ActivityLog/AgentLog come da PDL:
    - retention ActivityLog impostata a 180 giorni (`config/activitylog.php`);  
    - `PruneAgentLogsJob` già usa `DiscoverySettings::prune_logs_days` per la retention `agent_logs`.  
    Mancano ancora:
    - model custom Activity Spatie con colonna `tenant_id` e relative migrazioni;  
    - ActivityLogResource / relation managers UI;  
    - mapping sistematico delle azioni dominio → eventi Activitylog.
- **Verifica utente**
  - Lanciare vari job (discovery/export) e verificare la loro presenza nel Job Center.  
  - Verificare che ActivityLog e AgentLog siano correttamente filtrabili per tenant, device, agent.

---

## 4. Milestone D — Export, Backup, Notifications

Obiettivo: completare le funzionalità di contorno (export, PDF, backup, Telegram) dopo che discovery/merge sono stabili.

### D.1 Export & PDF (html2media)

- **Stato**
  - [COMPLETATO] Export CSV/XLSX per le tabelle chiave IPAM/DCIM/Discovery già presenti:
    - Subnets → `SubnetExporter` + `ExportAction`/`ExportBulkAction` su `SubnetResource` (queue `low`);  
    - IP Addresses → `IpAddressExporter` + export actions su `IpAddressResource`;  
    - Devices (DCIM) → `DeviceExporter` + export actions su `Racks\Devices\DevicesTable`;  
    - Ports → `PortExporter` + export actions su `PortsRelationManager`;  
    - DiscoveryEvents → `DiscoveryEventExporter` + export actions su `DiscoveryEventResource`.  
  - [COMPLETATO] Integrazione di base con `torgodly/html2media`:
    - pacchetto installato via Composer e assets pubblicati;  
    - azione `Html2MediaAction` configurata sulla tabella Subnets per generare un PDF semplice (`resources/views/exports/subnets.blade.php`) con elenco CIDR/VLAN/Customer/Location/Description.  
  - [DA FARE] Estendere PDF Export e “Exports section” alle altre aree:
    - template dedicati per rack layout e labels;  
    - UI “Exports” per IPAM, DCIM, Discovery, Settings con storico minimo e opzioni avanzate (logo MSP, header/footer personalizzati, scelta colonne, firma) come descritto nel PDL.  
- **Verifica utente**
  - IPAM → Subnets:
    - usare l’azione “Export” nella tabella per generare CSV/XLSX e verificare che i file compaiano in `storage/app/exports` (o nel disco configurato) e rispettino filtri/ordinamenti;  
    - usare “Generate PDF” per ottenere il PDF dei Subnet e verificare che i dati di base (CIDR/VLAN/Customer/Location/Description) siano corretti.  
  - DCIM → Devices / Ports:
    - verificare che le azioni di export siano disponibili e che gli export contengano i campi chiave (hostname, management IP, modello, stato porta, VLAN, ecc.).  
  - Discovery → DiscoveryEvents:
    - lanciare qualche discovery che generi eventi e verificare l’export della tabella DiscoveryEvents.  
  - Nota: gli export per Settings e i template PDF avanzati restano pianificati come estensioni successive di D.1; non sono necessari per testare la pipeline export di base.

### D.2 Backup DB (spatie/db-dumper)

- Aggiungere job di backup on‑demand:
  - genera `.sql.gz` in `storage/app/backups`.  
  - opzionale cifratura AES256 con password inserita al download.  
- Integrare audit log e, se esiste, notifiche.

### D.3 Notifications & Telegram Fase 1

- Implementare NotificationSettings + integrazione Telegram minima:
  - agent offline/online, discovery errors, backup completed, export completed.  
  - template testuali con placeholder base (device, agent, wan, ecc.).  
- Implementare rate‑limit base per tenant; rimandare comandi bot/inline keyboard avanzati a Fase 2.

Ogni sotto‑step deve includere almeno:

- 1–2 test (unit/feature),  
- una breve sezione di doc aggiornata (`PDL.md`, `PDL_UX_UI.md` se impatta la UI).

---

## 5. Milestone E — Fase 2 / Nice‑to‑have

Questa fase si affronta solo dopo che le milestone A–D sono stabili.

- SNMP avanzato (vendor Fortigate/Cisco/HP, PoE, temperature, SNMPv3).  
- Telegram avanzato & bot commands (`/health`, `/last_event`, `/agent_status`, inline keyboard avanzate).  
- Visual regression (Playwright/Chromatic) per UI critiche.

---

## 6. Trash list (da riscrivere / da rimuovere)

Componenti considerati candidati alla riscrittura o rimozione completa:

- `docker-compose.yml`, `docker-compose.override.yml` (una volta che `compose.yml` è operativo).  
- Logica di inferenza tenancy nei model (es. `Device::booted()` con `session('tenant_id')`).  
- Qualsiasi path di codice che dipende da `Schema::hasColumn` per decidere il comportamento runtime.  
- SNMP fields/commenti legacy (es. commento “SNMP-related fields removed” in DeviceForm) — sostituiti dal nuovo tab Discovery/SNMP.  
- Eventuali debug endpoints `/_debug/*` residui.  
- Script/compose legacy sotto `docker/remote-agent/` non più utilizzati dalla nuova pipeline agent.

---

## 7. Keep & refine list (da salvare e consolidare)

Componenti con buona base, da mantenere e rifinire:

- `AgentJob` (model) + `AgentJobController` API — base per Job Center e orchestrazione jobs.  
- `ScanAgent` (model) e relativa Resource Filament — da estendere con PAT management e settings coerenti con `/agent/config`.  
- Azioni Mikrotik ingest:
  - `IngestMikrotikInventoryAction`,  
  - `IngestMikrotikTopologyAction`,  
  - `IngestMikrotikArpAction`  
  (da rifattorizzare verso `device_result` + `MergePolicyService`, ma con logica di dominio riusabile).  
- IPAM core:
  - servizi `IpConversionService`, `MacAddressService`,  
  - modelli `Subnet`, `IpAddress`, `Vlan` e relative regole.  
- Filament clusters/resources attuali (IPAM, Racks & Devices, Discovery) — da allineare al PDL ma non da eliminare.  
- Trait `TenantAware`/`TenantScope` — mantenuti come meccanismo di query‑scoping UI, in coerenza con `TenantResolverService`.

---

## 8. Copertura PDL → Roadmap (sezioni UI/UX e dominio aggiuntive)

Questa sezione esplicita il mapping tra PDL (`PDL.md`, `PDL_UX_UI.md`) e roadmap, aggiungendo TODO puntuali dove il PDL definisce requisiti non ancora tracciati come task.

### 8.1 DeviceResource UI (PDL_UX_UI §6.2, PDL §6.6)

- **Stato roadmap**
  - Milestone A.5.1 copre l’introduzione dei tab concettuali (Overview, Discovery, Networking, SNMP Settings) e dell’Infolist Overview/Discovery di base.  
  - PDL principale (§6.6) ribadisce che DeviceResource deve avere i quattro tab obbligatori e che il tab Discovery deve includere un viewer `device_result` + azioni async (`Check connectivity`, `Full discovery`, `Test SNMP`).
- **Stato attuale (UI implementata)**
  - Il tab Discovery di `DeviceResource` mostra ora:
    - tipo e stato dell’ultimo job;  
    - schema_version del `device_result` più recente (se presente);  
    - conteggio per sezione (`interfaces`, `vlans`, `arp`, `dhcp_leases`, `neighbors`, `bridge_hosts`);  
    - elenco codici `errors[]` se presenti;  
    - JSON pretty‑print del `device_result` (o dell’intero `result` se la chiave non è disponibile).  
- **TODO (da aggiungere/raffinare in Milestone A.5/C.2)**
  - Raffinare il **DeviceResult viewer** nel tab Discovery:
    - mantenere il pretty‑print JSON di `result.device_result` con evidenza di `schema_version` e delle sezioni principali (`device`, `interfaces.items`, `vlans.items`, `arp.items`, `dhcp_leases.items`, `neighbors.items`, `bridge_hosts.items`, `snmp`);  
    - introdurre, se utile, un viewer più ricco (collapse/expand per sezioni e copia in clipboard) riutilizzabile anche nel Job Center.  
  - Allineare il tab Discovery a tutti i blocchi richiesti:
    - Last scan (tipo job, agent, timestamp);  
    - Health summary (REST/SNMP/subnet, stato `ok/partial/error`);  
    - REST status (ultimo `check_connectivity` + errori `device_result.errors[]` lato REST);  
    - SNMP status (quando pipeline SNMP Fase 1 sarà implementata).  
  - Esplicitare i tab “Networking” e “SNMP Settings” come pagine/sezioni distinte (non solo concettuali), mantenendo:
    - `PortsRelationManager` come tabella principale nel tab Networking (con colonne richieste dal PDL);  
    - schema `snmp_settings` completo nel tab SNMP Settings (connection, port name priority, custom OIDs) e azione “Test SNMP”.

### 8.2 ScanAgent UI (PDL_UX_UI §6.3)

- **Stato roadmap**
  - A.5.2 copre il tab “Logs” (AgentLogsRelationManager con filtri + action “View JSON context”).  
  - PDL_UX_UI richiede anche:
    - tab “Details” con PAT management (rotate/revoke, one‑time reveal) e preview configurazione (`ScanAgent.settings` + `/api/v1/agent/config`);  
    - tab “Jobs” come vista Job Center filtrata per agent.
- **TODO (estensione Milestone A.5 / C.4)**
  - Aggiungere alla ScanAgentResource:
    - tab Details completo:
      - gestione PAT (creazione/rotate/revoke) coerente con PDL;  
      - visualizzazione `last_seen` con badge stato (online/degraded/offline/outdated);  
      - pannello di anteprima config agent (estratto da `ScanAgent.settings` + ultimo `/api/v1/agent/config` ricevuto).  
    - tab Jobs (AgentJobsRelationManager):
      - tabella di job filtrata per `scan_agent_id`;  
      - colonne allineate al Job Center (type, scope, status, device, timestamps);  
      - link diretto al Job Center globale.

### 8.3 IPAM/DCIM UI estese (PDL_UX_UI §6.4, §6.7; PDL §6.4–6.5)

- **Stato roadmap**
  - A.5.3 copre Subnet (`last_scan`, `last_discovery`, filtro `scan_agent`) e Ports (`last_seen`, `discovered_by_scan_agent_id`) a schema e UI.  
  - PDL_UX_UI aggiunge:
    - Subnet “Discovery status” con indicatori partial dataset (es. `device_result.arp.partial`) e link a job/subnet discovery;  
    - Rack View “tipo ECCM” con schema a griglia, devices posizionati in verticale, export layout;  
    - IP Pool / IP Editing UX (ruoli IP, stati free/used/conflicted/locked).
- **TODO (da pianificare come estensione B.2/D.1/E)**
  - Subnet:
    - aggiungere sezione “Discovery status” nella Subnet view che mostri:
      - stato discovery (never run/scheduled/running/completed/partial/failed);  
      - indicatori per partial dataset basati su `device_result.*.partial`;  
      - link alle ultime discovery per quella subnet (Job Center filtrato).  
  - Rack View:
    - implementare vista rack a griglia coerente con PDL (layout U, click device → slide‑over, export rack layout via html2media/Export).  
  - IP Pool & IP Editing:
    - modellare in UI gli stati IP richiesti (free/used/conflicted/locked) e i campi di editing base (comment, type, category) secondo `PDL.md §IPAM`.

### 8.4 Job Center & DiscoveryEvent (PDL_UX_UI §6.5–6.6)

- **Stato roadmap**
  - C.4 descrive il Job Center basato su `AgentJob` e l’esistenza della slide‑over “View details” con JSON (payload/result/result_metadata/error_message).  
  - PDL_UX_UI richiede:
    - DeviceResult viewer integrato nel dettaglio job;  
    - elenco di DiscoveryEvent correlati al job (conflict events) con link alla pagina di approval;  
    - filtri completi (type, status, tenant, device, agent, scope).
- **Stato attuale (UI implementata parzialmente)**
  - Il dettaglio job (`AgentJobsTable` → action “View details”) mostra ora:
    - `schema_version` del `device_result` (se presente);  
    - conteggio per sezione (`interfaces`, `vlans`, `arp`, `dhcp_leases`, `neighbors`, `bridge_hosts`);  
    - JSON pretty‑print di `device_result` in un campo dedicato, oltre al `result` raw completo.  
- **TODO (estensione C.4 / collegamento con C.2)**
  - Estendere `AgentJobResource` per:
    - integrare un DeviceResult viewer più ricco e riutilizzabile anche lato DeviceResource (collapse/expand, focus su errori);  
    - mostrare elenco di `DiscoveryEvent` collegati al job (conflict events come MAC_MISMATCH, ORPHAN_IP, merge_conflict) con link alla Resource DiscoveryEvent per l’approval.  
  - Estendere DiscoveryEvent UI (nuova Resource o estensione esistente) per:
    - filtri su type/category;  
    - diff viewer per i cambi (old/new);  
    - batch actions per risolvere i conflitti (resolve/ignore, ecc.), in linea con PDL.  

### 8.5 System Health & Dashboard (PDL_UX_UI §6.1, §6.9)

- **Stato roadmap**
  - Sezioni A.2/A.3 coprono parzialmente il wizard e SystemState; non esisteva ancora una milestone dedicata alle cards System Health e alla dashboard principale.  
- **Stato attuale (dopo questo ciclo)**
  - SystemState:
    - `SystemStateSettings` (`app/Settings/SystemStateSettings.php`) contiene `installed`, `configured`, `last_scheduler_run_at`, `last_worker_heartbeat_at`, `last_health_check_at`.  
    - `last_scheduler_run_at` / `last_worker_heartbeat_at` vengono inizializzati dalle migrazioni/wizard ma non sono ancora aggiornati da job schedulati dedicati (scheduler/worker heartbeat).  
  - System Health page:
    - Aggiunta pagina Filament `SystemHealthPage` (`app/Filament/Pages/SystemHealthPage.php`) con view `resources/views/filament/pages/system-health.blade.php`, sotto il gruppo `Administration`.  
    - La pagina mostra cards per:
      - Scheduler: stato calcolato da `last_scheduler_run_at` secondo le soglie del PDL (<3 min OK, 3–10 min WARNING, >10 min CRITICAL).  
      - Worker: stato calcolato da `last_worker_heartbeat_at` con le stesse soglie.  
      - Database: check `DB::connection()->getPdo()` (UP/DOWN).  
      - Redis: se `database.redis.default` è configurato, usa `Redis::connection()->ping()` (UP/DOWN), altrimenti mostra `N/A` (“Redis not configured (not required)”).  
      - Storage: prova scrittura/lettura su `Storage::disk('local')` (UP/DOWN).  
      - Agents: riepilogo `ScanAgent` (`total`, `with_last_seen`, `last_seen_max`), senza introdurre logica di online/offline oltre quanto definito nel PDL.  
    - Il bottone “Run Health Check Now” (`runHealthCheck()`) aggiorna solo `SystemStateSettings::last_health_check_at` e mostra una notifica; non viene introdotto alcun `HealthCheckService` o job aggiuntivo rispetto a quanto già definito.  
    - La card Agents include esplicitamente una nota che Queue backlog e classificazione offline/online dettagliata verranno aggiunte in una fase successiva, per evitare assunzioni non descritte dal PDL.
- **TODO (nuova sotto‑milestone, es. A.6 / D.x)**
  - Dashboard:
    - implementare dashboard iniziale con KPI (devices, IPs, VLANs), agent status, recent discovery events, System Health summary.  
  - System Health:
    - completare wiring backend per:
      - aggiornare `last_scheduler_run_at` ad ogni `php artisan schedule:run`;  
      - aggiornare `last_worker_heartbeat_at` da un `WorkerHeartbeatJob` schedulato come da PDL;  
      - introdurre eventualmente un `HealthCheckService`/job dedicato se e quando verrà definito nei dettagli nel PDL (oggi la pagina calcola gli stati on‑the‑fly).  
    - aggiungere metrica `Queue backlog` coerente con il driver di coda effettivamente in uso, senza assumere un backend specifico (DB / Redis) finché non viene normato nel PDL;  
    - estendere la card Agents per mostrare conteggi online/offline basati su una soglia configurabile esplicita (non ancora modellata in Settings) e, se richiesto, propagare lo stato in un banner persistente di sistema.

### 8.6 Settings UX & Wizard Fase 2 (PDL_UX_UI §7–§8)

- **Stato roadmap**
  - A.2/A.3 descrivono Wizard Fase 1; D.3 introduce Notifications & Telegram Fase 1 lato backend.  
  - PDL_UX_UI definisce:
    - raggruppamento Settings (General, Discovery, Notifications, Security, Feature Flags);  
    - wizard Fase 2 (email/telegram/redis test) con test espliciti.
- **Stato attuale (dopo questo ciclo)**
  - GeneralSettings:
    - `GeneralSettings` (`app/Settings/GeneralSettings.php`) esposta tramite `GeneralSettingsPage` (`app/Filament/Pages/GeneralSettingsPage.php`) con:
      - `site_name`, `site_color`, `time_display_format`, `agent_alert_level`, `app_url`, `force_https`;  
      - layout condiviso `resources/views/filament/pages/general-settings.blade.php`.  
  - DiscoverySettings:
    - `DiscoverySettings` (`app/Settings/DiscoverySettings.php`) esposta tramite `ManageDiscoverySettings` (`app/Filament/Clusters/Discovery/Pages/ManageDiscoverySettings.php`) con i campi:
      - `auto_discovery_enabled`, `default_scan_interval_minutes`, `icmp_timeout_ms`, `icmp_retries`, `prune_logs_days`,  
      - `agent_polling_interval_minutes`, `timeout_rest_ms`, `timeout_snmp_ms`, `max_records_per_dataset`;  
    - la pagina utilizza la view `resources/views/filament/pages/discovery-settings.blade.php` e rispetta il PDL UX (max records + timeout override).  
  - SecuritySettings:
    - `SecuritySettings` (`app/Settings/SecuritySettings.php`) esposta tramite `SecuritySettingsPage` (`app/Filament/Pages/SecuritySettingsPage.php`) con:
      - `min_agent_version`, `min_supported_schema_version`, `agent_tls_verify`, `max_agent_payload_bytes`;  
    - la pagina ora riusa il layout generico `filament.pages.general-settings` per evitare la view mancante `security-settings.blade.php`.  
  - FeatureFlagSettings:
    - `FeatureFlagSettings` (`app/Settings/FeatureFlagSettings.php`) esposta tramite `FeatureFlagSettingsPage` (`app/Filament/Pages/FeatureFlagSettingsPage.php`) con:
      - `snmp_fase1`, `chunking`, `gzip`;  
    - tutti i flag sono rappresentati come toggle read‑only rispetto alla semantica del PDL (nessun comportamento aggiuntivo implementato qui).  
  - SystemStateSettings:
    - `SystemStateSettings` rimane senza pagina di editing (come da PDL); viene usata da middleware/wizard e da System Health, non modificabile da UI.  
- **TODO (estensione A.2/D.3)**
  - Settings UX:
    - introdurre pagina Filament per `NotificationSettings` (`NotificationSettingsPage`) quando la classe sarà disponibile, con i soli campi descritti nel PDL (webhook URL Fase 1, template Telegram Fase 2);  
    - mantenere allineati i form Discovery/Security/Feature Flags se il PDL introducesse nuovi campi (es. token rotation lato Security);  
    - collegare questi settings alle funzionalità già pianificate (Discovery pipeline, Notifications & Telegram, Security min_agent_version/tls_verify), senza introdurre logica aggiuntiva non prevista dal PDL.  
  - Wizard Fase 2:
    - aggiungere step opzionali al wizard per:
      - test email (SMTP) con feedback success/fail;  
      - test Telegram;  
      - test Redis, con messaggistica chiara su cosa succede se non è disponibile in produzione.

### 8.7 Agent ENV & struttura progetto (PDL §2.7, §7.1.1, §7.7)

- **Stato roadmap**
  - A.1/C.1 coprono Compose V2 per il server e `/api/v1/agent/config`; il PDL specifica anche:
    - env contract semplificato per l’agent (`SERVER_URL`, `AGENT_API_TOKEN`, `LOG_LEVEL`, `AGENT_POLL_INTERVAL_SECONDS`, `AGENT_CONFIG_REFRESH_SECONDS`);  
    - presenza esplicita di `remote-agent/.env.example` e `remote-agent/docker-compose.yml`;  
    - struttura target del progetto remote-agent (Drivers/Jobs/Http/Support/...).
- **Stato attuale (dopo questo ciclo)**
  - `remote-agent/.env.example` esiste e contiene:
    - `SERVER_URL`, `AGENT_API_TOKEN`, `LOG_LEVEL`, `AGENT_POLL_INTERVAL_SECONDS`, `AGENT_CONFIG_REFRESH_SECONDS` secondo PDL.  
  - `remote-agent/docker-compose.yml`:
    - espone un servizio `agent` con:
      - `SERVER_URL: ${SERVER_URL}`  
      - `AGENT_API_TOKEN: ${AGENT_API_TOKEN}`  
      - `LOG_LEVEL: ${LOG_LEVEL:-info}`  
      - `AGENT_POLL_INTERVAL_SECONDS: ${AGENT_POLL_INTERVAL_SECONDS:-5}`  
      - `AGENT_CONFIG_REFRESH_SECONDS: ${AGENT_CONFIG_REFRESH_SECONDS:-60}`  
    - monta `./` in `/app` e usa `php /app/agent.php` come comando, coerente con PDL.  
  - `AgentWorkCommand`:
    - legge solo `SERVER_URL` come sorgente autorevole per la base URL e rimuove eventuali suffissi `/api` o `/api/vX` se presenti per errore, in linea con il contratto “SERVER_URL senza /api”;  
    - legge solo `AGENT_API_TOKEN` (da env o da `.env` locale) come PAT;  
    - usa `AGENT_POLL_INTERVAL_SECONDS` e `AGENT_CONFIG_REFRESH_SECONDS` (se presenti e >0) per configurare le frequenze di polling jobs e config, con default 5s / 60s come nel PDL;  
    - mantiene il logging coerente con queste variabili (`Using AGENT_API_TOKEN: ****`).
- **TODO (estensione C.1 / struttura progetto agent)**
  - La struttura del progetto `remote-agent/` è ora allineata a grandi linee al layout target descritto in PDL (§2.7 / §7.7):
    - `src/Console/AgentWorkCommand.php`,  
    - `src/Drivers/MikrotikApiDriver.php`,  
    - `src/Jobs/JobHandler.php` (ex Handlers\JobHandler),  
    - `src/Http/NetVibeHttpClient.php`,  
    - `src/Support/DeviceResultBuilder.php`,  
    - `src/Logging/Logger.php`,  
    - `bin/netvibe-agent` come wrapper CLI che richiama `AgentWorkCommand::run()`,  
    - directory `config/`, `tests/`, `docs/` predisposte con README per futuri estensioni (preset, test agent, documentazione specifica).  
  - Nelle iterazioni successive si potranno:
    - aggiungere effettivi job SNMP/Connectivity/Discovery dentro `src/Jobs/` (SnmpEnrichmentJob, DiscoveryJob) e nuovi driver SNMP/UniFi in `src/Drivers/`,  
    - popolare `config/`, `tests/`, `docs/` con il contenuto descritto dal PDL, senza modificare ulteriormente il contratto env (`SERVER_URL`/`AGENT_API_TOKEN`/`LOG_LEVEL`/`AGENT_POLL_INTERVAL_SECONDS`/`AGENT_CONFIG_REFRESH_SECONDS`), ormai unico e privo di env legacy.

---

## 9. Vista Epics / Task / Sotto‑task (per agenti più basici)

Questa sezione riorganizza le milestone in **epic** e task/sotto‑task atomici, mantenendo invariato il contenuto precedente, per facilitare l’esecuzione da parte di agent meno sofisticati.

### Epic A — Infra, Setup & UI di base

- **A1. Compose & infra server**
  - A1.1 Garantire unico `compose.yml` per app/worker/db/redis/test-db (già implementato, verificare allineamento `.env.example`).  
  - A1.2 Rimuovere definitivamente `docker-compose.yml` legacy e riferimenti nei docs.  
- **A2. Setup Wizard Fase 1**
  - A2.1 Completare controlli preflight (PHP/ext, storage, DB, Redis opzionale).  
  - A2.2 Hardening `SystemStateSettings` + middleware `CheckSystemInstalled`.  
  - A2.3 Flusso completo wizard (preflight → migrate → admin → customer/location → app).  
- **A3. UI Filament base**
  - A3.1 Allineare DeviceResource ai tab concettuali (Overview, Discovery, Networking, SNMP).  
  - A3.2 Esporre campi discovery chiave in IPAM/DCIM (Subnets last_scan/last_discovery; Ports last_seen/discovered_by_scan_agent_id).  
  - A3.3 Implementare tab Logs per ScanAgent (filtri + view JSON context).  
- **A4. Dashboard & System Health (TODO Epic A esteso)**
  - A4.1 Implementare dashboard con KPI e System Health summary.  
  - A4.2 Implementare pagina System Health con cards Worker/Scheduler/Queue/Agent + “Run Health Check Now”.

### Epic B — Tenancy & Domain Core

- **B1. Tenancy esplicita**
  - B1.1 Usare `TenantResolverService` in `TenantScope` (completato).  
  - B1.2 Rimuovere inferenze `session('tenant_id')` dai model hooks (completato).  
  - B1.3 Validare i flussi di UI (Tenant Switcher) rispetto al nuovo resolver (test da aggiungere in milestone test).  
- **B2. Pulizia schema IPAM/DCIM**
  - B2.1 Rimuovere definitivamente VRF/L2Domain/Sections via migrazioni (completato).  
  - B2.2 Introdurre `last_seen_*` + `last_seen_overall` per i device (parzialmente completato).  
  - B2.3 Introdurre e usare tabelle blacklist VLAN/Subnet (completato).  
  - B2.4 TODO: completare rimozione chiavi legacy nel dominio (es. sections, residui schema) seguendo `docs/99_DEBT_AND_INCONSISTENCIES.md`.  
- **B3. SNMP Settings Fase 1 (modello + UI)**
  - B3.1 Migrazione `snmp_settings` + model `SnmpSettings` (completato).  
  - B3.2 Integrazione `snmp_settings` in DeviceForm/DeviceResource (completato).  
  - B3.3 TODO: preset vendor SNMP e future colonne SNMP su `ports` (`snmp_if_index`, `snmp_*`), come da PDL §6.4/6.5.

### Epic C — Discovery & Agent Pipeline

- **C1. Contratto agent config & device_result**
  - C1.1 `/api/v1/agent/config` conforme al PDL (completato).  
  - C1.2 `DeviceResultBuilder` per Mikrotik (device, interfaces, vlans, arp, dhcp, neighbors, bridge_hosts, errors) (completato).  
  - C1.3 Validazione `device_result.schema_version` sul server (`AgentJobController@storeResult`) e negoziazione lato agent (`AgentWorkCommand`) (completato parzialmente).  
- **C2. Ingest & Merge basato su device_result**
  - C2.1 Usare `MergePolicyService` per Device (hostname/serial) e IP (mac/hostname) (parzialmente completato).  
  - C2.2 Rifattorizzare ingest Mikrotik (Inventory/Topology/ARP) per usare direttamente `device_result` (parzialmente completato, con fallback legacy espliciti).  
  - C2.3 TODO: eliminare completamente i percorsi legacy (`bridge_vlan`, `ip_neighbors`, `arp_table`, `dhcp`) quando i test saranno allineati, e collegare `MergeResult->events` a `DiscoveryEvent`.  
- **C3. SNMP Fase 1 pipeline (TODO)**
  - C3.1 Implementare `SnmpService` server-side.  
  - C3.2 Implementare `SnmpDriver` + `SnmpEnrichmentJob` nel remote agent.  
  - C3.3 Estendere `device_result` con sezione `snmp` (device+interfaces) e integrare in ingest/merge.  
- **C4. Job Center & Logging/Audit**
  - C4.1 Completare Job Center (AgentJobResource) con filtri completi e slide‑over dettagli payload/result (completato base).  
  - C4.2 Integrare DeviceResult viewer e DiscoveryEvents correlati nel dettaglio job (TODO).  
  - C4.3 Hardening ActivityLog/AgentLog per tenancy e retention (parzialmente completato).

### Epic D — Export, Backup, Notifications

- **D1. Export & PDF**
  - D1.1 Export CSV/XLSX per Subnets, IP, Devices, Ports, DiscoveryEvents (completato).  
  - D1.2 PDF base per Subnets via html2media (completato).  
  - D1.3 TODO: estendere PDF/Exports a DCIM, Discovery, Settings con template avanzati.  
- **D2. Backup DB**
  - D2.1 Implementare job backup DB con retention configurabile (TODO).  
- **D3. Notifications & Telegram Fase 1**
  - D3.1 Implementare NotificationSettings + integrazione Telegram minima (TODO backend+UI).  

### Epic E — UI avanzata & Observability (Nice‑to‑have / Fase 2)

- **E1. SNMP avanzato**
  - SNMP avanzato (vendor specifici, PoE, temperature, SNMPv3) — vedi Milestone E.  
- **E2. Telegram avanzato**
  - Bot commands (`/health`, `/last_event`, `/agent_status`, inline keyboard) — Milestone E.  
- **E3. Visual regression & UI testing**
  - Playwright/Chromatic per viste critiche, script `./dev/test-ui`/`./dev/test-agent` allineati al PDL UI/Testing.  

Questa vista Epics/Task non sostituisce le sezioni A–D, ma le rende navigabili per agent meno sofisticati mantenendo tutto il contesto, le note aperte e i TODO già presenti.  

---

## 10. Informazioni Necessarie Mancanti

Questa sezione raccoglie i punti in cui il PDL o il codice non forniscono ancora dettagli sufficienti per procedere senza introdurre assunzioni.

- **10.1 NotificationSettings & NotificationSettingsPage**
  - Il PDL (`docs/PDL.md`, `docs/PDL_UX_UI.md §7`) menziona `NotificationSettings` e una `NotificationSettingsPage` con:
    - webhook URL (Fase 1);  
    - template Telegram (Fase 2).  
  - Nel codice non esiste ancora `app/Settings/NotificationSettings.php`.  
  - Mancano:
    - elenco preciso dei campi (nomi, tipi, default, struttura dei template);  
    - eventuale separazione per tipo di notifica (discovery, backup, agent alert, ecc.);  
    - definizione del group Spatie (`group(): string`) e mapping UI (sezione/esatto layout Filament).  
  - Finché questi punti non sono definiti nel PDL principale, non si procede con l’implementazione di `NotificationSettings`/`NotificationSettingsPage`.

- **10.2 DiscoveryEvent ↔ AgentJob (Job Center conflict events)**
  - Il PDL (PDL_UX_UI §6.5 Job Center) prevede che, nel dettaglio di un job, siano mostrati i “conflict events” generati dal merge (lista `DiscoveryEvent` correlati al job, con link diretto).  
  - L’attuale modello `DiscoveryEvent` (`app/Models/DiscoveryEvent.php`) non contiene alcun riferimento a `AgentJob` (nessun `agent_job_id` o relazione analoga).  
  - Il PDL non specifica ancora:
    - il contratto dati tra `MergePolicyService`/`MergeResult` e `DiscoveryEvent`;  
    - la chiave di correlazione definitiva (`agent_job_id`, `device_id`+timestamp, altro).  
  - Prima di collegare UI Job Center → DiscoveryEvent è necessario definire nel PDL lo schema dati e la relazione esatta.

- **10.3 Auto‑creazione subnet `/24` in IngestMikrotikArpAction**
  - Nota aperta già presente in B.2: l’implementazione corrente può auto‑creare subnet `/24` in `IngestMikrotikArpAction` basandosi sulla PVID della porta.  
  - Il PDL aggiornato non esplicita se questo comportamento:
    - debba essere mantenuto così com’è;  
    - debba essere reso configurabile (es. per tenant);  
    - debba essere sostituito da una logica guidata dalla Subnet view / Discovery status.  
  - Serve una decisione nel PDL prima di modificare o rimuovere questa auto‑creazione.

- **10.4 Modellazione VLAN↔bridge/interfacce (Mikrotik / multi‑bridge)**
  - Nota aperta in C.2: `DeviceResultBuilder::buildVlansSection()` oggi legge `vlan-ids` da `/rest/interface/bridge/vlan` senza distinzione chiara tra bridge fisici/logici; il PDL Fase 1 chiede solo un elenco VLAN “flat”.  
  - Il PDL non ha ancora definito:
    - come gestire device con più bridge o nomi non standard;  
    - se e come modellare la relazione VLAN↔bridge↔porta in Fase 2.  
  - Fino a quando il PDL non fornirà un modello preciso, l’implementazione VLAN rimane volutamente “flat” e non si procede con arricchimenti.

- **10.5 SNMP Fase 1 – dettagli driver/pipeline**
  - PDL e roadmap (C.3) definiscono gli obiettivi di SNMP Fase 1 (sysName/sysDescr/sysObjectID, ifTable base, 1–2 OID globali), ma non sono ancora dettagliati:
    - l’elenco definitivo degli OID obbligatori e opzionali;  
    - il formato esatto della sezione `device_result.snmp` (chiavi, tipi, eventuali flag `partial`);  
    - il contratto preciso tra `SnmpService` (server), `SnmpDriver` (agent) e `SnmpEnrichmentJob`.  
  - Finché questi elementi non saranno descritti in modo esplicito nel PDL, l’implementazione SNMP Fase 1 rimane sospesa (solo schema UI `SnmpSettings` e Feature Flag `snmp_fase1` sono pronti).

- **10.6 Agent System Health – queue backlog & soglia offline/online**
  - PDL System Health (§13 e PDL_UX_UI §6.9) menziona:
    - metriche di queue backlog;  
    - badge “Agent online/offline” basato su soglia configurabile.  
  - Attualmente:
    - non esiste una soglia esplicita a schema per la classificazione agent online/offline;  
    - il PDL non specifica il meccanismo di calcolo del backlog (driver coda DB vs Redis, code multiple).  
  - Prima di implementare queste metriche:
    - serve definire nel PDL dove memorizzare la soglia (Settings dedicato) e la semantica esatta (es. `last_seen > N minuti` → offline);  
    - serve chiarire se il backlog è calcolato su una coda specifica (`discovery`) o globalmente, e come viene esposto in UI.

- **10.7 HealthCheckService & job scheduler/worker heartbeat**
  - PDL menziona un `HealthCheckService` e job di heartbeat/scheduler che aggiornano `SystemStateSettings.last_scheduler_run_at` e `last_worker_heartbeat_at`.  
  - Nel codice:
    - non esistono ancora `HealthCheckService`, `WorkerHeartbeatJob` o job analoghi;  
    - il wiring concreto (quali comandi/artisan, quali schedule) non è ancora normato.  
  - Prima di aggiungere questi componenti è necessario che il PDL definisca:
    - il nome e la frequenza del job;  
    - a quali code appartiene;  
    - se deve fare solo update timestamp o ulteriori controlli (es. queue length).

- **10.8 Notification pipeline completa (webhook/Telegram)**
  - Oltre a `NotificationSettings`, il PDL menziona integrazione Telegram e webhook ma non dettaglia:
    - le tipologie di eventi che devono generare notifiche (discovery_conflict, backup_failed, agent_offline, ecc.);  
    - la struttura del payload da inviare ai webhook o ai bot Telegram;  
    - la strategia di deduplicazione/ratelimit (se prevista).  
  - Per ora è implementata solo la logica di alert sugli AgentLog (`AgentLogController::triggerAlertIfNeeded`) basata su `GeneralSettings.agent_alert_level`; tutto il resto della pipeline notifiche resta in attesa di specifiche più precise.

Questi punti devono essere chiariti nei PDL (tecnico e UX) prima di procedere con ulteriori modifiche strutturali, per rispettare il vincolo “nessuna assunzione non documentata” richiesto dal progetto.  
