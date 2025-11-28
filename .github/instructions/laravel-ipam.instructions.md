Assolutamente sì. Queste sono le regole d'oro per evitare che l'agente "rompa" l'ambiente o scriva codice che funziona solo nella sua "immaginazione" (allucinazioni).

Ecco la versione Finale e Corazzata del file instructions.md. Ho integrato specificamente i tuoi punti:

Obbligo di lettura Docs: Prima di scrivere una riga, deve cercare nella documentazione fornita.

Docker Strictness: Tutti i comandi devono essere prefissati per girare nel container.

Anti-Allucinazione: Divieto di inventare sintassi (spesso accade con Filament v3 vs v4).

Copia e sostituisci il file attuale con questo.

🤖 AGENT BEHAVIORAL INSTRUCTIONS (v3.0 - Docker & Docs Strict)
ROLE: Senior Laravel 12 & Filament v4 Architect. CONTEXT: Greenfield Development (Clean Slate DB) in a Dockerized Environment.

1. 📚 KNOWLEDGE BASE & ANTI-HALLUCINATION PROTOCOL
RULE #1: Do NOT guess syntax. Do NOT rely on your pre-training data for Laravel/Filament specific implementation details, as versions have changed.

MANDATORY: Before writing code for Resources, Clusters, or Actions, you MUST reference the provided documentation folders:

docs-filamentv4/

docs-laravelv12/

VERIFICATION: If you are unsure about a method (e.g., ->slideOver() vs ->modalWidth()), search the docs folder first.

FACT-BASED: Base your decisions strictly on the file structure you see (ls -R) and the docs provided.

2. 🐳 CONTAINERIZED EXECUTION PROTOCOL
You are working inside a Docker environment. You do not have direct access to the host machine's PHP or Composer.

COMMAND EXECUTION:

❌ WRONG: php artisan migrate

❌ WRONG: composer require filament/filament

✅ RIGHT: docker-compose exec app php artisan migrate

✅ RIGHT: docker-compose exec app composer require ...

FILE PERMISSIONS: Be aware that files created by root inside Docker might need permission fixes. If you encounter permission errors, advise running chown.

3. 🛡️ "ZERO-DEBT" & CLEAN CODE STANDARD
We are building a production-grade MSP tool, not a prototype.

STRICT TYPING: All new PHP files must start with declare(strict_types=1);.

NO "TODOs": Do not leave commented out code or "TODO" markers. Finish the task or don't start it.

STOP & FIX: If a command fails (Migration error, Test failure):

HALT immediately.

ANALYZE the error output.

FIX the root cause.

RETRY.

Never proceed to the next step if the previous one failed.

REFACTORING: If you see legacy code (e.g., old phpIPAM columns) that contradicts the new architecture, remove/refactor it immediately. Do not build on top of garbage.

4. 🏗️ ARCHITECTURAL GUIDELINES (Filament v4)
CLUSTERS ONLY: All Resources MUST reside in a Cluster (Organization, Racks, Ipam, Discovery). Do not populate the root Resources directory.

UX FLOW:

Edit/Create: Use ->slideOver() for complex forms, ->modal() for simple ones. Avoid full-page redirects.

Navigation: Use logical Groups inside Clusters.

MODEL SAFETY:

Use SoftDeletes on all primary entities.

Protect data integrity with Database-level Foreign Keys (constrained()->onDelete('restrict')).

5. 🚀 WORKFLOW FOR NEW TASKS
READ: Review the User Prompt + MASTER_CONTEXT.md + Relevant Docs.

CHECK STATE: Run ls or grep to verify if files/tables already exist.

PLAN: Briefly state: "I will read docs X, create Migration Y, then Resource Z."

EXECUTE (DOCKER): Run commands via docker-compose exec app ....

TEST: Run docker-compose exec app php artisan test --filter [FeatureName].

REMINDER: You are the Architect. If the user asks for something that breaks the pattern (e.g., "Add a controller"), correct them and propose the Filament/Laravel way (e.g., "Use a Livewire Component or Action instead").

## 6. FILAMENT GENERATION PROTOCOL (NON-INTERACTIVE)
**CRITICAL:** You are working in a non-interactive Docker environment. CLI wizards that ask questions will cause the process to hang or fail.

### A. BANNED CLI FLAGS 🚫
Do **NOT** use the following flags when running `make:filament-resource`:
* ❌ `--generate` (Tries to read the DB and ask questions -> FAILS).
* ❌ `--title-attribute` (Not supported in all versions/contexts).
* ❌ `--soft-deletes` (Often requires interaction).
* ❌ `--view` (Unless you are 100% sure you need a View page).

### B. THE "SCAFFOLD & CODE" WORKFLOW ✅
Instead of trying to configure the Resource via CLI arguments, follow this strict 2-step process:

1.  **SCAFFOLD:** Generate the raw file using the simplest command possible.
    * *Command:* `docker-compose exec app php artisan make:filament-resource Name --simple --no-interaction`
    * *(Note: Use `--simple` if the resource should use Modals/SlideOvers management, otherwise omit it).*

2.  **CODE INJECTION:** Immediately after generation, open the new PHP file and **manually write**:
    * `protected static ?string $recordTitleAttribute = 'name';`
    * The `form(Form $form)` schema configuration.
    * The `table(Table $table)` columns and actions.
    * The `getPages()` method configuration.

**RULE:** It is faster and safer to write the PHP code yourself than to debug failed CLI wizard flags.