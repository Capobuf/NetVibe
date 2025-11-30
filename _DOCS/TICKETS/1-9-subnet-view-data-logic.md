# Ticket 1.9 — Subnet View Data Logic (Detailed)

Goal

Implement proper inheritance and override semantics for `Customer` and `Gateway` on Subnet view/edit pages.

Subtasks

- 1.9.1 DB & Model audit
  - Verify `subnets` table has `customer_id` and `gateway` as nullable columns.
  - Add migration: `add_customer_source_to_subnets` (string/enum `customer_source` values: `explicit`, `inherited`, default null or `inherited` as needed).
  - Test: migration applied successfully in CI and preserves existing data.

- 1.9.2 Business logic — inheritance
  - Add `getEffectiveCustomerAttribute()` (or `getEffectiveCustomer()` method) on `app/Models/Subnet.php` to return Subnet.customer_id if set, else `vlan.customer_id`.
  - Ensure setting a Subnet customer sets `customer_source = 'explicit'`.
  - Writing/Deleting a value should set `customer_source` appropriately (`explicit` or null).
  - Add unit tests verifying overwrite behavior and effective value.

- 1.9.3 Gateway UX & form
  - Modify `SubnetResource` form to include `gateway` select/input.
  - When Subnet.gateway is null, show suggested gateway from VLAN (UI hint only, not auto-fill), with a small badge "suggested".
  - Ensure saving a gateway writes the value explicitly.
  - Add feature tests for form behaviour and visual badges.

- 1.9.4 Bulk propagation & API
  - On `VlanResource` edit page, add a bulk action: "Propagate Customer to inherited Subnets".
  - Implement dry-run preview: return number of subnets that would be updated + sample and require confirm modal to apply.
  - Implement queued job for actual propagation to prevent HTTP timeouts for large fleets.
  - Log propagation actions and provide a rollback token for a short window (24h) if feasible.
  - Add tests for API endpoints and the dry-run/execute flow.

- 1.9.5 Tests & docs
  - Add unit tests for model accessors and services.
  - Add feature tests for Subnet edit, VLAN bulk-propagation preview/execute.
  - Add E2E tests for UI flows (optional but recommended).
  - Update docs (_DOCS/ROADMAP.md, README, and any dev notes) to document the propagation policy.

Acceptance criteria

- Subnet shows inherited customer when `customer_id` is null.
- Setting a customer on Subnet marks it as `explicit` and remains unchanged on VLAN updates.
- Bulk propagation only updates `inherited` subnets and requires confirmation; includes dry-run preview.

Files to modify (likely)

- app/Models/Subnet.php
- database/migrations/* (new migration for customer_source)
- app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php
- app/Filament/Clusters/Vlans/Resources/VlanResource.php (bulk action)
- app/Services/VlanPropagationService.php (new service)
- tests/Unit/*, tests/Feature/*, tests/E2E/*

Risks & Mitigations

- Mass updates may be large: use queued jobs + batching + progress + logs.
- Data-loss concerns: require dry-run and explicit confirmation; provide audit and optional rollback.
- Race conditions: using DB transactions and job idempotency.
