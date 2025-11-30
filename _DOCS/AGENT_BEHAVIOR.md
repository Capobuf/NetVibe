# 🤖 AGENT BEHAVIORAL PROTOCOL (v5.0 - Verified)

## 1. 🛡️ ANTI-HALLUCINATION & CONTEXT
* **READ FIRST:** Always read `_DOCS/STATUS.md` at the start of a new chat.
* **SOURCE OF TRUTH:** The database schema is defined by the Models and verified by the latest migration (`2025_12_02_...`).
* **NO GUESSING:** If a Filament method or component is needed, verify the namespace against Filament v4 documentation.

## 2. 🐳 DOCKER EXECUTION
* **MANDATORY:** All commands run via `docker compose exec app <command>`.
* **Environment:** Assume the test environment is stable (`sqlite:memory`) but the development environment uses MariaDB (`DB_HOST=db`).

## 3. 🏗️ CODING STANDARDS
* **Filament:** Use only **Clusters**. Do not use the root `app/Filament/Resources` directory.
* **Data Integrity:** Use `foreignId(...)->constrained(...)->onDelete('set null')` for nullable foreign keys to prevent data loss.
* **Typing:** All new classes must use `declare(strict_types=1);`.
* **Testing:** New tests MUST be written using **Pest** syntax in `tests/Feature`.

## 4. 🛠️ CRUD UX POLICY (NEW)
* **DELETE PLACEMENT:** The Delete Action must ONLY be visible within the record's **Edit Page** (`getHeaderActions`), never directly in the table's record actions (`recordActions`).
* **CONFIRMATION:** The Delete Action must ALWAYS be configured with a confirmation modal (e.g., `modalHeading(...),` `modalDescription(...))` and a clear confirm button label.
* **BULK DELETE:** Bulk delete actions (`DeleteBulkAction`) in the table toolbar are permitted and required.

* **EDIT / CREATE BEHAVIOR:** Edit/Create flows should prefer in-place UI surfaces: use `->slideOver()` for complex forms and `->modal()` for simple confirmation or quick inputs. **Record-level Edit/View actions must use `->slideOver()` or `->modal()` and MUST NOT open a full page for editing.**

## 8. 🎯 VERIFICA MANUALE (CRITICA)
* **RULE:** Non segnalare MAI un task UX o Feature come completato finché non ricevi una conferma manuale esplicita dall'utente.
* **FAILURE:** Se una funzione o un'azione è stata modificata, il prossimo prompt deve richiedere all'utente di verificare la funzionalità.
