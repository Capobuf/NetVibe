Piano di Lavoro (PDL) — derivato dal codice

Panoramica
- Fonte unica: codice + KB esistente; nessuna spec esterna.
- Target: MSP multi-tenant (soft tenant con risorse condivisibili), massimo ~50 agent remoti.
- Ambito prodotto: ibrido phpIPAM/NetBox, solo lettura/observability (no config-push sui device), integra dati da agent e input operatore per una source of truth.
- Stack baseline: Laravel 12 (ultima stabile), Filament v4 (ultima stabile), immagine ServerSideUp php-fpm/nginx come base Docker; deve girare primariamente in Docker ma anche su stack tipo CloudPanel con accesso internet disponibile.
- Scala stimata: subnet prevalentemente /24 con alcune /22-/21; supporto device Mikrotik al 100% (altri vendor fuori scope iniziale).
- Proxy/TLS: app espone porta interna 8080 (documentata); TLS terminato dal reverse proxy (es. Nginx Proxy Manager) con verify on by default.

Fonti base
- Architettura/infra/agent → [[01_INFRASTRUCTURE.md]]
- Schema/tenancy → [[02_DATABASE_SCHEMA.md]]
- Logica IPAM → [[03_CORE_LOGIC_IPAM.md]]
- Logica DCIM/device → [[04_CORE_LOGIC_DCIM.md]]
- UX/Filament → [[05_UI_UX_FILAMENT.md]]
- Observability/test → [[06_OBSERVABILITY_AND_TESTS.md]]
- Debito/rischi → [[99_DEBT_AND_INCONSISTENCIES.md]]

Priorità (sequenza)
1) Sicurezza e hardening
   - Abilitare/verificare SSL/TLS per client HTTP (agent+server), verify on by default.
   - Chiudere `_debug/*`, applicare canonical host anche per API se richiesto.
   - Loop agent: backoff/rate control e gestione token.
   - Garantire read-only verso i device (niente push config), audit dei dati ingestiti.
2) Schema e coerenza dati
   - Decisione su `sections` (migration vs rimozione modello/seeder/UI).
   - Ripulire VRF/L2Domain residui; uniqueness VLAN per customer; storage `tagged_vlans` normalizzato.
   - Tenancy: policy esplicite per API/CLI quando `tenant_id` manca; obbligo `customer_id` per agent jobs/discovery.
3) Discovery/Agent pipeline
   - Backoff globale, gestione coerente NET_TIMEOUT/AUTH_ERROR, metriche di polling.
   - Contratti payload Mikrotik REST + legacy fping; completare inventory ingest (OS/hw/ports).
   - DiscoveryEvent approval tenant-safe con insert IP atomica/locked.
4) DCIM/IPAM UX e dati
   - Unificare DeviceResource (rimuovere duplicato infra o documentarne l’uso), chiarire binding scan agent/credential profile.
   - Ports/VLAN UI: normalizzare tagged_vlans string/array, esporre stato link/agent.
   - Decisione finale su sections; migliorare gateway/threshold display.
5) Observability e test
   - Coprire IngestMikrotikInventoryAction, DiscoveryEvent approval, tenancy edge cases.
   - Metriche/logging di discovery success/fail e tuning agent alert.

Deliverable attesi
- Patch set per priorità 1–3 con test aggiornati.
- Decision log per sections e tenancy enforcement.
- KB aggiornata (`docs/*`) dopo ogni milestone per tenere il PDL aderente al codice.

Aspetti da dettagliare (next)
- Deployment: profili Docker vs CloudPanel (env, HTTPS/terminate, storage persistente, healthcheck).
- Error handling: mapping chiaro errori agent (AUTH_ERROR, NET_TIMEOUT, http_error) -> stati device/auth_status/discovery_event.
- Ottimizzazione: cache dei payload agent (Guzzle timeout, retry), riduzione round-trip discovery, batching ingest per subnet /24 e /22-/21.
- UI/UX: flussi per commenti/operator input sui dati importati, visibilità cross-tenant per risorse condivise, navigazione top-bar Filament.
- Test plan: coverage minima per ingest Mikrotik, tenancy, UI Filament critiche (Subnet/Device/ScanAgent), smoke agent loop.

Sezioni operative (da completare)

1) Agent design e protocolli
- Binding: ogni agent è legato a un cliente (no agent globali); configurazione minimale via env solo `SERVER_URL` + `AGENT_API_TOKEN` (API key). Tutte le altre impostazioni (polling, timeout, TLS verify toggle se proxy non gestisce) sono spinte dal server e persistono lato agent (config file/cache).
- Payload standardizzato lato agent → server: definire schema “device result” con campi opzionali (resource, identity, interfaces, vlans, arp, dhcp). Il server gestisce campi mancanti per vendor futuri. Obiettivo: unico formato interno per parsing/merge.
- Stati operativi agent:
  - Disconnesso a lungo: last_seen scade → badge rosso, alert Telegram; job restano in pending/timeout.
  - Disabilitato da UI: il server non assegna job e rifiuta ingest; agent riceve 403 e si mette in idle (backoff lungo).
  - Crash/container bloccato: rely on healthcheck + alert; stateless salvo config persistita (montare volume per file config/cache token).
- Headless: nessuna UI sull’agent; log su STDERR con timestamp e supporto ASCII/emoji (coerente con log app). Formato: `[YYYY-MM-DD HH:MM:SS] [LEVEL] message`.
- Canali: solo HTTPS verso control plane; nessun canale ingress richiesto.

2) Logging e retention
- Agent log: timestamp, livello, ASCII/emoji ammessi; inviati anche a server via `/api/v1/agent/logs` con threshold configurabile. Persistenza locale opzionale (file rotato) solo per debug.
- App log: impostare log rotation (max size/retention) configurabile da UI (General/Discovery settings) per evitare laravel.log gigante. Default rotation size/time; opzione prune.
- Formati export log: CSV/JSON scaricabili per agent/app; no dump SQL.

3) Export/import dati e configurazioni
- Esportazioni per tabelle chiave (Subnets, IP, Devices, Ports, DiscoveryEvents, Config/Settings) in CSV/XLSX/PDF. PDF personalizzabile (logo, orientamento portrait/landscape). Se file > soglia (es. 4GB), usare export asincrono con link di download e compressione opzionale.
- Import batch (CSV/JSON) per clienti e risorse; validazione e anteprima.
- Backup configurazioni: esportare settings (general/discovery/logging) in JSON; import con merge controllato.
- Filament: usare azioni/bulk e code per export/import per non bloccare UI.

4) Snipe-IT integrazione (future)
- Obiettivo: mappare asset 1:1 con regole (es. match serial/MAC/hostname) configurabili in una sezione dedicata. Collegamenti click-to-open su device/asset.
- Richiede endpoint Snipe-IT base URL + token; opzionale e disattivabile.

5) DCIM/IPAM UX estesa
- Cataloghi: categorie device (Switch, Router, ecc.) configurabili, con definizione porte (numero e tipo) semplice; flag `shared` per uso cross-tenant.
- Rack: creare armadi con numero U, render grafico a tabella/infolist, interattivo (click su device/porta). Filament v4 nativo, niente view legacy.
- Porte: vista grafica/relazione per connessioni device A porta X → device B porta Y; tabella connessioni con azione di collegamento/disconnessione.
- Device Mikrotik: inserisci IP+username+password, dispatch job; entro 5-10s auto-popola campi (modello, serial, interfacce, VLAN, ARP, DHCP). Gestione errori: agent offline/no agent configurato → badge/alert e messaggio UI.
- Agent e location: ogni agent è associato a un cliente e, opzionalmente, a una location. Se location non è selezionata, l’agent copre tutte le sedi del cliente; se è selezionata, è limitato a quella sede. Supporto multi-sede via VPN: possibile deploy di un agent per location o uno unico cross-location.

6) Fonte di verità e conflitti (merge)
- Ruoli IP (gateway/DNS/altro) e prenotazioni “attese” (MAC/hostname/descrizione). Flag “locked/manual”: non sovrascrivere campi testo da ingest; sempre aggiornare last_seen/MAC se desiderato.
- In caso di mismatch: creare DiscoveryEvent con dettagli (MAC visto, lease statico sì/no, interfaccia, timestamp, agente). UI con diff e azioni: accetta (sovrascrive), ignora (mantiene manuale), mix (aggiorna solo alcuni campi).
- Regola default proposta: se locked → non sovrascrivere hostname/descrizione/ruolo; se non locked → dati router vincono per hostname/serial; sempre last_seen aggiornato.
- Correlazione dati: mappare MAC/IP alle porte/dispositivi dove sono stati visti (ARP/DHCP/neighbor) per evidenziare percorsi e rilevare possibili unmanaged switch (es. MAC visto su più porte/segmenti).
- UI conflitti: tabella conflitti con vista campo-per-campo (valore attuale vs in ingresso), scelta per campo, template di bulk (es. “accetta tutto dal remoto”, “ignora remoto”) personalizzabili.

7) Alerting e health
- Telegram webhook (bot token/chat id) per agent down, auth error, ingest failure. Notifiche Filament in-app.
- Agent health badge (LED) vicino al nome cliente (TenantSwitcher, lista clienti) basato su last_seen soglia configurabile.

8) Metriche/monitoring
- Base: dashboard Filament + log. Prometheus escluso (no /metrics); documentare esplicitamente che si usano solo log e dashboard interne.

9) Labeling, QR e connettività (DCIM)
- Generazione PDF etichette (Brother TZe) con layout selezionabile/personalizzabile (logo, portrait/landscape), contenente info connessione e QR.
- QR linka a pagina (configurabile pubblica/privata) che mostra connessione (device/porte stilizzati, campi selezionabili: nome, IP, categoria, ecc.). URL random; alla cancellazione della connessione: opzione per rimuovere subito, mantenere con messaggio “non tracciata” per X tempo, o riallocare su nuova connessione.
- Automazioni opzionali: aggiornare connessioni usando CDP/LLDP/neighbors quando disponibile, con conferma/auto-apply a scelta dell’utente.

10) Dashboard/alert (proposta iniziale)
- Indicatori default: agent online/offline, job falliti/timeout, conflitti IP/MAC per subnet/VLAN, IP pool quasi esauriti, export/import falliti.
- Notifiche Telegram configurabili per categorie: agent offline da X min, credenziali errate, nuovi conflitti su subnet/VLAN selezionate, errori ingest/export. Configurazione via UI con help text.

11) Log e retention
- Retention configurabile: default 7 giorni, rotazione a 200 MB (valori modificabili dall’utente). Log agent/app esportabili filtrando per cliente/agent o “server-only”. Formati CSV/JSON.
- Download log filtrati (cliente/agent) e pacchetto separato per log server.

12) Location e agent
- Richiedere sempre una location: se il cliente ne ha una sola, creare “Default” e associarla. Agent: sempre legato a cliente e opzionalmente a location; server non assegna job fuori location. Job fuori scope devono essere rifiutati con messaggio chiaro.
- Nomenclatura job: includere device/cliente/location nel titolo per tracciabilità (es. `<cliente>/<location>/<device>-<type>`).

13) Workflow conflitti avanzato
- Tabella conflitti con vista campo-per-campo, scelta per campo. Template bulk: predefiniti “accetta remoto” e “mantieni locale”, più template custom illimitati. Nomenclatura da affinare per chiarezza (es. “prendi dati agent”, “conserva dati operatore”).
- Audit post-approvazione: opzionale, se effort limitato/nativo Filament (database notifications/log).

14) Export/Import e stampe operative
- Export prioritari: IP, Subnet, Device, Ports, DiscoveryEvents, Settings. Formati XLS/PDF per uso sul campo. Export asincrono con link di download (scadenza configurabile) e gestione spazio disco (errore/log se insufficiente).
- Stampe mappe collegamenti/rack: PDF con layout predefiniti e personalizzabili (logo, orientamento), utile per installatori (simile a ECCM). Se file grande, usare background job.

15) Template etichette/QR
- Set di layout pronti + possibilità di aggiungerne di nuovi. Valutare standard TIA o altri; struttura template in formato semplice (es. JSON + HTML) per estenderli. Privacy di default: pagina QR privata con token nel link; opzione utente per renderla pubblica. Gestire vecchie stampe: cambio privacy non invalida link esistenti salvo revoca manuale/alla scadenza.
