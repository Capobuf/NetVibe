# 🗺️ NETVIBE ROADMAP (V2.0)

## 📦 EPIC 1: GENESIS (Identity & Optimization)
* **Goal:** Consolidare l'identità NetVibe e stabilizzare lo stack operativo: Nginx + PHP-FPM in container Docker.
    * **Task 1.1: Rebranding — DONE (2025-12-01):**
        * Rinomina `APP_NAME`, `composer.json` e Namespace principali in **NetVibe** (es. composer name → `netvibe/ipam`).
        * Pulizia riferimenti `phpIPAM` e `Modern IPAM` nel codice, commenti e configurazione interna. Container slugs aggiornati a `netvibe-*`.
    * **Task 1.2: Nginx Stack Consolidation:**
        * Status: PARTIAL — runtime image is set to Nginx + PHP‑FPM, but Octane/FrankenPHP docs remain in the repo.
            * Confirmed runtime image: `docker/php/Dockerfile` → FROM serversideup/php:8.4-fpm-nginx
            * Octane/FrankenPHP documentation still exists: `docs/docs-laravelv12/octane.md`, `docs/MASTER_ARCHITECTURE.md`
            * Recommended: coordinate doc pruning or annotate Octane as optional (no code removal performed here).
        * Ufficializzare l'immagine runtime in produzione come `serversideup/php:8.4-fpm-nginx`.
        * Rimuovere riferimenti attivi a Octane/FrankenPHP nella documentazione e `.env.example` a meno che non si pianifichi una migration coordinata.
        * Aggiornare CI/CD smoke tests per verificare il comportamento sotto Nginx + FPM.
    * **Task 1.3: Proxy Trust:** Configurazione `TrustProxies` per supporto HTTPS dietro Nginx Proxy Manager/Cloudflare.
        * Status: DONE — `bootstrap/app.php` configures `TRUSTED_PROXIES` with env fallback (`*`). (see `bootstrap/app.php`)
    * **Task 1.4: Structured Logging:** Configurazione Monolog per output JSON su `stdout` (Docker Native Logging).
        * Status: DONE — default log channel is `stderr_json` and uses `JsonFormatter` (see `config/logging.php`).
    * **Task 1.6 (Security Hardening):**
        * **TRUSTED_PROXIES:** Non usare il wildcard `*` in produzione. Documentare e preferire una lista di CIDR/mask (es. load-balancer, proxy public IP ranges) via `TRUSTED_PROXIES` env var. (implemented in `bootstrap/app.php` — currently defaults to `*`)
        * **Rate Limiting:** MISSING for agent endpoints — the agent endpoints exist (`routes/api.php`) but no route-level throttles / limiter bindings were found. Recommend adding a named RateLimiter and attaching `throttle:` middleware to `/v1/discovery` and `/v1/agent` routes.
        * **Docs & Alerts:** Documentare il rischio di header spoofing e aggiungere monitor/alerting per anomalie sugli endpoint di ingest (frequenza, fail rate).

## 🔐 EPIC 2: ACCESS & MULTI-TENANCY
* **Goal:** Implementare una "Soft Multi-tenancy" sicura e trasparente.
    * **Task 2.1: Tenant Scope Infrastructure (Hybrid):**
        * Status: DONE — `TenantAware` trait is implemented and applied to tenant-owned models. (see `app/Models/Concerns/TenantAware.php` and models e.g. `app/Models/Device.php`, `app/Models/Rack.php`, `app/Models/Subnet.php`, `app/Models/Vlan.php`, `app/Models/IpAddress.php`)
        * **Trait:** Implementare un `TenantAware` trait che applica automaticamente `where('customer_id', session('tenant_id'))` sui modelli che lo usano.
        * **Global Scope:** Applicare il trait ai modelli *tenant-owned* (Device, Rack, Subnet, Vlan) in modo che il filtro sia automatico nelle query Eloquent.
        * **Exclusion:** I modelli *globali* (DeviceModel, Settings, User) devono rimanere senza `TenantAware` — accesso cross-tenant è intenzionale.
        * **Override / Admin & Jobs:** Il trait deve permettere `withoutTenantScope()` (or `TenantAware::disable()` context) per job di background, task amministrativi e migrations backfill.
        * **UI Selector & session:** Conservare il selettore cliente in UI (Topbar) che imposta `session('tenant_id')`; quando `tenant_id` è NULL l'utente in scope admin vede tutte le risorse (god-mode) con chiaro visual indicator.
    * **Task 2.2: Advanced Auth:**
        * Status: PARTIAL — Filament/TOTP MFA support and Fortify docs are present (see `docs/docs-laravelv12/fortify.md` and Filament references) but the emergency CLI command `netvibe:reset-mfa {email}` is NOT present in the repository (no matching Artisan command file found). Recommend implementing or removing the roadmap line.
        * **MFA:** Abilitare Filament Two-Factor Authentication (TOTP).
        * **Emergency:** Implementare comando CLI `netvibe:reset-mfa {email}` per recovery.
        * **Password Reset:** Implementare flow standard Laravel Fortify/UI.

## 🔌 EPIC 3: PHYSICAL LAYER (Filament Native)
* **Goal:** Gestione del cablaggio utilizzando componenti nativi Filament (Forms, Actions, Infolists) invece di UI custom complesse.

    * **Task 3.1: Schema "Ports & Links" (Foundation):**
        * Status: DONE — migrations are present for `cable_types`, `ports`, and `links` (see `database/migrations/2025_12_05_112959_create_cable_types_table.php`, `2025_12_05_113000_create_ports_table.php`, `2025_12_05_113001_create_links_table.php`).
        * **Migration 1:** Tabella `cable_types` (id, name, color, category).
        * **Migration 2:** Tabella `ports` (device_id, name, type, status).
        * **Migration 3:** Tabella `links` (port_a_id, port_b_id, cable_type_id, color).

    * **Task 3.2: Port Automation:**
        * Status: DONE — `app/Actions/Physical/GenerateDevicePortsAction.php` exists and is used by the `Device` model (see `app/Models/Device.php`).
        * **Action:** `GenerateDevicePortsAction`. Alla creazione di un Device, genera N record nella tabella `ports` basandosi sui contatori `ethernet_ports` e `sfp_ports` del `DeviceModel`.
        * **Logic:** Naming sequenziale standard (1, 2, 3... e SFP1, SFP2...).

    * **Task 3.3: Rack View 2.0 (Infolist-Based):**
        * **Refactor:** Aggiornare `RackVisual` per usare una Blade View semplificata che renderizza le porte come una griglia di **Filament Actions** o **Badges**.
        * **Interaction:** Cliccando su una porta (Badge), si apre una `ManagePortAction` (Modale).
        * **Status & Note:** 
            * **Goal (unchanged):** usare una Blade View semplificata che renderizzi le porte come una griglia di **Filament Actions** o **Badges** e permetta interazione (modale/slide-over).
            * **Recent work (prototype):** un prototipo server-side SVG è stato sviluppato come esperimento per posizionare i chassis e le porte via coordinate calcolate (server-side SVG renderer). Sono stati inoltre creati mockup Blade temporanei e un Livewire test page per la verifica visiva.
            * **Result / Decision:** il renderer SVG si è rivelato fragile (arrotondamenti pixel, padding/stroke impliciti, misure non deterministiche) e ha causato problemi di allineamento e manutenzione. L'esperimento è stato rimosso e la view è stata semplificata (chassis placeholder) per stabilità in produzione. The Filament-native widget `RackVisualizerWidget` and unit test are present (see `app/Filament/Clusters/Racks/Resources/Racks/Widgets/RackVisualizerWidget.php` and `tests/Unit/RackVisualTest.php`).
            * Status: PARTIAL — the core widget + unit test exist, the recommended full Filament-native grid refactor is not yet completed.
            * **Current recommendation:** abbandonare il disegno complesso SVG per questa feature e completare la **versione Filament-native (grid/tabella)** per il rendering delle porte — delegare il layout al motore CSS/Filament invece che a calcoli SVG assoluti.
            * **Files / artifacts (dev cycle):** prototipo temporaneo (server-side SVG view + mockups + Livewire wrapper) è stato creato e poi rimosso; il widget ora mantiene la logica core (fetch di `record->units` e `devices->ports`) con una view semplificata.
            * **Next steps:** 1) prototipare la versione Filament-native (grid) e integrarla gradualmente; 2) aggiungere test E2E/acceptance che verifichino il layout e le azioni (clic porta → slide-over); 3) introdurre un flag `dev-mode` per overlay diagnostici senza modifiche a produzione.

    * **Task 3.4: Connection Workflow (The Form):**
        * Status: DONE — `LinkResource` and its `LinkForm` are implemented (see `app/Filament/Resources/Links/LinkResource.php` and `app/Filament/Resources/Links/Schemas/LinkForm.php`).
        * **UI:** Implementare l'azione di connessione come un Form Filament standard dentro la modale della porta.
        * **Fields:**
            1.  `Select::make('target_device_id')` (Searchable).
            2.  `Select::make('target_port_id')` (Filtrato per mostrare solo porte libere del device scelto).
            3.  `Select::make('cable_type_id')`.
        * **Logic:** Creazione record nella tabella `links`.

    * **Task 3.5: Connection Explorer:**
        * Status: DONE — Filament `LinkResource` exists (see `app/Filament/Resources/Links/LinkResource.php`).
        * Una Risorsa Filament standard `LinkResource` per vedere, filtrare ed esportare tutte le connessioni in formato tabellare.

## 🧪 EPIC 4: QUALITY & AI GUARDRAILS
* **Goal:** Prevenire il degrado del codice.
    * **Task 4.1: Pest Conversion (Legacy Cleanup):**
        * Status: PARTIAL — `pestphp/pest` is in dev dependencies and many tests exist, but a repo-wide, fully-converted Pest test suite is not yet verifiable as complete. Recommend a follow-up sweep to convert legacy phpunit tests to Pest where intended.
        * Convertire i 42 file di test legacy di stile PHPUnit in Pest nativo.
        * Regola: "Controllers must not use Eloquent Models directly".
        * Regola: "No `dd`, `dump`, `ray` in production code".
        * Regola: "All source files must have `strict_types=1`".
    * **Task 4.2:** Refactoring Test Suite esistente in Pest PHP nativo.

## 🛠️ EPIC 5: UX REVOLUTION
* **Goal:** Migliorare la navigazione e la leggibilità.
    * **Task 5.1: Navigation Overhaul:**
        * Status: PARTIAL / TODO — documented as an outstanding UI refinement; repo archives and notes show that global navigation entries still appear and a migration to grouped navigation has not been fully applied (see `_DOCS/archive/*` notes and UI issues in `_DOCS/archive/ISSUES.md`). Some Filament resources and groups are configured, but the UI still shows global navigation entries; action needed to finish grouping and hide resources from global nav where required.
        * Abbandono dei Cluster per Navigation Groups collassabili ("IPAM Core", "Infrastructure", "Settings").
    * **Task 5.2: Subnet View 2.0:**
        * Layout a 2 colonne: Albero Navigazione (Sinistra) + Dettagli/Tabella (Destra).
        * Tabella IP "Power User" (refinements).
    * **Task 5.3: Global Search:**
        * Status: PARTIAL — many resources include `recordTitleAttribute` and are prepared for global search, but I did not find evidence of a global search configuration explicitly attached across the panel (no `->globalSearch(...)` calls or a panel-level global search registration in the scanned code). Files to inspect/adjust if enabling global search fully: resource classes and panel config (`app/Filament/*` and `docs/docs-filamentv4/03-resources/10-global-search.md`).
        * Global Search (Spotlight style) su IP, MAC, Hostname e Device.

# Acceptance criteria

---

## Implementation Notes & Priorities

- Prioritize a safe, incremental rollout: begin with `Task 1.2` (FrankenPHP image build verification + CI smoke tests), then `Task 1.5` DB tenancy fix, `Task 2.1` TenantScope, `Task 3` physical layer as a feature flagged rollout.
- Avoid breaking production: container name/image renames must be coordinated with DevOps; migrations for `ports` and `links` should include idempotent/planned backfills and an opt-in run.
- Testing: add acceptance E2E harness for RackVisual / Cable Manager to catch UI regressions early.
- Security: All restore operations must run under elevated admin roles and be rate-limited with audit logging.

