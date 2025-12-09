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
