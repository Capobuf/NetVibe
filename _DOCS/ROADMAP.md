# 🗺️ NETVIBE ROADMAP (V2.0)

## 📦 EPIC 1: GENESIS (Identity & Optimization)
* **Goal:** Consolidare l'identità NetVibe e stabilizzare lo stack operativo: Nginx + PHP-FPM in container Docker.
    * **Task 1.1: Rebranding — DONE (2025-12-01):**
        * Rinomina `APP_NAME`, `composer.json` e Namespace principali in **NetVibe** (es. composer name → `netvibe/ipam`).
        * Pulizia riferimenti `phpIPAM` e `Modern IPAM` nel codice, commenti e configurazione interna. Container slugs aggiornati a `netvibe-*`.
    * **Task 1.2: Nginx Stack Consolidation:**
        * Ufficializzare l'immagine runtime in produzione come `serversideup/php:8.4-fpm-nginx`.
        * Rimuovere riferimenti attivi a Octane/FrankenPHP nella documentazione e `.env.example` a meno che non si pianifichi una migration coordinata.
        * Aggiornare CI/CD smoke tests per verificare il comportamento sotto Nginx + FPM.
    * **Task 1.3: Proxy Trust:** Configurazione `TrustProxies` per supporto HTTPS dietro Nginx Proxy Manager/Cloudflare.
    * **Task 1.4: Structured Logging:** Configurazione Monolog per output JSON su `stdout` (Docker Native Logging).
    * **Task 1.6 (Security Hardening):**
        * **TRUSTED_PROXIES:** Non usare il wildcard `*` in produzione. Documentare e preferire una lista di CIDR/mask (es. load-balancer, proxy public IP ranges) via `TRUSTED_PROXIES` env var.
        * **Rate Limiting:** Implementare rate limiting per gli endpoint agent (/api/v1/discovery/* e /api/v1/agent/*). Configurare soglie ragionevoli e policy per gestire burst e backoff.
        * **Docs & Alerts:** Documentare il rischio di header spoofing e aggiungere monitor/alerting per anomalie sugli endpoint di ingest (frequenza, fail rate).

## 🔐 EPIC 2: ACCESS & MULTI-TENANCY
* **Goal:** Implementare una "Soft Multi-tenancy" sicura e trasparente.
    * **Task 2.1: Tenant Scope Infrastructure (Hybrid):**
        * **Trait:** Implementare un `TenantAware` trait che applica automaticamente `where('customer_id', session('tenant_id'))` sui modelli che lo usano.
        * **Global Scope:** Applicare il trait ai modelli *tenant-owned* (Device, Rack, Subnet, Vlan) in modo che il filtro sia automatico nelle query Eloquent.
        * **Exclusion:** I modelli *globali* (DeviceModel, Settings, User) devono rimanere senza `TenantAware` — accesso cross-tenant è intenzionale.
        * **Override / Admin & Jobs:** Il trait deve permettere `withoutTenantScope()` (or `TenantAware::disable()` context) per job di background, task amministrativi e migrations backfill.
        * **UI Selector & session:** Conservare il selettore cliente in UI (Topbar) che imposta `session('tenant_id')`; quando `tenant_id` è NULL l'utente in scope admin vede tutte le risorse (god-mode) con chiaro visual indicator.
    * **Task 2.2: Advanced Auth:**
        * **MFA:** Abilitare Filament Two-Factor Authentication (TOTP).
        * **Emergency:** Implementare comando CLI `netvibe:reset-mfa {email}` per recovery.
        * **Password Reset:** Implementare flow standard Laravel Fortify/UI.

## 🔌 EPIC 3: PHYSICAL LAYER (Filament Native)
* **Goal:** Gestione del cablaggio utilizzando componenti nativi Filament (Forms, Actions, Infolists) invece di UI custom complesse.

    * **Task 3.1: Schema "Ports & Links" (Foundation):**
        * **Migration 1:** Tabella `cable_types` (id, name, color, category).
        * **Migration 2:** Tabella `ports` (device_id, name, type, status).
        * **Migration 3:** Tabella `links` (port_a_id, port_b_id, cable_type_id, color).

    * **Task 3.2: Port Automation:**
        * **Action:** `GenerateDevicePortsAction`. Alla creazione di un Device, genera N record nella tabella `ports` basandosi sui contatori `ethernet_ports` e `sfp_ports` del `DeviceModel`.
        * **Logic:** Naming sequenziale standard (1, 2, 3... e SFP1, SFP2...).

    * **Task 3.3: Rack View 2.0 (Infolist-Based):**
        * **Refactor:** Aggiornare `RackVisual` per usare una Blade View semplificata che renderizza le porte come una griglia di **Filament Actions** o **Badges**.
        * **Interaction:** Cliccando su una porta (Badge), si apre una `ManagePortAction` (Modale).

    * **Task 3.4: Connection Workflow (The Form):**
        * **UI:** Implementare l'azione di connessione come un Form Filament standard dentro la modale della porta.
        * **Fields:**
            1.  `Select::make('target_device_id')` (Searchable).
            2.  `Select::make('target_port_id')` (Filtrato per mostrare solo porte libere del device scelto).
            3.  `Select::make('cable_type_id')`.
        * **Logic:** Creazione record nella tabella `links`.

    * **Task 3.5: Connection Explorer:**
        * Una Risorsa Filament standard `LinkResource` per vedere, filtrare ed esportare tutte le connessioni in formato tabellare.

## 🧪 EPIC 4: QUALITY & AI GUARDRAILS
* **Goal:** Prevenire il degrado del codice.
    * **Task 4.1: Pest Conversion (Legacy Cleanup):**
        * Convertire i 42 file di test legacy di stile PHPUnit in Pest nativo.
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

