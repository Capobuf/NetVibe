# Ticket 1.10 — IP Table & IpAddressVisualGrid Overhaul (Detailed)

Goal

Redesign the Subnet IP list to a compact, usable "IP Table" and rebuild the visual widget `IpAddressVisualGrid` into a compact grid layout (with performance handling for large subnets).

Subtasks

- 1.10.1 UI rename + toolbar action
  - Rename visible title from "IP Addresses" to "IP Table" in Subnet view pages.
  - Add toolbar action/button "New IP Address" that opens the Create IP form prefilled with current Subnet ID.
  - Add tests verifying title and toolbar exist.

- 1.10.2 Grid redesign
  - Reimplement `IpAddressVisualGrid` as a CSS Grid-based compact layout. Optionally implement SVG fallback for consistent rendering.
  - Cells should be small, clickable (open Edit/Create flow), and support tooltips for quick info.
  - Simplify legend; move to a compact overlay or collapsible component.
  - Add unit and visual tests.

- 1.10.3 Large-subnet strategy
  - For subnets larger than a reasonable threshold (configurable, e.g. > /24), show a condensed/virtualised view: summarized groups with expand-on-demand per block.
  - Implement server-side pagination where applicable and a lightweight client-side virtualization technique.
  - Add tests verifying performance and UX correctness for large subnets.

- 1.10.4 Tests & accessibility
  - Add visual/UI tests (Playwright / Cypress) to assert grid presence, keyboard navigation, and click-to-edit behaviour.
  - Enforce ARIA labels and keyboard access for assistive technologies.

- 1.10.5 Edge cases & data mapping
  - Correctly mark reserved/broadcast addresses and show appropriate states.
  - Support IPv6 gracefully (if present) by either dedicated view or summarized groups.
  - Add tests for edge cases and empty/overflow states.

Acceptance criteria

- "IP Table" title is visible and "New IP Address" prefill works.
- Grid widget renders a compact, clickable grid for small-to-medium subnets and switches to condensed/virtualized mode for large subnets.
- Test coverage includes unit, integration and visual checks.

Files to modify (likely)

- resources/views/filament/infolists/components/ip-address-visual-grid.blade.php
- app/Filament/Infolists/Components/IpAddressVisualGrid.php
- app/Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php
- resources/views/filament/pages/subnet-view.blade.php (or equivalent)
- tests/Unit/*, tests/Feature/*, tests/E2E/*

Performance & UX notes

- Rendering tens of thousands of cells in the browser is not feasible. Choose grouped/virtualized approaches for large networks.
- Prefer consistent cell sizing and simple interactions to keep accessibility and mobile support.
