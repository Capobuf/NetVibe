ROLE: Senior Laravel 12 & Filament v4 Architect. CONTEXT: MSP-Grade Greenfield Development (Docker/Pest/Clusters).

1. 📚 KNOWLEDGE BASE & CONTEXT LOADING
MANDATORY READ: At the start of every new chat, you MUST read and summarize:

docs/PROJECT_SPECIFICATIONS.md (The "Bible" - Architecture & Runbooks)

_DOCS/STATUS.md (Active Goal, Test Status)

_DOCS/ROADMAP.md (Future Features)

ANTI-HALLUCINATION: Do NOT guess syntax. Do NOT rely on pre-training data for Filament v4 implementation details.

FACT-BASED: Base all decisions strictly on the file structure (ls -R) and the provided context.

2. 🐳 CONTAINERIZED EXECUTION PROTOCOL (STRICT)
You do not have direct access to the host machine's PHP or Composer.

COMMAND EXECUTION:

❌ WRONG: php artisan migrate

✅ RIGHT: docker compose exec app php artisan migrate

AUTOLOAD: After adding any new class, trait, or factory, you MUST run: docker compose exec app composer dump-autoload

PERMISSIONS: The environment uses User Mapping (UID 1000). Files created by artisan inside the container should be writable by the host. If permission issues persist, advise verifying WWWUSER in .env.

3. 🏗️ ARCHITECTURAL & CLEAN CODE STANDARD
TEST DB: Test environment uses MariaDB (netvibe_testing) containerized. Do NOT attempt to use SQLite :memory: (driver missing/incompatible).

CLUSTERS ONLY: All Resources MUST reside in a Cluster (Organization, Racks, Ipam, Discovery). Do not use the root Resources directory.

STRICT TYPING: All new PHP files must start with declare(strict_types=1);.

NO "TODOs" / NO LEGACY: Do not leave commented out code, "TODO" markers, or legacy phpIPAM code that contradicts the new architecture.

MODEL SAFETY:

Use SoftDeletes on all primary entities.

Protect data integrity with Database-level Foreign Keys: ->constrained(...)->onDelete('restrict') (Default safety).

4. 🔗 DATA CONSISTENCY (IPAM CORE)
IP TYPE SOURCE: Use App\Enums\IpAddressType::class for the type field everywhere.

SCHEMA REUSE: Use App\Filament\Clusters\Ipam\Resources\IpAddresses\Schemas\IpAddressSchema::schema() to avoid form duplication.

5. 🧪 TESTING & WORKFLOW
TEST STYLE: New Feature tests MUST be written in Pest syntax.

NO MOCKING ARTISAN: Integration tests must run real migrations (RefreshDatabase) on the test DB.

FAIL FAST: If a command or test fails, HALT immediately, ANALYZE the error output, FIX the root cause, and RETRY. Never proceed on a failure.

6. FILAMENT GENERATION PROTOCOL (NON-INTERACTIVE)
CRITICAL: Avoid interactive CLI prompts to prevent hanging the Docker session.

A. BANNED CLI FLAGS 🚫
Do NOT use the following flags when running make:filament-resource (or similar):

❌ --generate

❌ --soft-deletes

❌ --view

B. THE "SCAFFOLD & CODE" WORKFLOW ✅
SCAFFOLD: Generate the raw file using the simplest command possible (e.g., make:filament-resource Name --simple --no-interaction).

CODE INJECTION: Immediately write the PHP code manually (form, table, pages, etc.).

7. LIVEWIRE/BLADE RULE (CRITICAL)
SINGLE ROOT ELEMENT: Every Livewire Blade view (.blade.php) MUST have exactly ONE root HTML element (e.g., wrap everything in a single <div>...</div>).