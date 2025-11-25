# IMPLEMENTATION REPORT

Data: 2025-11-25

Scopo: verificare l'aderenza dell'implementazione corrente (migrations + Filament resources) rispetto alla mappatura funzionale e UI della versione phpIPAM originale (`PHPIPAM_UI_MAP.md`).

Legenda: ✅ MATCH | ⚠️ PARTIAL | ❌ MISSING

---

## 1) Struttura del Menu

Sintesi dell'albero atteso (da `PHPIPAM_UI_MAP.md`):
- Dashboard
- Tools (Scansione, Discovery, IP utilities, etc.)
- Administration (Scan agents, Users, Settings)
- Subnets / Sections (albero sinistro con navigazione gerarchica)
- Devices, VLANs, VRFs, Customers

Stato attuale (Filament `navigationGroup`):
- `IP Management`: Subnets, Sections, Ip Addresses (implementazione presente)
- `Network Infrastructure`: Devices, Vlans, Vrfs (implementazione presente)
- `Administration`: ScanAgents, Customers, Locations (implementazione presente)

Valutazione
- Dashboard — ✅ MATCH: `Dashboard` presente come pagina Filament.
- Tools — ⚠️ PARTIAL: la categoria "Tools" dell'UI originale include molte utilities (discovery UIs, scan lists, IP calculators). Nella versione Filament esistono risorse e servizi per scansioni (ScanAgents, FpingWrapper) ma mancano molte voci strumenti nella barra principale e non c'è un gruppo Filament espresso "Tools" con tutte le utilità mappate.
- Administration — ✅ MATCH (parziale): le voci principali (ScanAgents, Customers, Locations) sono presenti e raggruppate in `Administration`.
- Subnets/Sections Tree — ⚠️ PARTIAL: le risorse `Subnets` e `Sections` sono presenti, ma la UI è una lista/tabella Filament (con `master_subnet_id` disponibile) — manca una visualizzazione ad albero completa identica all'originale.

Raggruppamenti errati / voci mancanti rilevate
- Mancano voci strumentali specifiche (es. interfaccia "Scan results" o "Scanned networks" come item singolo nella sidebar) — ❌ MISSING (Tools main menu).

---

## 2) Dati e Colonne (Deep dive)

NOTE: confronto tolto punto‑per‑punto tra colonne attese da phpIPAM e implementazione in`database/migrations/*` e `app/Filament/Resources/Subnets` + `IpAddresses`.

### A) Subnets
Riepilogo colonne attese (esempi dall'UI/analysis): VLAN, Master Subnet, % Usage, Requests, Scan settings, VRF, Description, Section, Customer, Location.

Implementazione (evidenze):
- `database/migrations/...create_subnets_table.php` include: `subnet`, `mask`, `section_id`, `master_subnet_id`, `vlan_id`, `vrf_id`, `scan_agent_id`, `customer_id`, `location_id`, `allow_requests`, `is_folder`, `is_pool`, `is_full`, `ping_subnet`, `discover_subnet` e timestamp (vedi migration excerpt).
- `app/Filament/Resources/Subnets/Tables/SubnetsTable.php` e `SubnetForm.php` espongono la maggior parte di questi campi nelle colonne e nei form.

Valutazione colonne Subnets
- VLAN — ✅ MATCH (campo `vlan_id` presente e visualizzato).
- Master Subnet (parent) — ✅ MATCH (`master_subnet_id` presente e obbligatorio / default 0).
- % Usage (percentuale di utilizzo) — ❌ MISSING: non ho trovato un campo specifico in migrations/resources che memorizzi o calcoli direttamente la percentuale di utilizzo delle sottoreti (potrebbe essere calcolabile a runtime ma non esposto come colonna o campo calcolato nelle table views).
- Requests (richieste di indirizzi) — ⚠️ PARTIAL: esiste `allow_requests` (toggle) per abilitarne l'uso, ma non ho trovato una tabella `requests` o workflow di gestione delle richieste nel Filament UI (la presenza del flag non equivale ad un sistema completo di richieste).
- Scan settings / Scan agent reference — ✅ MATCH: `scan_agent_id`, `ping_subnet`, `discover_subnet` sono presenti.

Esempi estratti:
 - Migration: `$table->unsignedBigInteger('master_subnet_id')->default(0)->comment('Parent subnet for nested subnets');`
 - Table: `IconColumn::make('discover_subnet')` (mostra se discovery abilitato)

Conclusione Subnets: parzialmente completo — schema DB e Filament espongono la maggior parte dei metadati richiesti, ma mancano la metrica di %Usage persistita e il workflow per Requests.

### B) IP Addresses
Campi attesi in phpIPAM: IP (dotted/decimal), MAC, Hostname, Note, Switch Port, Owner, State, Last seen, PTR/statuso DNS, Customer, Location.

Implementazione (evidenze):
- `database/migrations/...create_ip_addresses_table.php` espone `ip_addr` (decimal), `mac`, `hostname`, `description`, `owner`, `switch_id`, `port`, `note`, `last_seen`, `ptr`, `ptr_ignore`, `exclude_ping`, `customer_id`, `location_id`.
- `app/Filament/Resources/IpAddresses/Tables/IpAddressesTable.php` mostra colonne ip_addr (formattato via `IpConversionService::fromDecimal`), hostname, description, mac, owner, state (badge), subnet.description, location.name, is_gateway, port, last_seen, created_at.

Valutazione IP Addresses
- MAC, Hostname, Note, Switch Port — ✅ MATCH: tutti presenti come campi e colonne toggleable nelle risorse.
- PTR / DNS handling — ⚠️ PARTIAL: esistono campi `ptr` e `ptr_ignore` nelle migrations; tuttavia il trattamento automatico/visualizzazione dei record PTR non è completamente evidente nel UI corrente.
- Stato IP (badge) — ✅ MATCH: stato implementato con badge colors e mapping.

Esempio estratti:
- Migration: `$table->unique(['ip_addr', 'subnet_id'], 'sid_ip_unique');`
- Table: `->formatStateUsing(fn ($state) => app(\App\Services\IpConversionService::class)->fromDecimal($state))` per ip formatting.

### C) Logica Stati (IP & Subnet)
- La migrazione di `ip_addresses` e `subnets` include un `state` con commenti che mappano numeri a stati (es. `1=Offline,2=Used,3=Reserved,4=DHCP` o simile). Le risorse mostrano gli stati con badge e colori corrispondenti.
- Valutazione: ✅ MATCH — gli stati principali sono coperti e la UI visualizza badge colorati per lo stato.

---

## 3) Funzionalità Core

### 3.1 Scansione (workflow originale: Select Agent → Scan → Review)
- Statuo attuale: ⚠️ PARTIAL
  - Cosa c'è: `app/Services/Scanners/FpingWrapper.php` implementa la logica di scan; esiste il modello e migration `ScanAgent` (tipo `direct|api|mysql` e token), Filament Resource `ScanAgent` per CRUD; subnet ha flag `discover_subnet` e riferimento `scan_agent_id`.
  - Cosa manca: Non è stato trovato un "Scan subnet" Filament action / page che permetta la selezione di agent + esecuzione scan + review modal/approve import. Quindi l'infrastruttura back-end è presente ma mancano gli endpoint/azioni UI integrate per l'esecuzione e revisione interattiva dei risultati.

### 3.2 Gestione Gerarchica (Nested Subnets)
- Statuo attuale: ⚠️ PARTIAL
  - Cosa c'è: DB supporta `master_subnet_id` e il Model include relazioni `parentSubnet()/childSubnets()`; Filament fields espongono `master_subnet_id` in form e tabella.
  - Cosa manca: Non è presente una visualizzazione ad albero nativa (tree) nell'UI Filament; l'usabilità per gestire / navigare gerarchie è quindi semplificata rispetto all'UI originale che offre un vero tree view.

### 3.3 Altre funzionalità core rilevanti
- Import/Export, Request worklow, Scan results review modal: ❌ MISSING o ⚠️ PARTIAL (toggle flags present ma processi di revisione/integrazione non presenti o non esposti nella UI).

---

## Riepilogo valutazione generale
- Implementazione complessiva: Ampia copertura del model/data layer e risorse Filament per la maggior parte delle entità chiave (Subnets, IPs, Devices, Vlans, ScanAgents). Tuttavia l'integrazione operativa (workflow di scansione, visualizzazioni ad albero, funzionalità avanzate come %Usage o request lifecycle) è parzialmente presente o mancante.

Percentuale-sintetica (stima qualitativa): ~70% funzionalità core implementate (data model + risorse), ~30% di UX/workflow e strumenti mancanti da ricostruire per rispecchiare l'esperienza phpIPAM completa.

---

## 3 Priorità critiche suggerite per il prossimo sprint
1. (CRITICO) Scan workflow completo — Implementare pagine/azioni Filament per avviare uno scan dalla UI (Select Agent → Run → Store/Review results) e una modal di review/merge per scoperte automatiche (MISSING). Questo disinnesca la frattura tra backend (FpingWrapper) e l'esperienza utente.
2. (CRITICO) Tree view per Subnets / Sections — Sostituire il campo numerico `master_subnet_id` con una vista ad albero ricorsiva per semplificare navigazione e gestione delle sottoreti nidificate (PARTIAL → MATCH con work).
3. (ALTA) %Usage e Requests workflow — Calcolare e visualizzare l'utilizzo percentuale delle sottoreti e implementare la gestione delle richieste (approvazione/riuso) piuttosto che solo il flag `allow_requests` (MISSING / PARTIAL).

---

## Anteprima file e riferimenti (estratti utili)
- Subnet migration (excerpt): `$table->unsignedBigInteger('master_subnet_id')->default(0)->comment('Parent subnet for nested subnets');`
- Ip address uniqueness: `$table->unique(['ip_addr', 'subnet_id'], 'sid_ip_unique');`
- Scan service snippet: `app/Services/Scanners/FpingWrapper.php` — `public function scanSubnet(string $cidr, int $timeout = 1000): array { ... }`

---

Se desideri, posso: (A) generare ticket/GitHub issues per le 3 priorità, (B) implementare uno dei tre task prioritari (parto dal scan UI o dal tree view), o (C) aggiungere un piano tecnico dettagliato per ciascuna priorità.
