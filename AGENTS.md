# NetVibe — AGENTS.md

Instructions for all coding agents. The Product Owner makes product decisions and approves functional/UX changes. These rules apply to every task, including documentation-only changes.

## 1. Protected specification — mandatory prior authorization

- **Canonical specification:** `docs/specification/NETVIBE_SPEC.md`. This file is the only authoritative product specification. Other files in `docs/specification/` may explicitly identify themselves as drafts, proposals or appendices; those designations must be respected.
- **Protected paths:** all of `docs/specification/**`, `AGENTS.md`, `.github/CODEOWNERS`, and `docs/README.md`. Never add, delete, rename, move, format, regenerate, replace or edit protected content without the Product Owner's **explicit, change-specific authorization**. This includes spelling fixes, links, headings, bulk formatting, automatic tool rewrites and apparently harmless adjustments.
- **Before any proposed specification change**, present the Product Owner, in Italian:
  1. The precise file and sections concerned; the proposed changes (old/new or a readable diff).
  2. Why the change is necessary and what happens if it is not made.
  3. Its concrete impact, where relevant, on IPAM rules, UI/UX, database/data migrations, tenant isolation, integration behavior, API, tests and existing workflows.
  4. Any contradictions with other sections, approved decisions, current implementation or dependent modules; uncertainties and possible regressions.
  5. A minimal alternative when an equally correct smaller change exists.
- **Stop and request explicit approval before editing**. An issue assignment, an instruction to fix code, a PR comment, a generic "proceed", or the absence of objections does not authorize a specification change unless the Product Owner specifically approves the proposed specification modification and its scope. A new or expanded change requires renewed authorization.
- After approval, apply **only** the authorized spec diff and report the changed sections. Never silently resolve a contradiction by editing another section. If more changes are needed, request authorization again.
- The specification may not be changed as a side effect of implementing an issue, changing framework versions, applying a formatter, installing a skill, refactoring, or cleaning the repository.
- **Do not weaken these rules** by editing `AGENTS.md` or `.github/CODEOWNERS` without the same prior explanation and explicit approval.
- `CODEOWNERS` documents review ownership but is not itself a technical merge/push lock. Do not claim enforcement unless applicable GitHub branch protection requiring Code Owner review is actually configured and verified.

## 2. Never invent; minimal coherent changes

- Never invent facts, requirements, API capabilities, behavior, URLs, results or completed actions. Never make silent product assumptions; report an unresolved decision instead of guessing.
- Read the issue and relevant approved documentation and source files **in full**; split long files into chunks. Do not mistake truncated tool output for missing file content. Do not indiscriminately inspect the entire repository.
- Follow **Ponytail/YAGNI**: the smallest complete change that fixes the real issue. No scope/feature creep, unnecessary abstraction, speculative compatibility, microservices, plugin infrastructure, extra configuration, unrelated refactoring or premature generalization.
- Reuse existing Laravel mechanisms and shadcn/ui components. Apply DRY to business behavior, not to every superficially similar line.
- No ceremonial pre/during/post-change audits, repeated environment probes, generated inventories or unsolicited reports. **Do not generate SHA256 or other file checksums merely to compare files** or create comparison scripts for straightforward edits.
- Do not remove essential input validation, authorization, error handling, accessible UI, prevention of data loss or customer/site isolation.
- Code identifiers and non-obvious code comments in English; UI and user-facing updates in Italian. Explain the reason for unusual logic, not obvious syntax.

## 2a. UI/UX quality and user-facing content

- Tutte le tabelle principali devono offrire ricerca sull’intero elenco, ordinamento dalle intestazioni con ciclo ascendente → discendente → predefinito e selettore delle colonne integrato nell’intestazione, a destra. Il selettore permette di mostrare, nascondere e riordinare le colonne. Ricerca e configurazione devono essere mantenute durante ordinamento e paginazione. Riutilizzare i componenti condivisi.
- Minimalism applies to implementation complexity and feature scope, not to visual quality, usability or interface completeness.
- Design interfaces around the user's actual tasks and information hierarchy, not merely around database entities or backend structure.
- Make full and appropriate use of the Laravel React/Inertia starter kit, its layouts, navigation patterns and shadcn/ui ecosystem.
- Prefer existing components, but freely add official shadcn/ui components when they provide a better interaction. Do not introduce another UI framework or unnecessary third-party dependencies.
- Choose deliberately between dedicated pages, tabs, sections, dialogs, sheets, menus and contextual actions. Avoid both oversized, mixed-purpose pages and unnecessary navigation fragmentation.
- Create visually polished, responsive and accessible interfaces with coherent spacing, clear hierarchy, appropriate iconography and well-defined interaction states.
- The smallest code diff is not automatically the best UI solution. Reasonable layout and component restructuring is allowed when necessary to deliver a complete user experience within the issue's scope.
- Product specifications define application behavior, not user-facing copy. Do not turn domain rules, invariants, technical limitations or implementation details into explanatory UI text.
- Until explicitly requested otherwise, do not add educational descriptions, generic disclaimers, redundant helper text or reassuring statements about how the application works.
- Preserve essential labels, validation feedback, actionable errors, accessibility information and warnings about concrete operational consequences.
- Exercise autonomy in visual composition and component selection, but never introduce unapproved product behavior or functional requirements.
- Present complete, usable UI flows for Product Owner review before PR creation or merge.

## 3. Product boundaries and architecture

- The currently chosen platform is Laravel + React/Inertia + TypeScript + Tailwind + **shadcn/ui** + MySQL. Docker Compose is the development environment. Framework versions and starter-kit details marked as proposals are **not approved merely by appearing in documentation**.
- **IPv4 only. No IPv6** implementation, fields, helpers, validation, compatibility layers, placeholders or future-proofing.
- The IPAM must function without any integration. Respect A01 invariants and A03's distinction between documented data, external configuration and temporary observations.
- Integrations are read-only toward managed infrastructure. No mandatory probes, remote agents, VPN, SNMP, general reconciliation engine, CQRS, microservices or plugin framework.
- Prefer a lightweight Laravel modular monolith; implement modules only when needed. Do not create extra layers, ports, repository interfaces, event buses or queues by default.
- Enforce company/site permissions, uniqueness and concurrency constraints on the backend and database, not just in React.
- No permanent worker, production Node process, SSR or Redis dependency without an approved demonstrated need.

## 4. Branch, implementation and owner acceptance

1. **Every GitHub issue uses a dedicated branch** named `issue/<number>-<short-name>` from `main`, including small issues unless the owner explicitly instructs otherwise.
2. Substantial tasks without issues use `task/<short-name>`. A genuinely trivial, isolated request **without an issue** may be made on local `main` without an unnecessary branch/PR.
3. Make only changes necessary for the issue. Do not run repeated tests, linters or audit scripts before/during development.
4. After implementing or applying feedback, **refresh the actual Docker development instance**, including only required source/asset/dependency/container/migration updates. Preserve its development database and test data. Perform the minimum runtime check needed to know that the preview is accessible.
5. Explain in Italian what changed, especially in UI, identify the branch, give only verified working page links, and list concrete steps A/B/C (with expected results) for the Product Owner to try. If unavailable, state the limitation; invent no links.
6. **Owner reviews manually first.** Address feedback in the same branch, refresh Docker, and ask for review again where UI/UX changed.
7. **Only after owner approval**, execute minimal issue-relevant automated tests on a dedicated MySQL testing database. If fixes change UI/UX, notify the owner and obtain renewed review. Fixes that leave the approved UI/UX unchanged do not require renewed approval; complete relevant checks and continue the already authorized workflow.
8. After approval and successful relevant checks, push, create PR, merge and close the linked issue. **Do not push/open PR/merge/close before approval** unless the Product Owner explicitly asks for GitHub publication as the task's outcome; in that case publish the branch only and do not infer merge approval.
9. Trivial approved direct-`main` tasks can commit/push without a PR. Never force-push or rewrite `main`.

## 5. Proportional testing and minimal CI

- No browser end-to-end tests (Playwright, Cypress or Selenium). The Product Owner manually verifies the UI/UX in Docker.
- No coverage targets, duplicate test layers for the same behavior, routine snapshots, exhaustive matrices or tests of framework internals.
- Add only a test that realistically catches an issue-specific regression: e.g. one unit test for tricky IPv4/CIDR/range calculation, or a focused MySQL feature test for persistence, tenant isolation, race conditions or authorization. Pure copy/rename usually needs none.
- Automated tests are **post-approval**, not a repetitive development ritual. When a test fails, fix the cause and rerun only the impacted check.
- Use a distinct MySQL test database (e.g. `netvibe_test`) in Docker. Never reset/migrate/seed the browsable development database as part of a test. Abort a destructive test command if the database is not demonstrably isolated.
- Keep initial CI minimal: PHP formatting, approved relevant backend tests, and TypeScript/build checks as needed. Do not introduce E2E, coverage gates, PHPMD, Deptrac or additional scanning stages without approval.
- Be transparent about which tests ran and which have been deferred.

## 6. Skills

- Use Ponytail for small effective changes; prefer its skill without lifecycle hook overhead. This project's owner-acceptance-before-tests rule takes precedence over generic skill recommendations.
- Use Laravel Boost for version-aware reference material and task-relevant Laravel/Inertia/Pest skills when installed.
- Use the official shadcn/ui skill; reuse configured UI components before adding new ones.
- Use React Best Practices (vercel:react-best-practices) when writing or reviewing React/TSX code; apply only rules relevant to this React/Inertia application, keeping changes minimal and preserving owner acceptance before automated tests.
- A NetVibe IPAM skill may refer to approved A01 rules, but must not silently turn proposals into requirements or modify protected documentation.
- Do not bulk-install unrelated skills or let a skill installer overwrite protected files without the Product Owner's explicit approval.

## 7. Completion message (Italian)

Report: issue/task and branch; exact changes; verified preview links or a clear blocker; short A/B/C steps for manual verification; whether Docker was updated; whether tests were intentionally deferred; any material limitations. Before the owner's acceptance, explicitly state: **No tests yet; no PR or merge** (unless a specifically authorized documentation publication changed only the remote branch).
