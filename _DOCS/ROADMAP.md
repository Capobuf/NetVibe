# 🗺️ PROJECT ROADMAP (v1.0 MVP Next Steps)

## PHASE 1: UX CRITICAL REFINEMENT (IMMEDIATE)
* **Task 1.1 (CRITICAL FIX) — RESOLVED (2025-11-30):** Fix Subnet View: Display CIDR string instead of decimal IP in the Infolist. (See `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` — uses `$record->cidr` accessor)
* **Task 1.2 (CRITICAL FIX) — RESOLVED (2025-11-30):** Restore Missing CRUD: Delete actions restored for Subnets and VLANs.

	- Subnet: `app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php` (table record actions), `app/Filament/Clusters/Ipam/Resources/Subnets/Pages/ViewSubnet.php` (view header).
	- VLAN: `app/Filament/Clusters/Ipam/Resources/Vlans/VlanResource.php` (table record actions).
	- Tests: `tests/Feature/Arch/CodeQualityTest.php` and `tests/Feature/ResourceLabelNormalizationTest.php` updated to assert DeleteAction presence.
* **Task 1.3 (UX/VISUAL FIX):** Fix Rack View: Debug the `RackVisual` component to render the graphical device map instead of the unit list.
	- **RESOLVED (2025-11-30):** Rack view now renders device blocks with absolute positioning and preserves quick-create slots (see `resources/views/filament/infolists/components/rack-visual.blade.php`).
* **Task 1.4 (UX/Cleanup):** Clean up Global Settings Page (Remove Scan Timeout, fix button spacing, relocate nav).

* **Task 1.5 (UX/CRUD CONSISTENCY):** Enforce new CRUD Policy across all existing Resources (Remove `DeleteAction` from Tables, verify confirmation in Edit Pages).
	- **RESOLVED (2025-11-30):** Initial pass completed — removed `DeleteAction` from table record actions, added header Delete actions on edit pages with confirmation modals, and updated tests to enforce the new policy.

* **Task 1.6 (UX/LINK FIX):** Fix Rack Resource Table: Link column must navigate to the **View Page** (not the Edit Page).

* **Task 1.7 (UX/CRUD CONSISTENCY):** Fix Device Resource: Ensure all record actions (Edit) use `->slideOver()` instead of opening a full page.

## PHASE 2: FOUNDATIONAL INFRASTRUCTURE & WIZARD
* **Task 2.1 (CRITICAL):** Docker Split (Separate compose.yml for Prod and compose.override.yml for Dev).
* **Task 2.2:** Setup Wizard (Level Pro: Web interface for Admin creation and initial settings).

## PHASE 3: DISCOVERY & ITAM ENHANCEMENTS
* **Task 3.1 (Remote Agents):** Implement API endpoint and logic for remote scan agents to push data into the system.
* **Task 3.2 (UI Filters):** Add advanced filters to IpAddress tables (e.g., filter by IpAddressType::class, last_seen, state).

## PHASE 4: ARCHITECTURAL DEEPENING
* **Task 4.1:** Subnet Hierarchical Tree View.
* **Task 4.2:** Implement IPAM L2 Domain/VLAN workflow clarity.
