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

Tabs:

- Overview:
  - hostname, mgmt IP, vendor, serial, uptime, badges.  
- Interfaces:
  - tabella responsiva con name, MAC, oper/admin status, speed, azione slide-over per dettagli.  
- VLANs:
  - VLAN membership matrix.  
- Discovery:
  - Run discovery (actions);  
  - Last results;  
  - Partial data viewer.  
- SNMP Settings (minimal Fase 1):
  - enable SNMP;  
  - community masked;  
  - timeout;  
  - retries.  
- Logs / Jobs:
  - relation managers.

### 6.3 ScanAgent

- Token management (one-time reveal).  
- Last seen.  
- Config preview.  
- Logs.  
- Jobs.

### 6.4 IPAM

- VLAN list con filtro per location.  
- Subnet tree (group per VLAN).  
- IP Pool con stato IP:
  - free / used / conflicted / locked.  
- IP Editing:
  - campi editabili: comment, type (static/dhcp static), category (printer/pc/custom).

### 6.5 Job Center

- Tabella `agent_jobs`.  
- Filters (type/status/tenant).  
- Slide-over → payload/result/errors.  
- Azioni: rerun, jump to device.

### 6.6 DiscoveryEvent

- Filtri per type/category.  
- Diff viewer.  
- Batch actions per resolve conflict.

### 6.7 Rack View

- Schema a griglia.  
- Devices posizionati in verticale.  
- Click device → slide-over.  
- Export rack layout.

### 6.8 SNMP Minimal UI

- enable;  
- community;  
- timeout;  
- custom global OIDs;  
- test SNMP.

### 6.9 System Health

Cards:

- Worker status  
- Scheduler status  
- Queue backlog  
- Agent online/offline  
- Bottone “Run Health Check Now”.

## 7. Settings UX

Suddivisione:

- General settings:
  - app name;  
  - locale.  
- Discovery:
  - max records;  
  - timeout override.  
- Notifications:
  - webhook URL (se presente in Fase 1);  
  - telegram templates (Fase 2).  
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

- `./dev/test.sh`:
  - avvia docker-compose-test;  
  - prepara DB;  
  - lancia Pest;  
  - mostra report.

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
