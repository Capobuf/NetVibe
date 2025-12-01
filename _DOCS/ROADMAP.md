# 🗺️ NETVIBE ROADMAP (V2.0)

## 📦 EPIC 1: GENESIS (Identity & Infrastructure)
* **Goal:** Trasformare il progetto in "NetVibe" e adottare un'infrastruttura container singola ad alte prestazioni.
    * **Task 1.1: Rebranding — DONE (2025-12-01):**
        * Rinomina `APP_NAME`, `composer.json` e Namespace principali in **NetVibe** (es. composer name → `netvibe/ipam`).
        * Pulizia riferimenti `phpIPAM` e `Modern IPAM` nel codice, commenti e configurazione interna. Container slugs aggiornati a `netvibe-*`.
    * **Task 1.2:** **FrankenPHP/Octane Migration (CRITICAL):**
        * **Dockerfile:** Sostituzione immagine con `dunglas/frankenphp:php8.4`.
        * **Docker Compose:** Eliminazione servizio `webserver` (Nginx). Il servizio `app` espone direttamente la porta 80/443.
        * **Octane:** Installazione `laravel/octane` per gestire il server application (lifecycle, worker bootstrap, static caches).
    * **Task 1.3: Proxy Trust:** Configurazione `TrustProxies` per supporto HTTPS dietro Nginx Proxy Manager/Cloudflare.
    * **Task 1.4: Structured Logging:** Configurazione Monolog per output JSON su `stdout` (Docker Native Logging).

## 🔐 EPIC 2: ACCESS & MULTI-TENANCY
* **Goal:** Implementare una "Soft Multi-tenancy" sicura e trasparente.
    * **Task 2.1: Tenant Scope Infrastructure:**
        * **Selector:** Implementare un selettore globale di Cliente nella Topbar (Filament Hook).
        * **Session:** Salvare `current_tenant_id` in sessione.
        * **Global Scope:** Applicare `TenantScope` a tutti i modelli principali (Subnet, Device, Rack).
        * **Logic:** Se `current_tenant_id` è NULL -> Mostra TUTTO (God Mode).
    * **Task 2.2: Advanced Auth:**
        * **MFA:** Abilitare Filament Two-Factor Authentication (TOTP).
        * **Emergency:** Implementare comando CLI `netvibe:reset-mfa {email}` per recovery.
        * **Password Reset:** Implementare flow standard Laravel Fortify/UI.

## 🔌 EPIC 3: PHYSICAL LAYER (The Cable Manager)
* **Goal:** Gestione fisica dei collegamenti (Layer 1) sfruttando i dati esistenti.
    * **Task 3.1: Schema "Ports & Links":**
        * **Table `ports`:** `device_id`, `name` (es. "Gi1/0/1"), `type` (RJ45/SFP), `mac_address`.
        * **Table `links`:** `port_a_id`, `port_b_id`, `cable_type_id`, `color`, `length`.
        * **Table `cable_types`:** Catalogo cavi (Cat6, Fibra OM4, DAC) con colori HEX associati.
    * **Task 3.2: Port Generation Logic:**
        * **Action:** `GenerateDevicePortsAction`. Quando si crea un Device, leggere i campi esistenti `ethernet_ports` e `sfp_ports` dal `DeviceModel` associato.
        * **Logic:** Generare automaticamente i record nella tabella `ports` (es. 24 porte Eth + 4 SFP) con naming standard.
    * **Task 3.3: Rack View 2.0 (Interactive):**
        * **Refactor:** Aggiornare `RackVisual` per renderizzare una Griglia CSS delle porte (basata sui record `ports`).
        * **State:** Visualizzazione stato porta (Verde=Link, Grigio=Vuoto).
    * **Task 3.4: Cable Manager (Livewire Component):**
        * **UI:** Interfaccia "Click-to-Connect": seleziona Porta A -> Porta B -> Modale tipo Cavo -> Salva.
    * **Task 3.5: Print & Documentation:**
        * **PDF:** Generazione etichette cavi (Brother TZe style) con QR Code.
        * **Connection Table:** Vista tabellare filtrabile di tutte le patch.

## 🧪 EPIC 4: QUALITY & AI GUARDRAILS
* **Goal:** Prevenire il degrado del codice.
    * **Task 4.1: Pest Architecture Testing:**
        * Regola: "Controllers must not use Eloquent Models directly".
        * Regola: "No `dd`, `dump`, `ray` in production code".
        * Regola: "All source files must have `strict_types=1`".
    * **Task 4.2:** Refactoring Test Suite esistente in Pest PHP nativo.

## 🛠️ EPIC 5: UX REVOLUTION
* **Goal:** Migliorare la navigazione e la leggibilità.
    * **Task 5.1: Navigation Overhaul:**
        * Abbandono dei Cluster per Navigation Groups collassabili ("IPAM Core", "Infrastructure", "Settings").
    * **Task 5.2: Subnet View 2.0:**
        * Layout a 2 colonne: Albero Navigazione (Sinistra) + Dettagli/Tabella (Destra).
        * Tabella IP "Power User" (refinements).
    * **Task 5.3: Global Search:**
        * Global Search (Spotlight style) su IP, MAC, Hostname e Device.

# Acceptance criteria

---

## Implementation Notes & Priorities

- Prioritize a safe, incremental rollout: begin with `Task 1.2` (FrankenPHP image build verification + CI smoke tests), then `Task 1.5` DB tenancy fix, `Task 2.1` TenantScope, `Task 3` physical layer as a feature flagged rollout.
- Avoid breaking production: container name/image renames must be coordinated with DevOps; migrations for `ports` and `links` should include idempotent/planned backfills and an opt-in run.
- Testing: add acceptance E2E harness for RackVisual / Cable Manager to catch UI regressions early.
- Security: All restore operations must run under elevated admin roles and be rate-limited with audit logging.

