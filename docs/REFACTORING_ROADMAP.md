# Refactoring Roadmap — NetVibe IPAM/DCIM

Questo documento traduce il PDL in una roadmap di refactoring concreta. Il codice esistente **non** è autorevole: dove diverge dal PDL, va adeguato tramite rifattorizzazione o riscrittura.

Le priorità sono raggruppate per modulo, con indicazione di dipendenze e ordine consigliato.

## 1. Priorità alta (bloccanti)

### 1.1 Setup / Wizard / Install flow

- **Stato attuale**: wizard minimale (solo preflight base, migrate, primo utente), middleware `CheckSystemInstalled` legato solo alla tabella `users`.  
- **Obiettivo PDL**:
  - Wizard multi-step con:
    - preflight approfondito (PHP/ext, storage, .env, DB, Redis).  
    - esecuzione migrazioni.  
    - creazione primo admin.  
    - creazione primo Customer + Location.  
    - configurazione base (locale, app_url, queue driver, Redis).  
    - test email/Telegram (Fase 2).  
    - pagina diagnostica con log di errori.
  - Stato installazione basato su `system_state` (o settings equivalenti), non solo presenza utenti.
- **Azioni**:
  - Riscrivere `SetupController`, views `resources/views/setup/*`, e middleware `CheckSystemInstalled` per aderire al PDL.  
  - Introdurre un service `InstallStateService` o equivalente per centralizzare la logica “installed/configured”.

### 1.2 Tenancy & TenantResolver

- **Stato attuale**: vari modelli inferiscono `customer_id` da `session('tenant_id')`, location o rack; job/ingest dipendono implicitamente dalla sessione in alcuni punti.  
- **Obiettivo PDL**:
  - `tenant_id` esplicito per tutti i job, ingest e API.  
  - nessuna inferenza da session dentro i modelli.  
  - `TenantResolverService` centralizzato per decidere tenant corrente per UI, API, job.
- **Azioni**:
  - Introdurre `TenantResolverService` e usarlo nei punti di entry (controller, job, actions), non nei model `boot`.  
  - Eliminare la logica di inferenza da session in `Device::booted` e altri modelli, sostituendola con attributi obbligatori/validati.

### 1.3 Remote Agent & device_result

- **Stato attuale**: agent restituisce array Mikrotik specifici (`resource`, `identity`, `interfaces`, `bridge_vlan`, `arp_table`, `dhcp_leases`); nessun `device_result` normalizzato; nessun chunking/lmiti di size; TLS verify disabilitato.  
- **Obiettivo PDL**:
  - `device_result` come payload unico normalizzato, con `schema_version` semver, sezioni `device/interfaces/vlans/ip_addresses/arp/dhcp_leases/neighbors/bridge_hosts/errors`.  
  - normalizzazione chiavi, tempi (uptime_seconds, *_seconds), partial dataset (`items/partial/limit`).  
  - supporto gzip + chunking con limite 20 MB post-compress.  
  - timeouts agent→Mikrotik e agent→server coerenti con PDL.  
  - preparazione pipeline SNMP Fase 1 (anche solo stub iniziale).
- **Azioni**:
  - Introdurre una classe `DeviceResultBuilder` nel remote agent.  
  - Adeguare `JobHandler::handleDiscoveryDeep` a costruire `device_result` e non array Mikrotik raw.  
  - Implementare protocollo di chunking/gzip (usando `NetVibeHttpClient` come base).  
  - Aggiungere endpoint `/api/v1/agent/config` ricco e relativo handling in `AgentWorkCommand::applyRuntimeConfig`.

### 1.4 ProcessAgentJobResultAction & ingest Mikrotik

- **Stato attuale**: pipeline `ProcessAgentJobResultAction` + `IngestMikrotikInventoryAction/TopologyAction/ArpAction` basata su payload Mikrotik non normalizzato; merge policy implicita.  
- **Obiettivo PDL**:
  - lavorare su `device_result` normalizzato.  
  - delegare le decisioni di merge a `MergePolicyService`.  
  - rimuovere `Schema::hasColumn` e logica schema-dipendente runtime.  
  - estendere pipeline per supportare anche SNMP/UniFi senza duplicare logica.
- **Azioni**:
  - Introduzione di `MergePolicyService` e utilizzo in tutte le azioni ingest.  
  - Refactoring graduale delle azioni Mikrotik per usare il nuovo schema.  
  - Migrazioni per rendere schema consistente (eliminare rami condizionali su colonne).

### 1.5 System Health

- **Stato attuale**: inesistente (solo CheckSystemInstalled).  
- **Obiettivo PDL**:
  - pagina Filament “System Health” con controlli scheduler/worker/Redis/DB/storage.  
  - tracking `last_scheduler_run_at` e `last_worker_heartbeat_at` in `system_state` o settings.  
  - integrazione con queue (worker down → feature disabilitate).
- **Azioni**:
  - Creare tabella/Settings per `system_state` (con last_* e altri flag).  
  - Aggiungere heartbeat scheduler/worker in `Console\Kernel` e nei job.  
  - Implementare pagina Filament dedicata secondo PDL.

## 2. Priorità media

### 2.1 ScanAgent & PAT management

- **Stato attuale**: ScanAgent ha PAT via Sanctum, settings JSON, last_seen; PAT management in UI basico.  
- **Obiettivo PDL**:
  - PAT rotabili, mostrati una sola volta, mask in UI.  
  - settings coerenti con config agent (`timeout_rest/snmp`, `tls_verify`, `max_records`, feature flags).  
  - health/liveness tracking integrato con System Health.
- **Azioni**:
  - Estendere `ScanAgentResource` con sezione “API Token” e pulsante “Regenerate”.  
  - Collegare `settings` di ScanAgent a `/agent/config`.  
  - Integrare agent health indicator con System Health.

### 2.2 Device / DeviceForm (DCIM)

- **Stato attuale**: campi legacy (sections, rack_size), commento che SNMP fields sono stati rimossi, logica tenancy e rack nel boot.  
- **Obiettivo PDL**:
  - tab “Discovery” con toggle REST/SNMP enrichment.  
  - tab “SNMP Settings” strutturata (Connection, Port Name Strategy, Custom OIDs).  
  - mappatura porte coerente con DCIM/IPAM (solo porte fisiche, interfacce logiche come metadata).  
  - nessuna logica tenancy nel model; tutta la logica cross-entity spostata in servizi.
- **Azioni**:
  - Pulire il model Device da campi obsoleti e `Schema::hasColumn`.  
  - Riscrivere DeviceForm in cluster Racks per includere le tab Discovery/SNMP.  
  - Spostare heuristics di tenancy e rack in servizi dedicati.

### 2.3 DiscoveryEvent

- **Stato attuale**: centrato su conflitti IP/MAC e ORPHAN_IP da ARP ingest.  
- **Obiettivo PDL**:
  - supportare categorie evento (Connectivity, Merge, SNMP, REST, WAN).  
  - diff strutturato old/new per campo.  
  - integrazione diretta con MergePolicyService.  
  - UI comparativa campo-per-campo, template di decisione.
- **Azioni**:
  - Estendere schema DiscoveryEvent (tipi, categorie, payload strutturato).  
  - Aggiornare Resource Filament con vista diff e azioni di merge.  
  - Collegare generazione DiscoveryEvent alle decisioni di MergePolicyService.

### 2.4 ActivityLog & AgentLog

- **Stato attuale**: ActivityLog Spatie usato in parte; AgentLog con relation manager.  
- **Obiettivo PDL**:
  - ActivityLog con `tenant_id` e scope globale obbligatorio.  
  - log JSON-only per agent e server.  
  - UI filtrata per tenant/entity e slideOver per dettagli.  
  - retention configurabile.
- **Azioni**:
  - Aggiornare ActivityLog per includere tenant_id e scoping.  
  - Allineare `AgentLog` al formato JSON.  
  - Creare `ActivityLogResource` globale e affinare `AgentLogsRelationManager`.

### 2.5 Job Center (basato su AgentJob)

- **Stato attuale**: AgentJobsRelationManager per Device/ScanAgent, nessun “Job Center” globale.  
- **Obiettivo PDL**:
  - Job Center che mostri tutti i job (discovery, export, backup, notify, ecc.) con scope e stato.  
  - basato su `AgentJob` (esteso) anziché su una nuova tabella.
- **Azioni**:
  - Estendere `AgentJob` con campi `scope`, `result_metadata`, `error_message`, `tenant_id`.  
  - Creare `JobCenterResource` Filament con filtri e azione “view logs” che si appoggia ai log JSON.

## 3. Priorità bassa / Fase 2

### 3.1 SNMP avanzato

- Vendor avanzati (Fortigate, Cisco, HP/Aruba) con MIB specifiche.  
- OID per-port per PoE, temperature, metriche.  
- SNMPv3 integrazione completa.

### 3.2 Telegram avanzato & bot commands

- Rate limit per tenant, aggregazione di eventi.  
- Comandi bot `/health`, `/last_event`, `/agent_status`.  
- Inline keyboard avanzati con deep link.

### 3.3 Visual Regression

- Integrazione Playwright/Chromatic (o simili) per screenshot regression.

## 4. Trash list (da riscrivere / da rimuovere)

Questi componenti sono considerati candidati alla riscrittura totale o rimozione:

- Wizard attuale (`SetupController` + views `resources/views/setup/*`) — sostituito dal nuovo wizard conforme al PDL.  
- Logica di inferenza tenancy nei model (es. blocchi in `Device::booted` basati su `session('tenant_id')`).  
- Qualsiasi path del codice che dipende da `Schema::hasColumn` per decidere il comportamento runtime.  
- SNMP fields/commenti legacy (es. commento “SNMP-related fields removed” in DeviceForm) — saranno rimpiazzati da nuova tab Discovery/SNMP.  
- Eventuali debug endpoints `/_debug/*` ancora presenti.  
- Moduli o script legacy sotto `docker/remote-agent/` non più utilizzati dalla nuova pipeline agent.

## 5. Keep & refine list (da salvare e consolidare)

Questi componenti hanno una buona base e vanno mantenuti, rifinendo dove serve:

- `AgentJob` (model) + `AgentJobController` API — base per Job Center e orchestrazione agent jobs.  
- `ScanAgent` (model) e relativa Resource Filament — da estendere con PAT management e settings coerenti.  
- Azioni Mikrotik ingest:
  - `IngestMikrotikInventoryAction`  
  - `IngestMikrotikTopologyAction`  
  - `IngestMikrotikArpAction`  
  (vanno rifattorizzate per usare `device_result` + MergePolicyService, ma la logica di dominio è solida).  
- IPAM core:
  - `IpConversionService`, `MacAddressService`  
  - modelli `Subnet`, `IpAddress`, `Vlan` e relative regole.  
- Filament clusters e Resources già coerenti (IPAM, Racks & Devices, Discovery) — da allineare gradualmente al nuovo PDL ma non da buttare.  
- Trait TenantAware/TenantScope — utili se usati in coerenza con `TenantResolverService`.

