# TEST GAP REPORT — Coverage & Gap Analysis

Role: Lead QA Engineer
Source: FULL_PROJECT_CONTEXT.txt (complete repository dump)
Date: 2025-12-03

This report compares the test suite (tests/) with the application logic (app/) and highlights coverage, missing tests (gaps), quality concerns (happy-path vs edge cases) and a prioritized test plan.

---

## 1. EXECUTIVE SUMMARY
- **Totale Test Suite:** 50 test files found in `tests/` (Feature + Unit)
- **Copertura Stimata (Logica Core):** Media — many core services are covered but several critical paths are untested.
- **Area più a rischio:** Setup Wizard (`app/Livewire/SetupPage`) and environment management (`app/Services/EnvEditor`) — **🔴 CRITICO**. Also: `app/Jobs/ScanSubnetJob` (scan worker) — **🔴 CRITICO**.

Rationale: The discovery/ingest flow, IP utilities (conversion and fping) and many Filament resources have meaningful tests (including edge cases). However the Setup flow, .env editing service and the subnet scanning job are not unit-tested and have risky behaviors (destructive migrations, runtime .env writes, exec). Those gaps are high-impact if left untested in CI or prod.

---

## 2. DETAILED COVERAGE (Coverage Matrix)
Below is a per-module matrix with tests found, what they cover, missing edge cases and risk level.

| Modulo / Funzionalità | File di Test Trovati | Cosa Copre (Happy Path) | Cosa MANCA (Edge Cases / Missing) | Livello Rischio |
| :--- | :--- | :--- | :--- | :--- |
| **Setup Wizard** (`app/Livewire/SetupPage.php`) | **Nessuno** | — | DB connection failures, .env write permissions/atomicity failures, migration timeouts / partial failures, concurrent runs / lock behavior, idempotence of admin creation & seeding behavior | 🔴 CRITICO |
| **EnvEditor** (`app/Services/EnvEditor.php`) | **Nessuno** | — | Atomic writes/locking, permission errors, invalid value quoting/escaping, file not present, backup/rollback scenarios | 🔴 CRITICO |
| **System Health** (`app/Services/SystemHealthCheckService.php`) | **Nessuno** | — | Tests for each health-check condition, dependency health variations, slow responses, false positives/negatives | 🟠 ALTO |
| **Discovery ingest action** (`app/Actions/Discovery/IngestScanResultsAction.php`) | tests/Unit/DiscoveryIngestActionTest.php & tests/Feature/Actions/IngestLogicRegressionTest.php | Good coverage, includes: MAC mismatch handling, last_seen update, new-ip auto-import, pending->approved event flows — multiple edge cases | Lower-level errors (DB transaction failures), contention under concurrent ingests, large batch handling performance and retries not fully tested | 🟡 MEDIO |
| **ProcessDiscoveryIngestJob** (`app/Jobs/ProcessDiscoveryIngestJob.php`) | Indirect: Feature tests assert dispatch (tests/Feature/Api/DiscoveryIngestApiTest.php, AgentFullCycleTest.php, IngestLogicRegressionTest.php) | Confirms job dispatch and some end-to-end behavior via synchronous dispatch in Feature tests | Job-level failure handling, retries, exception paths on job handle, queue semantics, idempotency under requeue not unit-tested | 🟠 ALTO |
| **ScanSubnetJob (scan worker)** (`app/Jobs/ScanSubnetJob.php`) | **None** | — | Scanning logic, fping parsing integration, discovery disabled path, error logging, worker failure/retry handling, resource usage (sleep/backoff) | 🔴 CRITICO |
| **FpingWrapper (scanner)** (`app/Services/Scanners/FpingWrapper.php`) | tests/Unit/FpingWrapperTest.php | Excellent: covers success, non-fatal exit codes, fatal exit codes (throws) | Performance edge cases, IPv6 coverage and invalid inputs beyond invalid CIDR test | 🟢 BASSO |
| **IpConversionService** (`app/Services/IpConversionService.php`) | tests/Unit/IpConversionServiceTest.php & tests/Unit/IpRoundTripTest.php | Strong: IP conversions, round-trip checks, CIDR parsing | Extreme edge-cases (very large masks, IPv6 edge cases) — low priority | 🟢 BASSO |
| **Filament Resources / UI (Subnets, IpAddresses, Devices etc.)** | Multiple Feature tests: SubnetResourceTest, IpAddressFormTest, Device tests, GeneralSettingsPageTest | UI flows, Form/RelationManager behaviors and many happy & some edge cases tested | Some UX error flows, permission edge cases, complex relation scenarios; more integration tests would help | 🟡 MEDIO |
| **ScanAgent / Agent API** | tests/Feature/Api/AgentPollingLogicTest.php, AgentFullCycleTest.php, AgentConfigApiTest.php and others | Good coverage: API polling, token auth, config responses, push results, logs — includes many edge conditions (401 handling, discover_subnet false) | Resilience under malformed payloads, partial network failures, API rate-limits and malformed token payloads not fully stress-tested | 🟡 MEDIO |
| **Subnet prefill / Migrate subnet actions** | tests/Feature/SubnetPrefillDispatchTest.php, Unit/MigrateSubnetActionTest.php | Tests job dispatch rules and action logic; migration helpers covered | Large-n, performance, rollback and transactional edge cases not exhaustively tested | 🟡 MEDIO |
| **Utilities / Observers** (`app/Observers/SubnetObserver.php`, rules, validators) | A few unit tests touching observers via integration tests (SubnetPrefillDispatchTest) | Basic observer pathways are exercised by integration tests | Specific failure modes, observer side-effects under race conditions are untested | 🟡 MEDIO |

---

## 3. QUALITY ASSESSMENT — Tests that exist: happy path vs edge cases
- Strong unit testing: Discovery ingest action, FpingWrapper, IpConversionService — include explicit edge-case tests.
- Good end-to-end feature testing: Agent API endpoints, Discovery ingest endpoints, Filament resource UIs and some job dispatch tests.
- Weak / missing coverage: Setup wizard, EnvEditor, ScanSubnetJob, SystemHealthCheckService. Many of these are high-risk because they touch environment management, long-running operations, or remote execution.

General notes about test quality:
- Tests for ingestion logic and fping explicitly include edge cases and failure scenarios — good example of robust unit testing.
- Many feature tests use `migrate:fresh` in beforeEach hooks; this is robust but slows CI — `RefreshDatabase`/transactional tests or optimized migration caching could speed up CI.
- UI/Filament tests cover functionality but often focus on happy-paths; consider adding negative/permission tests for UI flows.

---

## 4. PIANO DI TEST PRIORITARIO (Action Plan) — 5 MUST DO tests (ordered)
These are the minimum tests I recommend to stabilise the codebase and reduce immediate risk.

1) Setup Wizard safety tests (High priority — CRITICAL)
   - Unit & integration tests for `app/Livewire/SetupPage` covering:
     - DB connection failure: when testConnection() fails, the form should set dbTestStatus and not attempt environment writes.
     - `.env` write failures (file missing or permission denied): simulate `EnvEditor` throwing and assert graceful retry/notification behavior.
     - runMigrations() should respect lock: simulate concurrent runs (mock Cache lock) causing a notification and not running migrations. Test both safe migrate and explicit confirmFullReset destructive path (including production guard).
     - Seeding idempotence under repeated runs and skipped seeding when tables not present.

2) EnvEditor robustness (High priority — CRITICAL)
   - Unit tests for `app/Services/EnvEditor`:
     - setKey / setKeys update vs append when file exists or not.
     - Proper quoting when values contain spaces or special chars.
     - Atomic write and backup path (simulate File::put failing), file read when .env missing, permission error.
     - Edge case: value contains double quotes inside.

3) Scan worker tests (High priority — CRITICAL)
   - Unit & integration tests for `app/Jobs/ScanSubnetJob`:
     - Scanning a subnet with discovery disabled should not create records; logs are written.
     - Fping output parsing integration with `FpingWrapper` under success and failure codes and invalid outputs.
     - Job handles exceptions, retries and logs errors without leaving inconsistent DB state.

4) ProcessDiscoveryIngestJob / job-level resilience (Medium-High)
   - Unit tests for `ProcessDiscoveryIngestJob` behavior: ensure it calls IngestScanResultsAction, handles exceptions, and marks events/logs appropriately. Also test re-run/idempotency.

5) SystemHealthCheckService tests & Setup interplay (Medium)
   - Unit tests for each specific health check and integration test that the `SetupPage` reacts correctly to failing health checks (blocks or warns depending on policy).

---

## 5. Recommendations & Next Steps
1. Write the critical tests above (SetupWizard, EnvEditor, ScanSubnetJob) — these are the highest-risk untested surface. Start with the SetupPage as it can perform destructive ops.
2. Add unit tests for job-level behavior for `ProcessDiscoveryIngestJob` and `ScanSubnetJob` to ensure handling, retries, and no-data-loss semantics.
3. Replace / mitigate `migrate:fresh` in many tests with either `RefreshDatabase` or optimize by using `RefreshDatabaseState` and caching to speed CI.
4. Add CI checks for risky patterns: runtime `.env` writes, `Artisan::call('migrate:fresh')` inside web code (fail build or warn). These can be lint rules to catch risky patterns on push.

---

If you'd like, I can start implementing the top-priority tests now (for example, add unit tests for `EnvEditor` and `SetupPage` helpers), or I can create GitHub issues/PR templates for the 5 critical tests above. Which do you prefer me to do next?
