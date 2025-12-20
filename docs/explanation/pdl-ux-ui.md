# PDL — UI/UX DESIGN SPECIFICATION

Versione 1.0 – documento formale

## 0. Introduzione

### 0.1 Scopo del documento

Questo documento definisce tutte le convenzioni UI/UX dell’applicazione NetVibe, includendo:

- Principi di progettazione  
- Pattern di interazione  
- Comportamenti asincroni  
- Linee guida visive  
- Specifiche per Filament **v4 only** (unico framework UI supportato)  
- UX per discovery, agent, DCIM, IPAM  
- Wizard iniziale  
- Error UX e System Health  
- Linee guida per testing e qualità UI

### 0.2 Audience

- Senior Laravel developer  
- Junior developer e contributor esterni  
- UI/UX designer  
- Maintainer del progetto  
- Autori di moduli aggiuntivi  
- Sviluppatori dell’agent

### 0.3 Copertura

- Copre ogni modulo dell'interfaccia, dalla navigazione alla gestione dei job e dei wizard.  
- Non descrive in dettaglio le API REST/agent (coperte dal PDL principale).

### 0.4 Non-obiettivi

- Non è una guida per lo sviluppo backend puro.  
- Non contiene specifiche complete REST/agent (fare riferimento al PDL principale).

### 0.5 Relazione con altri documenti

Fa riferimento a:

- `docs/PDL.md` (architettura e dominio)  
- `docs/REFACTORING_ROADMAP.md`  
- `docs/TESTING_QA_GUIDELINES.md`  
- `docs/03_CORE_LOGIC_IPAM.md`, `docs/04_CORE_LOGIC_DCIM.md` per la logica di dominio

## 1. Principi Fondamentali di UI/UX

### 1.1 Obiettivi UX

NetVibe è pensato per tecnici di rete: deve essere chiaro, veloce, esplicativo negli errori e coerente.

Obiettivi principali:

- Ridurre il tempo per diagnosticare problemi di rete.  
- Minimizzare la confusione in presenza di errori.  
- Fornire contesto e spiegazioni.  
- Evitare UI bloccanti o lente.  
- Mostrare sempre perché qualcosa non è disponibile.

### 1.2 Architettura dell’informazione

Cluster laterali (sidebar):

- DCIM & Racks  
- Discovery  
- IPAM  
- Organization (customer/location/rack mgmt)  
- Infrastructure (devices, agents, credentials)  
- Settings

### 1.3 Coerenza cross-modulo

- Stesse icone per le stesse entità.  
- Stessa semantica per colori (success/warning/danger).  
- Stesso pattern async per job-based actions.

### 1.4 Riduzione complessità

- UI ridotta ai campi necessari per il caso principale.  
- Sezioni avanzate nascoste dietro collapse/“Advanced”.  
- Fallback ai valori di default visualizzati chiaramente in help text.

### 1.5 Progressive disclosure

- Regola: mostra informazioni avanzate solo quando richieste.  
- Non sovraccaricare la vista principale con tutti i dettagli di discovery.

### 1.6 UX per ambienti tecnici

- Messaggi di errore precisi, mai generici (“Something went wrong”).  
- Badges di stato sempre visibili sulle entità chiave (Device, Agent, Job).  
- Partial data ≠ success: la UI deve esplicitare quando i dati sono incompleti.

### 1.7 Gestione stati

Ogni vista deve rappresentare in modo chiaro:

- `idle`  
- `loading`  
- `running`  
- `partial_success`  
- `failed`

### 1.8 No UI blocking

- Niente operazioni synchronous lunghe lato UI.  
- Tutte le operazioni che coinvolgono agent/queue devono essere job-based e asincrone.

## 2. Design System

### 2.1 Filosofia

- Dark-mode first, light-mode opzionale (se supportato dal tema Filament).  
- Tema coerente con Filament, senza CSS custom ad hoc.

### 2.2 Palette colori

Palette di riferimento (concettuale; implementata tramite theme Filament):

```text
Primary:      #3b82f6 (blue-500)
Primary dark: #1d4ed8
Danger:       #dc2626
Warning:      #f59e0b
Success:      #10b981
Neutral:      grayscale 200-900
Background:   #0f1116
Cards:        #1b1e25
```

Vendor option colors (soft tones):

- Mikrotik → cyan  
- UniFi → indigo  
- Cisco → teal  
- Fortigate → red

### 2.3 Typography

```text
Purpose      Class
Title        text-xl font-semibold
Subtitle     text-lg text-gray-400
Body         text-sm
Metadata     text-xs text-gray-500
Code / JSON  font-mono text-xs
```

### 2.4 Grid & spacing

- Basato su 8pt grid system.  
- Cards: padding ~24px.  
- Spaziatura verticale tra sezioni principali: ~32px.

### 2.5 Componenti base

- **Buttons**
  - primary  
  - subtle  
  - ghost  
  - destructive
- **Badges**
  - default, warning, success, danger  
  - vendor-colors opzionali dove rilevante (es. Mikrotik vs UniFi)
- **Cards**
  - header + body  
  - shadow-sm

### 2.6 Iconografia

Usiamo Lucide (o icone equivalenti nel tema Filament):

```text
Concetto    Icona
Discovery   radar
Device      server
IP          network
VLAN        cable
SNMP        globe
Agent       satellite-dish
Queue       queue
```

### 2.7 Modali / Slide-over

Regole:

- Slide-over per editing entità (Forms principali).  
- Modal per conferme/dangerous actions (delete, reset token, force merge).  
- Evitare modali nested.

## 3. Pattern di Interazione Globali

### 3.1 Navigation

Sidebar clusterizzata in:

- DCIM & Racks  
- Discovery  
- IPAM  
- Organization  
- Infrastructure  
- Settings

### 3.2 Breadcrumb

Esempio:

```text
Infrastructure → Devices → Mikrotik-Office → Interfaces
```

### 3.3 Header actions

Le azioni più usate in una vista devono comparire nel header:

- Run Discovery  
- Export  
Add

Health

3.4 Search global

Ricerca cross-entity per:

Device name

IP

MAC

VLAN ID

Agent name

3.5 Quick actions palette

Shortcut: CMD+K

3.6 Toast notifications

Tutte le azioni async → toast immediato:

✳️ Job queued

❌ Error

⚠️ Partial

3.7 Error banner

Se worker down → banner rosso fisso.

3.8 Context help popovers

Icona ⓘ accanto ai concetti complessi.

## 4. Async UX Patterns

### 4.1 Perché tutto async

- Per evitare blocchi, timeouts e rotture lato agent/server.  
- Per allinearsi al modello job-based descritto nel PDL principale.

### 4.2 Pattern "Launch async job"

Ogni action “pesante”:

1. Valida input.  
2. Dispatcha un job in queue.  
3. Mostra un toast di conferma (“Job queued”).  
4. Aggiorna la UI tramite polling leggero o refresh esplicito.

### 4.3 Stati job

Stati mostrati in UI (coerenti con l’enum AgentJob):

- `queued`  
- `running`  
- `success` / `completed`  
- `failed`  
- `partial` / `partial_success`  
- `timeout`  
- `expired`

### 4.4 Job Center integration

Ogni job ha:

- riga nella tabella Job Center;  
- slide-over che mostra:
  - payload;  
  - output/result;  
  - errors;  
  - result_metadata.

### 4.5 Polling ottimizzato

Polling solo nelle viste:

- Job Center;  
- Device → tab Discovery;  
- Wizard finale (se necessario per mostrare esito di step async).

### 4.6 Azioni sensibili

Richiedono conferma esplicita:

- Delete device;  
- Reset agent token;  
- Force merge.

### 4.7 Cancellazione job

Consentita solo se:

- job in stato `queued`;  
- job `running` da troppo tempo (timeout) e la cancellazione è supportata a livello dominio.

## 5. Error UX & System Status

### 5.1 Tipi di errori

Categoria | Esempi
--------- | -------
Network   | timeout, `UNREACHABLE`
Protocol  | `SCHEMA_MISMATCH`, `INVALID_RESPONSE_FORMAT`
Agent     | outdated, config invalid, min version non rispettata
REST      | access denied, API unavailable, version too low
SNMP      | timeout, unsupported, invalid OID
Merge     | conflict, locked field
System    | worker offline, scheduler non running, queue saturata

### 5.2 Badge di stato device

- green: healthy  
- yellow: partial  
- red: error  
- gray: unknown

### 5.3 Stato agent

- online (heartbeat < 2m)  
- degraded (2–10m)  
- offline (>10m)  
- outdated (versione agent < min_agent_version)

### 5.4 System health

Mostra:

- worker heartbeat  
- scheduler heartbeat  
- queue backlog  
- agent online count

### 5.5 Error overlay

Solo per problemi critici globali:

- DB down  
- Redis down  
- Worker morto

### 5.6 UX dei conflitti

Diff viewer con, ad esempio:

```text
Old:    "Mikrotik-01"
New:    "Mikrotik Main"
Source: REST
```

Pulsanti:

- Accept  
- Reject  
- Always keep REST  
- Always keep manual

## 6. UI per Moduli Core

### 6.1 Dashboard

Cosa mostra:

- KPI: devices, IPs, VLANs  
- Agent status  
- Recent discovery events  
- System Health summary

### 6.2 Device Resource

La `DeviceResource` Filament è la vista canonica per ogni device DCIM. La UI è organizzata in tab obbligatori, mappati su pagine/sezioni Filament, con la seguente struttura:

```text
Device
 ├── Overview        (Infolist read‑only)
 ├── Discovery       (Infolist + JSON viewer)
 ├── Networking      (Form + RelationManager Ports)
 └── SNMP Settings   (Form strutturato)
```

#### 6.2.1 Tab “Overview” (Infolist read‑only)

- Implementata come Infolist Filament, non come form (solo lettura).  
- Contenuti minimi:
  - `hostname` (device.name), `management_ip` (campo autorevole in modello Device).  
  - Vendor / modello / serial (derivati da `device_result.device` o merge policy).  
  - Uptime, OS version, piattaforma (`mikrotik`, `unifi`, `generic`).  
  - Badges di stato:
    - Health (Healthy / Partial / Stale / Unknown).  
    - Auth status (valid / invalid / unreachable).  
    - Ultimo canale visto (REST / SNMP / subnet scan).  
- Mostra anche un riepilogo discovery:
  - ultimo job completato, tipo job, timestamp, esito (badge).  
  - link rapido al Job Center per quel device.

#### 6.2.2 Tab “Discovery”

- Sezione centrale per la pipeline agent → server per il singolo device.  
- Blocchi principali:
  - **Last scan**: timestamp ultima discovery completa (REST/SNMP), tipo job (`check_connectivity`, `discovery_deep`, `snmp_discovery`), agent usato.  
  - **Health summary**: stato aggregato (REST/SNMP/subnet), con indicatori `ok/partial/error`.  
  - **REST status**:
    - ultimo esito `check_connectivity` (HTTP/REST Mikrotik).  
    - errori recenti (`AUTH_ERROR`, `NET_TIMEOUT`, ecc.) da `device_result.errors[]` o dal job.  
  - **SNMP status**:
    - ultimo esito `snmp_discovery` (se abilitato).  
    - errori recenti SNMP (timeout, community invalid, `SCHEMA_MISMATCH`, ecc.).  
  - **Device Result viewer**:
    - JSON viewer (pretty‑print, font monospace) del `device_result` più recente associato a quel device.  
    - Deve evidenziare almeno:
      - `schema_version`.  
      - chiavi principali `device`, `interfaces.items`, `vlans.items`, `arp.items`, `dhcp_leases.items`, `neighbors.items`, `bridge_hosts.items`, eventuale sezione `snmp`.  
      - `errors[]` con codice, source, message.  
    - La viewer è read‑only; supporta collapse/expand per sezioni e copia in clipboard.  
  - **Actions (async)**:
    - “Check connectivity” → dispatch job `check_connectivity`.  
    - “Full discovery” → dispatch job `discovery_deep` (Mikrotik REST + subnet/ARP/DHCP, consolidato in `device_result`).  
    - “Test SNMP” → dispatch job `snmp_discovery` (solo se SNMP abilitato).

#### 6.2.3 Tab “Networking”

- Scopo: concentrare tutti gli aspetti di connettività e topologia L2/L3 del device.  
- Sezioni minime:
  - **Management IP & access**:
    - Campo `management_ip` (autorevole per discovery, vedi PDL principale §7.9).  
    - Eventuale alias per IP secondari o note operative.  
  - **Ports / Interfaces**:
    - RelationManager `PortsRelationManager` come tabella principale della sezione.  
    - Colonne minime:
      - `name`, `mac`, `status` (badge), `speed`, `duplex`.  
      - `vlan_mode`, `pvid`, `tagged_vlans`.  
      - `last_seen` (da discovery) e `discovered_by_scan_agent_id` (se disponibile).  
    - Slide‑over per dettagli porta (descrizione, note, link fisico).  
    - Azioni di collegamento/disconnessione link (già coperte nel PDL principale per DCIM).

#### 6.2.4 Tab “SNMP Settings”

- Implementa la UI SNMP minimale Fase 1 (vedi anche §6.8), ma strutturata come tab dedicato.  
- Sottosezioni:
  - **Connection**:
    - `Enable SNMP` (toggle, `snmp_settings.enabled`).  
    - `Host/IP`, `Port` (default 161), `Version` (`v1`/`v2c`).  
    - `Community` (password, masked, cifrata).  
    - `Timeout` (ms) e `Retries`.  
  - **Port naming priority**:
    - Repeater riordinabile per definire l’ordine fra `ifAlias`, `ifDescr`, `ifName`.  
  - **Custom OIDs (globali)**:
    - Repeater per definire OID globali a livello device (non per‑port) con:
      - `oid`, `field_name`, `expected_type`.  
  - **Azioni**:
    - Bottone “Test SNMP” (stessa azione del tab Discovery), che in Fase 1 è job async e non blocca la UI.

### 6.3 ScanAgent

La `ScanAgentResource` espone dettagli e gestione degli agent remoti.

- **Tab principale (Details)**:
  - Token management (one‑time reveal PAT, gestione rotate/revoke).  
  - Campi base agent (nome, customer, stato `active`).  
  - `last_seen` con badge di stato (online / degraded / offline / outdated).  
  - Config preview (estratto da `ScanAgent.settings` e da `/api/v1/agent/config`).
- **Tab “Logs” (AgentLogsRelationManager)**:
  - Tab dedicato che mostra i log ricevuti da `/api/v1/agent/logs`.  
  - Filtri:
    - `level` (DEBUG/INFO/WARNING/ERROR/CRITICAL).  
    - `date range` (da/a, basato su `created_at`).  
    - testo libero su `message` e, se possibile, su `context` (full‑text semplice).  
  - Colonne:
    - timestamp, level (badge), message, agente/tenant (se non impliciti).  
  - Azioni:
    - “View JSON context” → slide‑over/modal che mostra il campo `context` formattato JSON (read‑only).  
  - La tab Logs deve aggiornare implicitamente `last_seen` quando arrivano nuovi log (la logica server aggiorna `scan_agents.last_seen` su ogni ingest).
- **Tab “Jobs” (AgentJobsRelationManager)**:
  - Storico job associati all’agent (riuso di Job Center filtrato per agent).  
  - Link rapidi a Device e Job Center globale.

### 6.4 IPAM

- VLAN list con filtro per location.  
- Subnet tree (group per VLAN).  
- IP Pool con stato IP:
  - free / used / conflicted / locked.  
- IP Editing:
  - campi editabili: comment, type (static/dhcp static), category (printer/pc/custom).
- **Subnet view (dettaglio)**:
  - Deve mostrare esplicitamente:
    - `last_scan` (timestamp ultima scansione subnet).  
    - `last_discovery` (timestamp ultima discovery/ingest associata).  
  - Filtri aggiuntivi:
    - filtro per `scan_agent` associato alla subnet.  
  - Sezione “Discovery status”:
    - stato corrente discovery (never run / scheduled / running / completed / partial / failed).  
    - indicatori per partial dataset (es. ARP troncato) coerenti con `device_result.arp.partial`.  
    - link alle ultime discovery per quella subnet (Job Center filtrato).
- **Ports (DCIM) allineate a discovery**:
  - La Ports UI (sia in Device che in Rack) deve esporre:
    - `last_seen` (da discovery) per ogni porta.  
    - `discovered_by_scan_agent_id` (agent responsabile dell’ultimo aggiornamento).  
  - Questi campi sono mostrati come colonne toggleable ma disponibili by default nelle viste tecniche.

### 6.5 Job Center

- Tabella `agent_jobs` come vista centrale di tutti i job agent.  
- Filtri:
  - `type`, `status`, `tenant` (già previsti).  
  - `device` (lookup su hostname/device_id).  
  - `agent` (ScanAgent).  
  - `scope` (`device|agent|system`).  
- Slide‑over (dettaglio job):
  - mostra `payload`, `result`, `result_metadata`, `error_message` (read‑only).  
  - include un **DeviceResult viewer**:
    - se `result.device_result` è presente, visualizza la struttura `device_result` prettamente come nel tab Device → Discovery.  
    - rende immediatamente visibili `schema_version`, `errors[]` e dimensione dei dataset (counts per sezione).  
  - mostra eventuali **conflict events** generati dal merge:
    - elenco di `DiscoveryEvent` correlati a quel job (es. MAC_MISMATCH, ORPHAN_IP) dove `agent_job_id = job.id` (ordinati `created_at ASC`);
    - ogni evento mostra badge di `severity`, descrizione sintetica e link alla risorsa correlata (device, ip, port, subnet);
    - link diretto alla pagina di merge/approvazione degli eventi (Fase 1: read-only / log only).
- Azioni Fase 1:
  - view details (nessuna modifica diretta allo stato del job).  
  - export CSV/XLSX tramite ExportAction/ExportBulkAction (per audit esterno).  
- Azioni Fase 2 (non ancora implementate, ma previste a livello PDL):
  - rerun job (solo per job idempotenti, es. discovery).  
  - jump to device / jump to agent (shortcut verso le rispettive Resource).  

### 6.6 DiscoveryEvent

- Filtri per type/category.  
- Diff viewer.  
- Batch actions per resolve conflict.
  
Dettagli UX (Fase 1):

- Gli eventi mostrati nel dettaglio job sono i `DiscoveryEvent` che hanno `agent_job_id = job.id`.
- L’elenco è ordinato `created_at ASC` per ricostruire la sequenza di rilevamento.
- In Fase 1 i DiscoveryEvent sono read-only: la UI li mostra come log/diagnostica (no approval workflow ancora).
- Ogni evento mostra: `badge severity`, `short description` e `resource links` (device, ip, port, subnet). 

### 6.7 Rack View

- Schema a griglia.  
- Devices posizionati in verticale.  
- Click device → slide-over.  
- Export rack layout.

### 6.8 SNMP Minimal UI

La UI minima Fase 1 per SNMP, allineata al PDL principale, comprende:

- abilitazione SNMP per device (`enabled`);  
- host/IP, port, version (v1/v2c);  
- community masked (salvata in forma cifrata);  
- timeout (ms) e retries;  
- port name priority configurabile tramite lista riordinabile (ifAlias / ifDescr / ifName);  
- custom global OIDs, definiti a livello device (non per-port) tramite repeater;  
- azione “Test SNMP” (job async), la cui implementazione effettiva è prevista nella Milestone C (SNMP Fase 1 pipeline).

### 6.9 System Health

Cards:

- Worker status  
- Scheduler status  
- Queue backlog  
- Agent online/offline  
  - uses `agent_offline_threshold_seconds` (SystemSettings, default 120s) to classify online/degraded/offline
- Bottone “Run Health Check Now”.

Queue backlog:
- Show backlog count per tenant (DB queue) and apply thresholds from `DiscoverySettings`:
  - if > `queue_backlog_warning_threshold` → show WARNING
  - if > `queue_backlog_critical_threshold` → show CRITICAL

Health Check action:
- 'Run Health Check Now' calls server HealthCheckService → presents a summary with worker/scheduler last_seen, agent online counts, backlog status, and any recent critical DiscoveryEvents.

## 7. Settings UX

Suddivisione:

- General settings:
  - app name;  
  - locale.  
- Discovery:
  - max records;  
  - timeout override.  
- Notifications:
  - webhook configuration (Fase 1): `webhook_enabled` (toggle), `webhook_url` (input), `notify_on` toggles (agent_offline/agent_online/discovery_error/discovery_conflict/backup_completed/backup_failed), `rate_limit_seconds` (int, default 300);
  - Telegram (Fase 2): `telegram_enabled`, `telegram_bot_token`, `telegram_chat_id` (UI provided but driver not active until Fase 2);
  - Webhook UX: validate URL + sample test send button; show last 10 webhook failures in a diagnostic panel (status codes / payload preview).
- Security:
  - min agent version;  
  - tls verify;  
  - token rotation.

## 8. Wizard

Step Fase 1:

- Preflight  
- DB migrate  
- Admin user  
- Customer + location  
- App URL  
- Queue driver validation  
- Final check

Fase 2:

- email test  
- telegram test  
- redis test

Lo stato viene salvato in `system_state` (vedi PDL principale).

## 9. Responsiveness

- Sidebar collapsible.  
- Tabelle con colonne nascoste su mobile.  
- Slide-over full-screen su mobile.  
- Cards impilate verticalmente.

## 10. Accessibility

- WCAG AA contrast.  
- Focus outlines chiari.  
- Icone con `aria-label`.  
- Annunci live per job changes.

## 11. UI Testing

### 11.1 Tipologie

- Pest Feature tests.  
- Filament tests.  
- Dusk UI tests.  
- Snapshot testing.

### 11.2 Regole per nuovi test

- Ogni Resource deve avere almeno 1 test di apertura pagina.  
- Ogni action async deve avere test della queue (job creato).  
- Ogni wizard step deve avere almeno un test (idealmente Dusk).

### 11.3 Script locale

- Script ufficiali:
  - `./dev/test` — esegue l'intera suite (lint + Pest + Dusk) in ambiente Docker di test.  
  - `./dev/test-fast` — solo test rapidi (unit + feature base).  
  - `./dev/test-ui` — test Filament + snapshot.  
  - `./dev/test-agent` — test per agent-protocol + compatibilità schema `device_result`.  
  - `./dev/test-ci` — entrypoint usato in CI.

Ogni script:

- usa l'ambiente Docker di test;  
- resetta i database di test prima del run;  
- esegue le suite in ordine chiaro (lint → unit/integration → UI/snapshot).

## 12. Anti-patterns

- ❌ Modali nested.  
- ❌ Polling ogni <10s.  
- ❌ UI che dipendono implicitamente da session state per logica di business.  
- ❌ Field senza help text quando il significato non è ovvio.  
- ❌ Table senza pagination.  
- ❌ Valori nascosti che cambiano senza feedback.

## 13. Appendici

### 13.1 Icon mapping

(Liste complete per Filament)

### 13.2 Vendor color palette

### 13.3 Template notifiche (Fase 2)

### 13.4 Glossario

### 13.5 Varianti widescreen
