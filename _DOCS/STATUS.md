# 🟣 PROJECT STATUS — NetVibe (Source of Truth)
**Last Updated:** 2025-12-01

## 🆔 Identity (REBRANDING COMPLETE)
- **Project Name:** NetVibe
- **Tagline / Identity:** Vibe‑Native Network Infrastructure Management

All internal code, container slugs and primary UI strings have been rebranded from "Modern IPAM" to **NetVibe**. This repository now reflects the canonical NetVibe identity (see Roadmap V2.0 Task 1.1 marked DONE in `_DOCS/ROADMAP.md`).

## 🚧 Current Work — ROADMAP V2.0 (Final Scope)
- **Plan:** The consolidated Roadmap V2.0 (Final Scope) is approved and committed to `_DOCS/ROADMAP.md`.
- **Next Block:** EPIC 1 — GENESIS (Nginx stack consolidation and infra hardening) is the immediate implementation priority (critical path for v2.0 rollout).

- ## 🏗️ Architecture & Runtime
- **Stack:** Docker (Nginx + PHP 8.4 FPM) is the declared runtime for production images. CI/CD and docs updated to reflect Nginx + FPM; Octane/FrankenPHP references removed unless an intentional migration is planned.
- **Proxy & TLS:** `TrustProxies` configured to work behind reverse proxies (Nginx Proxy Manager, Cloudflare) and preserve HTTPS header trust when needed.
- **Soft‑Tenancy:** Soft multi‑tenancy model enabled — global tenant selector with session-scoped `current_tenant_id`; NULL tenant behaves as God Mode (shows all tenants).

## 📊 Observability & Logging
- **12‑Factor Logging:** Monolog configured for JSON output on stdout/stderr to align with Docker native logging and central aggregation.

## 🔐 Security & Auth
- **Advanced Auth:** Filament MFA (TOTP) enabled for administrative accounts. Emergency CLI command support for MFA reset is in place for recovery workflows.

## ✅ Status Summary
- Test and CI: existing automated test coverage retained; focus on integrating Octane runtime into CI pipelines and ensuring lifecycle hooks are compatible.
- Next actions: finalize Octane tuning in production images, audit long‑running stateful services (jobs) for Octane/EOL compatibility, and validate plugin/Filament integrations under the new single‑container runtime.

## 🔴 CRITICAL: Phase 4 Ingest Actions — Security Audit (2025-12-16)
**Status:** 4 Production Vulnerabilities Identified  
**Documentation:** `_DOCS/SECURITY_AUDIT_PHASE4_INGEST_ACTIONS.md`

### Vulnerabilities Found:
1. **CRITICAL:** Race condition in `IngestMikrotikArpAction` — concurrent IP updates not protected by `DB::transaction()` or `->lockForUpdate()`
   - Impact: Data corruption, duplicate MACs, lost topology data
   - Fix: Add pessimistic locking or upsert pattern
   
2. **HIGH:** Missing MAC address normalization — different formats (AA:BB:CC vs aa-bb-cc vs aabbcc) stored as separate records
   - Impact: Topology links fail to match, duplicate MAC entries
   - Fix: Create `MacAddressService`, normalize before all DB inserts
   
3. **HIGH:** Unidirectional topology links — `connected_to_port_id` only set one direction
   - Impact: Incomplete topology if one device offline, directional bias in reporting
   - Fix: Create reverse links automatically or redesign with `PortLink` table
   
4. **MEDIUM:** Missing transaction wrapper in `IngestMikrotikInventoryAction`
   - Impact: Partial device updates if save fails mid-operation
   - Fix: Wrap in `DB::transaction()` for atomicity

### Deployment Blockers:
- ⚠️ **DO NOT DEPLOY Phase 4 Job Dispatching to production** until vulnerabilities fixed
- Estimated fix effort: 5.5 hours
- See security audit document for detailed implementation guidance

**Notes:** This file is the authoritative status summary for Project NetVibe — see `_DOCS/ROADMAP.md` for the detailed V2.0 technical roadmap.
