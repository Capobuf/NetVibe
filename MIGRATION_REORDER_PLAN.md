# MIGRATION_REORDER_PLAN

Date: 2025-12-03

Purpose
-------
Analisi di dipendenza delle migration in `database/migrations` e piano di rinominazione (modifica *solo* dei nomi dei file, per fissare l'ordine di esecuzione Laravel). Non cambiare il contenuto PHP delle migration.

Background
----------
La suite di migrations contiene operazioni di CREATE, ALTER e INSERT/SEED. Laravel esegue le migration in ordine alfabetico (timestamp nel filename). Se una migration che scrive/aggiorna una tabella precede la migration che crea quella tabella, l'esecuzione fallirà (es. `DB::table('settings')->updateOrInsert` prima di `Schema::create('settings')`).

Analisi Anomalie (scoperta)
---------------------------
- `2025_02_01_000005_add_discovery_settings.php` — esegue `DB::table('settings')->updateOrInsert(...)`.
- `2025_11_29_133422_create_settings_table.php` — crea la tabella `settings` ma ha timestamp successivo (Nov 29) rispetto al file che la usa (Feb 01).

Conseguenza: le migration falliscono su installazione pulita (Day-0) perché si tenta di inserire/aggiornare `settings` prima che la tabella sia stata creata.

Valutazione di altre migrazioni rilevanti
----------------------------------------
- `users` e `jobs` — le migration di creazione sono già molto early (0001_01_01_*) e non mostrano conflitti.
- IPAM core (subnets, ip_addresses) — la migration `create_ipam_schema` precede le alter/seed successive; le alter/insert successive includono guard (Schema::hasTable / hasColumn) e sono generalmente safe.
- `add_discovery_columns_to_subnets` (2025_02_01_000020) tenta di aggiungere `scan_agent_id` anche se `scan_agents` non esiste — la migration gestisce entrambi i casi e inserisce la colonna senza FK se la tabella `scan_agents` non c'è (poi una migration successiva aggiungerà FK). È robusta.

Anomalie cronologiche trovate
----------------------------
1. `2025_02_01_000005_add_discovery_settings.php` (reads/writes `settings`) — OCCURRENCE BEFORE — depends on `settings` table created in later migration
2. `2025_11_29_133422_create_settings_table.php` (creates `settings`) — should be earlier than 2025_02_01_000005

Piano di Rinominazione (principio)
----------------------------------
- Regola generale: assicurare che tutte le CREATE table essenziali (Users, Jobs, Settings) siano eseguite all'inizio dell'anno 2025 (o prima delle migration che ne fanno uso).
- Le migration di alter/modifica restano dopo le CREATE che aggiungono le tabelle interessate.
- Le migration che inseriscono/seedano dati vengono posizionate dopo la creazione delle tabelle su cui operano.

Mapping proposto (specifico)
----------------------------
- Spostare `2025_11_29_133422_create_settings_table.php` → `2025_01_01_000004_create_settings_table.php`
  - Motivazione: posizionandola subito dopo `2025_01_01_000003_fix_scan_agents_schema.php` (che oggi esiste) garantiamo che `2025_02_01_000005_add_discovery_settings.php` trovi `settings` già esistente.

Nota: Questa è la modifica **minima** necessaria per correggere l'errore riscontrato. Altre migration sembrano già essere sicure o idempotenti.

Rinominazione - script (dry-run / apply)
---------------------------------------
Il seguente script esegue le rinominazioni usando `git mv` (conserva lo storico). Ha due modalità: `--dry-run` (mostra cosa verrebbe fatto) e `--apply` per eseguire effettivamente i `git mv`.

Esempio di uso:

    # Dry-run: mostra le modifiche
    ./scripts/rename_migrations_fix_order.sh --dry-run

    # Apply: effettua i git mv
    ./scripts/rename_migrations_fix_order.sh --apply

Regola d'oro: eseguire sempre in branch dedicato e fare CI build/test dopo renaming; non eseguire merge fino a quando la suite di migration non è stata verificata in ambiente di staging.

---

Appendice: script proposto (viene anche aggiunto come file eseguibile nel repository `scripts/rename_migrations_fix_order.sh`).

---

```bash
# scripts/rename_migrations_fix_order.sh
# Run with --dry-run to preview, --apply to execute.
set -euo pipefail

DRY=true
if [ "${1:-}" = "--apply" ]; then
  DRY=false
fi

declare -A MAP
# mapping: old -> new
MAP["2025_11_29_133422_create_settings_table.php"]="2025_01_01_000004_create_settings_table.php"

BASEDIR="database/migrations"

echo "Migration reorder script — dry run = $DRY"

for src in "${!MAP[@]}"; do
  dst=${MAP[$src]}
  srcpath="$BASEDIR/$src"
  dstpath="$BASEDIR/$dst"

  if [ ! -f "$srcpath" ]; then
    echo "WARNING: source missing: $srcpath — skipping"
    continue
  fi

  if [ -f "$dstpath" ]; then
    echo "ERROR: destination already exists: $dstpath — abort to avoid collision"
    exit 1
  fi

  echo "Will rename: $srcpath -> $dstpath"
  if [ "$DRY" = false ]; then
    git mv "$srcpath" "$dstpath"
    echo "Renamed $src -> $dst"
  fi
done

if [ "$DRY" = false ]; then
  echo "All renames applied. Please run tests and verify migrations run in the expected order before pushing/merging."
fi

```

---

Next steps consigliati
----------------------
1. Eseguire lo script in modalità `--dry-run` e rivedere la lista dei rename.
2. Creare branch di lavoro e eseguire `./scripts/rename_migrations_fix_order.sh --apply`.
3. Eseguire la suite di migrations in un ambiente di staging o tramite la CI (preferibilmente con un snapshot DB di prova) e correre la suite di test integrata.
4. Verificare che non ci siano ulteriori migration che scrivono tabelle non ancora create — in quel caso aggiustare la lista mapping.

Se vuoi, procedo ora ad aggiungere il file di script `scripts/rename_migrations_fix_order.sh` con permessi di esecuzione e un commit proposto (ma **non** applicherò i rename sui file reali finché non autorizzi). 
