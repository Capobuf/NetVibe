# 🗺️ PROJECT ROADMAP (v1.0 MVP Next Steps)

## PHASE 1: UX CRITICAL REFINEMENT (IMMEDIATE)
* **Task 1.1 (CRITICAL FIX) — RESOLVED (2025-11-30):** Fix Subnet View: Display CIDR string instead of decimal IP in the Infolist. (See `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` — uses `$record->cidr` accessor)
* **Task 1.2 (CRITICAL FIX) — RESOLVED (2025-11-30):** Restore Missing CRUD: Delete actions restored for Subnets and VLANs.

	- Subnet: `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` (table record actions), `app/Filament/Clusters/Ipam/Resources/Subnets/Pages/ViewSubnet.php` (view header).
	- VLAN: `app/Filament/Clusters/Ipam/Resources/Vlans/VlanResource.php` (table record actions).
	- Tests: `tests/Feature/Arch/CodeQualityTest.php` and `tests/Feature/ResourceLabelNormalizationTest.php` updated to assert DeleteAction presence.
* **Task 1.3 (UX/VISUAL FIX):** Fix Rack View: Debug the `RackVisual` component to render the graphical device map instead of the unit list.
	- **ATTEMPTED (2025-11-30) — FAILED / REQUIRES REWORK:** I implemented a number of iterations on the Rack visualization (Blade-first, block/grid-based approach) but the view did not render reliably in the UI for the reviewer (page still showed a vertical list or was visually empty depending on state and client cache). The rendering approach needs a rework (either further CSS/layout work or a small JS/SVG renderer) before this can be considered done.

	- Summary of work performed (files modified / inspected):
		- `resources/views/filament/infolists/components/rack-visual.blade.php` — multiple refactors: attempted absolute-positioned device blocks, then moved to a block-based `flex-col-reverse` implementation (per-unit heights and clickable Create/ Edit slots), added empty-state and accessibility attributes.
		- `app/Filament/Infolists/Components/RackVisual.php` — the component that computes unit layouts was examined (algorithm validated by `tests/Unit/RackVisualTest.php`).
		- `app/Filament/Clusters/Racks/Resources/Racks/Tables/RacksTable.php` — adjusted link behavior during this work (separate UX fix for table links).
		- Tests touched / run: `tests/Unit/RackVisualTest.php`, `tests/Feature/Arch/CodeQualityTest.php`, `tests/Feature/ResourceLabelNormalizationTest.php` (all passing after changes).

	- Next recommended steps (future work):
		1. Decide whether to continue with a pure CSS/HTML solution (tighten layout, add ruler column, improve accessibility and contrast), or adopt a small JS/SVG renderer (Konva, D3 or a lightweight SVG solution) for pixel-correct device placement and to avoid cross-page overflow.
		2. Add a browser-based / visual UI test (playwright or Cypress) that verifies the rack drawing is present and clickable at runtime (prevents regressions where Blade changes don't show up due to caching/DOM issues).
		3. If continuing with Blade/CSS, rework the container sizing, remove any overflow from parent elements, and add a clear, visible ruler column for U numbers (non-text-list) to aid comprehension.
* **Task 1.4 (UX/Cleanup) — RESOLVED (2025-11-30):** Clean up Global Settings Page (removed scan timeout, fixed button spacing, relocated navigation group).

	- Changes implemented:
		- `app/Settings/GeneralSettings.php` — removed `scan_ping_timeout` and related fields (moved to DiscoverySettings).
		- `app/Filament/Pages/GeneralSettingsPage.php` — moved page under the `Administration` navigation group, cleaned up mount/save logic and wrapped the form schema in a `Section` to improve spacing.
		- `resources/views/filament/pages/general-settings.blade.php` — separated the page actions into a distinct card to prevent the Save button from appearing attached to the form box.
		- Tests updated: `tests/Feature/GeneralSettingsPageTest.php`, `tests/Feature/GeneralSettingsPageHttpTest.php`.

* **Task 1.5 (UX/CRUD CONSISTENCY):** Enforce new CRUD Policy across all existing Resources (Remove `DeleteAction` from Tables, verify confirmation in Edit Pages).
	- **RESOLVED (2025-11-30):** Initial pass completed — removed `DeleteAction` from table record actions, added header Delete actions on edit pages with confirmation modals, and updated tests to enforce the new policy.

* **Task 1.6 (UX/LINK FIX) — RESOLVED (2025-11-30):** Fix Rack Resource Table: Link column now navigates to the **View Page** (not the Edit Page).

	- Changes implemented:
		- `app/Filament/Clusters/Racks/Resources/Racks/Tables/RacksTable.php` — `name` column updated to use `RackResource::getUrl('view', ['record' => $record])` so list names navigate to the view page.
		- Added a test to verify the table column uses the `view` URL: `tests/Feature/Arch/CodeQualityTest.php`.

* **Task 1.7 (UX/CRUD CONSISTENCY):** Fix Device Resource: Ensure all record actions (Edit) use `->slideOver()` instead of opening a full page.

* **Task 1.8 (CRITICAL FIX):** Fix Spatie MissingSettings Error: Update the save logic in `GeneralSettingsPage` to correctly pass all required settings properties. (STATUS: PENDING)

* **Task 1.9 (DATA LOGIC/INHERITANCE):** Fix Subnet View Data Logic.
	- **Goal:** Implement the correct business logic for the `Customer` and `Gateway` fields on Subnet Infolist/View pages.
	- **Required Behavior:**
		1. `Customer` value should inherit/cascade from the linked VLAN when it exists, but remain user-overwritable at the Subnet level (explicit Subnet value takes precedence).
		2. `Gateway` should be a user-definable field in the Subnet Edit form and **not** be automatically pre-filled by derived logic; provide a clear editable selector and update behaviour in save logic.
	- **Notes / Next steps:** update `SubnetResource` (form, infolist), migrations (if needed), and add tests validating inheritance + overwriteability.

	- **Subtasks (1.9.x):**
		- **1.9.1 (DB & Model audit):** Verify DB has `customer_id` and `gateway` columns nullable on `subnets` table. Add migration to add `customer_source` (enum/string) to track explicit vs inherited.
		- **1.9.2 (Business logic — inheritance):** Implement `getEffectiveCustomer()` accessor on `Subnet` model and a `customer_source` enum value set when user explicitly assigns a customer.
		- **1.9.3 (Gateway UX & form):** Make `gateway` a user-editable field in the Subnet Edit form and show a `Suggested gateway` (derived) badge instead of auto-filling.
		- **1.9.4 (Bulk propagation & API):** Add an opt-in bulk-propagation action on VLAN edit that previews affected subnets (dry-run) and applies changes only to `customer_source == 'inherited'` entries via queued job.
		- **1.9.5 (Tests & docs):** Add unit tests for model accessors, feature tests for edit/override flows, and E2E tests to verify propagation UI and dry-run behavior.

* **Task 1.10 (VISUAL/IP TABLE OVERHAUL):** Redesign IpAddresses Table and Visual Map Widget.
	- **Actions:**
		1. Rename the table title from "IP Addresses" to **"IP Table"** in the Subnet View and list screens.
		2. Add a **"New IP Address"** toolbar action/button that opens the Create IP form and pre-fills the Subnet ID (context-aware create flow).
		3. Redesign `IpAddressVisualGrid` (footer widget) to a compact, grid-like layout (in the spirit of phpIPAM / GitHub style compact grids) instead of a vertical list — simplify the legend and reduce visual noise. Add unit tests and a visual/UI test to guarantee layout presence.
	- **Notes / Next steps:** coordinate with Rack/IPAM visual style guidelines; create a migration plan for UX rollout and visual regression testing.

	- **Subtasks (1.10.x):**
		- **1.10.1 (UI rename + toolbar action):** Rename table title to **"IP Table"** and add a toolbar action `New IP Address` that opens the Create form with Subnet pre-filled.
		- **1.10.2 (Grid redesign):** Reimplement `IpAddressVisualGrid` as a compact CSS Grid (or lightweight SVG fallback) with clickable cells, tooltips and small cell states (used/free/reserved).
		- **1.10.3 (Large-subnet strategy):** Implement virtualization/condensed modes for large subnets (e.g. /24+), include grouping and a progressive 'expand' control for detail.
		- **1.10.4 (Tests & accessibility):** Add unit tests, visual/regression tests (playwright/cypress) and ensure ARIA/keyboard support for the grid widget.
		- **1.10.5 (Edge cases & data):** Ensure correct mapping for IPv4/IPv6, reserved/broadcast addresses, multicast ranges and provide graceful empty/overflow states.

## PHASE 2: FOUNDATIONAL INFRASTRUCTURE & WIZARD
* **Task 2.1 (CRITICAL):** Docker Split (Separate compose.yml for Prod and compose.override.yml for Dev).

	- **CONFIG STRATEGY NOTE (decisions & tradeoffs):** There are three viable approaches for configuring services (DB/Nginx) across environments — each has different tradeoffs and operational costs:

		1. **Env-only (minimal)**
			 - Behavior: use a single base `docker-compose.yml` and choose configuration differences by `.env` / `.env.production` files only.
			 - Pros: Simple, fewer files and artifacts; easy to change behaviour by switching env files.
			 - Cons: Not all low-level settings (e.g. InnoDB tuning or complex Nginx directives) are exposed via environment variables — some production tuning cannot be expressed without file-based configuration or templating.

		2. **Templating (recommended hybrid for flexibility)**
			 - Behavior: keep lightweight template files (e.g. `my.cnf.tpl`, `default.conf.tpl`) and generate final config at container startup via `envsubst` or a small entrypoint script using environment variables.
			 - Pros: No image rebuild required to change config, templates are versioned in the repo, flexible for both dev and prod.
			 - Cons: Requires a small entrypoint or templating step; slightly more complexity than env-only.

		3. **Baked into image (immutable config)**
			 - Behavior: copy config files into service images at build time (image contains `my.cnf`, nginx config baked in). Good for fully reproducible production images.
			 - Pros: Deterministic, reproducible production artifacts and easier to reason about for release pipelines.
			 - Cons: Requires image rebuild for config changes; more Dockerfile artifacts to maintain.

	- **Implementation status (current repository):** During this task we implemented a mixed approach to be conservative and reproducible by default:
		- The base compose has been moved to `docker/docker-compose.yml` to centralize container stack configuration.
		- Development overrides live under `docker/dev/docker-compose.override.yml` (local mounts, exposed dev ports, node service).
		- A production override was created under `docker/production/docker-compose.prod.yml` and a sample `.env.production.example` was added to document secure values.
		- For MariaDB we created `docker/mysql/Dockerfile` and bake `my.cnf` into the image by default (reproducible production image). The worker command was made configurable via `WORKER_CMD` environment variable so prod behaviour can be customized without an additional override file.

	- **Recommendation / next step:** If you want maximum flexibility during development and prefer to avoid extra Dockerfiles, switch to the templating approach (templates + `envsubst`) — this gives the best balance between env-only simplicity and the reproducibility of baked images. If your deployment requires strict production immutability (release images) stick with baked config and use templates for dev.

* **Task 2.2:** Setup Wizard (Level Pro: Web interface for Admin creation and initial settings).

* **Task (Logging Strategy):** Implement 12-Factor Logging Strategy (Stdout/Stderr).
	- **Goal:** Conformare Laravel alle best practice di Docker, passando il logging da file-based a `stdout/stderr` (console logs) per una raccolta log centralizzata (Twelve-Factor App principle).
	- **Actions:**
		1. Configurare il canale di logging di Laravel per utilizzare il driver `stderr` per l'ambiente `stack`.
		2. Standardizzare l'uso della variabile d'ambiente `LOG_LEVEL` per controllare la verbosità.
		3. Definire i livelli standard: `LOG_LEVEL=debug` in sviluppo (usando il file di override dev) e `LOG_LEVEL=error` o `warning` in produzione (usando il file di override prod).
		4. Verificare che il comando `docker compose logs <service>` funzioni correttamente per tutti i container (`app`, `worker`).

## PHASE 3: DISCOVERY & ITAM ENHANCEMENTS
* **Task 3.1 (Remote Agents):** Implement API endpoint and logic for remote scan agents to push data into the system.
* **Task 3.2 (UI Filters):** Add advanced filters to IpAddress tables (e.g., filter by IpAddressType::class, last_seen, state).

* **Task 3.3 (Branding UX):** Implement the ability to **upload a logo** that replaces the text `Site Name` (used for dashboard and navigation). (STATUS: PENDING)

## PHASE 3.5: API PUSH & CONFLICT RESOLUTION (CRITICAL SECURITY)
GOAL: Implementare un modello di comunicazione bidirezionale sicuro (Server ↔ Agent) e un'area di staging per la risoluzione manuale dei conflitti (MAC/Hostname mismatch).

Categoria

Task

Dettagli

- Architettura Agent 3.5.0.1: Agente Remoto (Stack)
	- L'agente remoto sarà un singolo container Docker (basato su PHP-FPM Alpine) per coerenza e riutilizzo delle librerie.

- Configurazione Infra 3.5.0.2: Variabili ENV Remote
	- Definire la variabile di deployment remota REMOTE_AGENT_API_KEY e REMOTE_AGENT_API_URL che verranno iniettate nel comando docker run generato dalla UI.

- Backend & Schema 3.5.1.1: Setup Laravel Sanctum
	- Installare `laravel/sanctum`, eseguire le migrazioni e aggiungere il trait `HasApiTokens` al modello `ScanAgent`.

- Backend & Schema 3.5.1.2: Staging Table
	- Creare il modello e la migrazione per la tabella `discovery_events` per gestire i record in stato di conflitto (staging).

- Sicurezza Token 3.5.2: (STATUS: DONE)
	- 3.5.2.1: Generazione Chiavi — Implementata: Custom Action su `ScanAgentResource` per generare una Sanctum API Key (scope `ipam:ingest`).
	- 3.5.2.2: Revoca e Scope — Implementata: generazione di un nuovo token revoca le chiavi precedenti; i token sono permanenti per default.

-- Comunicazione PULL (Config) 3.5.3: (PRIORITY: IMMEDIATE BACKEND TASK)
	- Questo diventa il nuovo task di codifica immediato per il backend.
    
	3.5.3.1: Endpoint Config
	- Implementare l'endpoint protetto `/api/v1/agent/config` (Agent → Server). Questo endpoint risponderà con l'elenco delle Subnet ID assegnate a quell'agente (`Subnet.scan_agent_id = Agente.id`).

- Comunicazione PULL (Config) 3.5.3.2: Assegnazione UI
	- Assicurare che l'assegnazione di Subnet a un agente avvenga impostando il campo `scan_agent_id` nel record Subnet (gestito dalla UI/Actions).

- Comunicazione PUSH (Data) 3.5.4.1: Endpoint Ingestion
	- Implementare l'endpoint protetto `/api/v1/discovery/results` (Agent → Server).

- Comunicazione PUSH (Data) 3.5.4.2: Validazione & Coda
	- Proteggere l'endpoint con una Laravel Form Request dedicata. Il Controller deve inoltrare il payload RAW immediatamente a un Job Asincrono (Coda).

- Risoluzione Conflitti 3.5.4.3: Logica Ingestione (DIFF)
	- All'interno del Job asincrono, eseguire il diff tra il dato in ingresso e il record esistente: solo se c'è un mismatch MAC/Hostname, creare il record in `discovery_events` (Staging) e NON toccare `ip_addresses`.

- Risoluzione Conflitti 3.5.4.4: Health Check (Last Seen)
	- Aggiornare il campo `last_seen` del `ScanAgent` per ogni chiamata riuscita a `/api/v1/discovery/results` (Health check passivo).

- Frontend/UX Review 3.5.5.1: Risorsa Conflitti
	- Creare la risorsa Filament `DiscoveryEventsResource` per permettere all'amministratore di visualizzare gli eventi di conflitto in staging.

- Frontend/UX Review 3.5.5.2: Azioni Massive
	- Implementare azioni massive (Batch Actions) nella risorsa `DiscoveryEventsResource` per: 1) Approvare/Merge (sovrascrive l'IP Address) e 2) Scartare (ignora l'evento).

- Testing 3.5.6.1: Test API Feature
	- Scrivere Test API Feature per l'autenticazione token (401/403) e la validazione (422) di `/api/v1/discovery/results`.

- Testing 3.5.6.2: Test Unitari Logica
	- Scrivere Unit Tests per la logica di diff (conflitto) all'interno dell'`DiscoveryIngestAction`.

## PHASE 4: ARCHITECTURAL DEEPENING
* **Task 4.1:** Subnet Hierarchical Tree View.
* **Task 4.2:** Implement IPAM L2 Domain/VLAN workflow clarity.
