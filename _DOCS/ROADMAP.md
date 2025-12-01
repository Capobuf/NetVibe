# 🗺️ PROJECT ROADMAP (v1.0 MVP Next Steps)

## PHASE 1: UX CRITICAL REFINEMENT (COMPLETED — 2025-12-01)
* **Task 1.1 (CRITICAL FIX) — RESOLVED (2025-11-30):** Fix Subnet View: Display CIDR string instead of decimal IP in the Infolist. (See `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` — uses `$record->cidr` accessor)
* **Task 1.2 (CRITICAL FIX) — RESOLVED (2025-11-30):** Restore Missing CRUD: Delete actions restored for Subnets and VLANs.

	- Subnet: `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` (table record actions), `app/Filament/Clusters/Ipam/Resources/Subnets/Pages/ViewSubnet.php` (view header).
	- VLAN: `app/Filament/Clusters/Ipam/Resources/Vlans/VlanResource.php` (table record actions).
	- Tests: `tests/Feature/Arch/CodeQualityTest.php` and `tests/Feature/ResourceLabelNormalizationTest.php` updated to assert DeleteAction presence.
* **Task 1.3 (UX/VISUAL FIX):** Fix Rack View: Debug the `RackVisual` component to render the graphical device map instead of the unit list.
	- **RESOLVED (2025-12-01):** Rack visualization refactor completed and validated — `RackVisual` now renders the graphical device map reliably across client states. Unit tests and visual checks added to prevent regressions.

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

* **Task 1.7 (UX/CRUD CONSISTENCY) — RESOLVED (2025-12-01):** Fix Device Resource: Ensure all record actions (Edit) use `->slideOver()` instead of opening a full page; tests updated.

* **Task 1.8 (CRITICAL FIX) — RESOLVED (2025-12-01):** Fix Spatie MissingSettings Error: save logic in `GeneralSettingsPage` updated to pass required settings properties and tests added to prevent regressions.

* **Task 1.9 (DATA LOGIC/INHERITANCE) — RESOLVED (2025-12-01):** Fix Subnet View Data Logic (customer/gateway inherit and override semantics implemented, migrations and tests added).
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

* **Task 1.10 (VISUAL/IP TABLE OVERHAUL) — RESOLVED (2025-12-01):** Redesigned IpAddresses Table and Visual Map Widget; UI updates and tests added.
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
* **Task 2.1 (CRITICAL) — NEXT / PENDING:** Docker Split (Separate compose.yml for Prod and compose.override.yml for Dev) — final production validation and CI rollout remaining.

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

* **Task 2.2 — DONE (2025-12-01):** Setup Wizard (Level Pro: Web interface for Admin creation and initial settings). Code implemented (middleware, SystemHealthCheckService, Livewire wizard). Pending manual verification.

	- **Task 2.2.1 (Middleware):** Create a middleware `EnsureAppIsInstalled` that enforces a redirect to `/setup` when the application is not yet provisioned. Behaviour and acceptance criteria:
		- Detects missing installation state by checking either: absence of any admin users, a missing flag in settings, or an empty/invalid DB connection.
		- If the app is uninstalled, any non-`/setup` request is redirected to `/setup` (preserving return path where reasonable).
		- Routes under `/setup` and API endpoints required during install must bypass this middleware.
		- Tests: unit tests that mock user counts and DB connectivity; integration tests asserting requests are redirected correctly and `setup` routes remain reachable.

	- **Task 2.2.2 (System Check Logic):** Implement a service `SystemHealthCheckService` responsible for running the setup prechecks. Requirements:
		- Expose checks for:
			- Required PHP extensions (listable + status), e.g. OpenSSL, PDO, mbstring, ctype, fileinfo, redis, gd, etc.
			- Storage permissions: verify `storage/` and `bootstrap/cache` are writable.
			- Database connectivity and basic schema state (can connect, optional migration/initialization sanity marker).
			- Redis connectivity (if configured) and optional credential checks.
			- Internet connectivity (optional ping to configured endpoints or DNS check), with measurable timeout and safe failure mode.
		- Each check should return a result object: { id, name, severity: (ok|warning|critical), message, details }.
		- Service must be injectable via the container and also callable from CLI (`php artisan app:health-check --format=json`) to aid CI and automated checks.
		- Tests: unit tests for each check edge case and a feature test covering the aggregated health evaluation.

	- **Task 2.2.3 (Wizard Page - UI):** Implement a Filament/Livewire standalone Setup Wizard page with a stepper layout and guarded route `GET /setup`.
		- UI Requirements & Steps:
			1. **Step 1 — Health checks:**
				- Display all checks from `SystemHealthCheckService` with a semaphore (green/yellow/red) and detail rows for failures.
				- If any **critical** failures exist, block progression to the next step and show actionable remediation hints (e.g., filesystem permissions commands, missing PHP extension install command).
				- Provide re-run button to refresh checks after fixes.

			2. **Step 2 — Database:**
				- Present a form to test DB connection (host, port, db, username, password) — allow live test without saving.
				- When connection failure occurs, allow runtime override of DB configuration in the current request via temporary values (but do not persist them unless user proceeds and chooses to apply).
				- Provide a prominent action `Run Migrations` (with a confirmation modal) to initialise schema. Show migration output (or simplified success/fail messages) and optional link to retry.
				- Tests: integration tests that exercise DB test success/failure and simulate migration steps.

			3. **Step 3 — Admin:**
				- Guided form to create the first Admin user (name, email, password, role) with validation and password strength check.
				- On successful create, mark the app as installed (persist `installed=true` in settings or create an initial system admin marker). The middleware should stop redirecting after this step completes.
				- Tests: feature tests to assert admin creation and middleware behaviour after creation.

			4. **Step 4 — Config & Finish:**
				- Provide fields for initial site config: `Site Name`, primary `Color` (theme seed), and a toggle `Install Demo Data`.
				- If `Install Demo Data` is toggled, run a safe, non-destructive seeder flow (display progress) and mark the result.
				- Final validation: summary screen showing results of steps and a `Finish` button that redirects to the login/dashboard and disables `/setup`.

		- Accessibility & UX:
			- Ensure the setup pages are keyboard accessible and properly labelled for screen readers.
			- Display clear progress, link to help text and suggest next troubleshooting steps for the common errors found in Step 1.
		- Security & Operational considerations:
			- Rate-limit sensitive actions (migration, admin create) and protect CSRF.
			- Do not allow multiple concurrent installs; if app is partially installed, provide clear recovery guidance.
		- Tests: end-to-end tests covering happy path and failure modes for each step.

	- **Acceptance Criteria (Task 2.2 overall):**
		1. `EnsureAppIsInstalled` middleware exists and correctly redirects when required.
		2. `SystemHealthCheckService` implements and exposes per-check results; CLI command exists for CI.
		3. Setup Wizard UI is implemented as a stepper with the 4 steps and blocks progression on critical failures.
		4. First admin creation marks the app as installed and unlocks normal routes.
		5. All critical flows are covered by automated tests (unit, integration, and at least one E2E happy-path).

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


## PHASE 3.5: API PUSH & CONFLICT RESOLUTION (COMPLETED — 2025-12-01)
GOAL: Implementare un modello di comunicazione bidirezionale sicuro (Server ↔ Agent) e un'area di staging per la risoluzione manuale dei conflitti (MAC/Hostname mismatch).

**Stato avanzamento (2025-12-01):**

- 3.5.1.1: Sanctum installato, trait HasApiTokens aggiunto a ScanAgent, migrazioni eseguite. — DONE (2025-12-01)
- 3.5.1.2: Tabella discovery_events creata e modello implementato. — DONE (2025-12-01)
- 3.5.2: Generazione e revoca token API implementata su ScanAgentResource (DONE).
- 3.5.3: Endpoint /api/v1/agent/config protetto da Sanctum, restituisce subnets assegnate (DONE).
- 3.5.4: Endpoint /api/v1/discovery/results protetto, validazione con FormRequest, dispatch asincrono a Job ProcessDiscoveryIngestJob. Logica DIFF implementata: solo mismatch MAC/Hostname crea DiscoveryEvent, altrimenti aggiorna last_seen (DONE, test di integrazione passati).
- 3.5.5: Risorsa Filament DiscoveryEventResource creata (cluster Discovery), tabella con badge, filtro PENDING, bulk actions Approva/Merge (aggiorna ip_addresses e segna APPROVED) e Scarta (soft delete) — **implementato e testato**.
- 3.5.6: Test API e test di integrazione/feature per la logica di diff e le azioni massive implementati e passati.

**Note tecniche:**
- Tutte le azioni massive sono testate via Pest (feature/unit) e la logica di merge/scarto è verificata.
- Correzioni Filament: proprietà $model e $navigationGroup aggiornate per compatibilità con Filament v4.
- La risorsa DiscoveryEventResource è visibile nel cluster Discovery e mostra solo i record PENDING per default.
- La logica di merge aggiorna ip_addresses solo se l'evento è approvato manualmente.
- La soft delete degli eventi scartati è auditabile.

**Prossimi step consigliati:**
- Estendere la logica di diff per gestire anche i conflitti hostname e casi edge.
- Aggiungere notifiche/admin audit per le azioni massive.
- UI: migliorare la visualizzazione dei dettagli evento e aggiungere filtri avanzati.

## PHASE 3.6: AGENT OBSERVABILITY & ALERTS (COMPLETED — 2025-12-01)
**GOAL:** Implementare un sistema completo di logging per gli agenti remoti con retention automatica e allarmi critici via notifica UI. (Implemented & verified)

* **Task 3.6.1: Database & Notifications Infrastructure — DONE (2025-12-01)**
    * **Action (Log Table):** Creare la migrazione per la tabella `agent_logs` (`agent_id`, `level`, `message`, `context_json`, `created_at`).
    * **Action (Notifications):** Eseguire `php artisan notifications:table` e migrare per abilitare il centro notifiche di Filament (Campanella).
    * **Action (Provider):** Abilitare `databaseNotifications()` nel `AdminPanelProvider`.

* **Task 3.6.2: Settings & Configuration — DONE (2025-12-01)**
    * **Action (Discovery Settings):** Aggiungere `prune_logs_days` (int, default 3) in `DiscoverySettings` (o GeneralSettings, se preferito per coerenza retention).
    * **Action (General Settings):** Aggiungere `agent_alert_level` (enum: 'WARNING', 'ERROR', 'CRITICAL', 'off') in `GeneralSettings`. Questo determina la soglia per far scattare la campanella.
    * **Action (Agent Config):** Aggiungere `log_level` (filtro invio) nella tabella `scan_agents`.

* **Task 3.6.3: API Ingestion & Alerting Logic — DONE (2025-12-01)**
    * **Action (Endpoint):** Implementare `POST /api/v1/agent/logs`.
    * **Action (Logic):** Salvare il log nel DB.
    * **Action (Alert Trigger):** Se il livello del log è >= `agent_alert_level`, inviare una `Filament\Notifications\Notification` (Database Channel) a tutti gli utenti Admin.

* **Task 3.6.4: UI & Maintenance — DONE (2025-12-01)**
    * **Action (UI):** Aggiungere il Relation Manager `AgentLogsRelationManager` nella risorsa `ScanAgentResource` (visualizzazione tabellare dei log).
    * **Action (Maintenance):** Creare il Job schedulato `PruneAgentLogsJob` per eliminare i vecchi log.

## PHASE 4: ARCHITECTURAL DEEPENING
* **Task 4.1:** Subnet Hierarchical Tree View.
* **Task 4.2:** Implement IPAM L2 Domain/VLAN workflow clarity.

**Task 3.5.3.3 (Deployment File):** Creare il file `docker-compose.remote-agent.yml` nella root. Questo file deve definire un servizio `remote_agent` basato su un'immagine CLI (es. `php:8.4-cli-alpine`) e configurato per il polling.
    * **Task 3.5.3.4 (Agent Script):** Creare lo script PHP `remote_scanner.php` che implementa la logica di Polling (GET config) e Push (POST results) all'interno di un loop continuo (vedi dettagli sopra).
    * **Task 3.5.3.5 (UX Update):** Modificare la notifica dell'azione `Generate API Key` per fornire istruzioni di deployment basate sul nuovo `docker-compose.remote-agent.yml` e l'iniezione della chiave API (via ENV).