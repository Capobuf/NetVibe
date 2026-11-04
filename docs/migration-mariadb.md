# Migrazione di sviluppo a PHP 8.5 e MariaDB 11.4

Procedura per l’issue #100. MariaDB **11.4 LTS** è la release approvata dal Product Owner; il tag `11.4` riceve le patch della stessa serie. PHP usa la serie **8.5**, con patch di sicurezza corrente (minimo **8.5.12**, verificato il 4 novembre 2026). Composer limita il runtime a questa serie e impedisce installazioni con patch precedenti al minimo.

## Prima del cambio

Eseguire dalla radice del checkout che alimenta Docker, con albero di lavoro salvato. Conservare il container MySQL, il suo volume e l’immagine PHP precedente. Non usare `down -v`, `--remove-orphans`, `migrate:fresh` o reseeding. Non montare mai il datadir MySQL su MariaDB.

Il nome nel `.env` può differire da `MYSQL_DATABASE` del container, creato in precedenza: leggere il database effettivamente usato da Laravel. I comandi seguenti presuppongono il Compose precedente, con servizio `mysql`:

```bash
umask 077
NETVIBE_BACKUP=$(mktemp -d "$HOME/netvibe-migration.XXXXXX")
NETVIBE_MYSQL=$(docker compose ps -q mysql)
NETVIBE_DB=$(docker compose exec -T laravel.test php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::selectOne("SELECT DATABASE() AS name")->name;')
cp .env "$NETVIBE_BACKUP/env.mysql"
cp compose.yaml composer.json composer.lock "$NETVIBE_BACKUP/"
git rev-parse HEAD > "$NETVIBE_BACKUP/revision.txt"
printf '%s\n' "$NETVIBE_MYSQL" > "$NETVIBE_BACKUP/mysql-container.txt"
printf '%s\n' "$NETVIBE_DB" > "$NETVIBE_BACKUP/database.txt"
```

I backup contengono dati e credenziali: conservarli fuori dal repository, con accesso limitato. Non rigenerare `APP_KEY`. Conservare anche `storage/app` quando si trasferisce l’istanza su un altro host; questa procedura cambia soltanto il DB locale.

## Preparazione e importazione

Passare al branch della migrazione. Il nuovo Compose usa `sail-mariadb`, separato da `sail-mysql`, e crea l’utente `sail_test` soltanto sul database `testing`. Avviare solo MariaDB e costruire PHP senza sostituire ancora l’app attiva:

```bash
docker compose pull mariadb
docker compose up -d --no-deps mariadb
docker compose build --pull --no-cache laravel.test
docker compose exec -T mariadb healthcheck.sh --connect --innodb_initialized
docker compose run --rm --no-deps laravel.test php -v
```

I nomi `MYSQL_*` nel servizio MariaDB sono quelli usati dallo stub Sail e permettono anche `sail mariadb`; il server rimane MariaDB. Attendere lo stato healthy. Verificare che PHP sia la patch stabile corrente di 8.5, confrontandola con [PHP.net](https://www.php.net/releases/index.php?json&version=8.5). Non procedere con una patch precedente a quella di sicurezza.

Per il trasferimento definitivo, mettere l’app in manutenzione e sospendere eventuali cron/sincronizzazioni che scrivono nello stesso DB. Lasciare completare le richieste in corso; bloccare poi le scritture sul MySQL sorgente prima del dump. Il blocco vale per l’intero container: verificare prima che non ospiti altre applicazioni attive.

```bash
docker compose exec -T laravel.test php artisan down --retry=60
docker exec "$NETVIBE_MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "SET GLOBAL super_read_only=ON"'
docker exec "$NETVIBE_MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF --column-statistics=0 --skip-add-locks "$1"' sh "$NETVIBE_DB" > "$NETVIBE_BACKUP/database.sql"
docker compose exec -T mariadb sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb -uroot "$MYSQL_DATABASE"' < "$NETVIBE_BACKUP/database.sql"
```

Controllare l’esito di ciascun comando e fermarsi al primo errore. Importare solo nel nuovo database MariaDB: il dump sostituisce le tabelle della destinazione. Se si effettua una prova preliminare mentre MySQL è scrivibile, ripetere il dump finale a scritture bloccate e importarlo prima del passaggio.

Prima di collegare l’app, confrontare sorgente e destinazione in sola lettura:

- Stesse tabelle e stesso numero esatto di righe per tabella (`COUNT(*)`, non le stime di `information_schema.TABLES`); confrontare anche i valori dei record, inclusi utenti, associazioni e credenziali cifrate.
- Stessi indici e chiavi uniche (`information_schema.STATISTICS`), stesse foreign key e azioni (`KEY_COLUMN_USAGE` e `REFERENTIAL_CONSTRAINTS`); verificare l’assenza di riferimenti orfani anche per le chiavi composte. Il dump disattiva temporaneamente i controlli FK e riattivarli non convalida retroattivamente i dati.
- Colonne JSON valide con `JSON_VALID`; MariaDB rappresenta `JSON` come `LONGTEXT` con validazione. Non sostituire indiscriminatamente tipi o collation nel dump. Su questo schema `utf8mb4_unicode_ci` è compatibile.

## Collegamento dell’app e review

Modificare **solo** `DB_CONNECTION=mariadb` e `DB_HOST=mariadb` nel `.env` esistente; conservare nome DB, credenziali e chiave applicativa. Se è configurato `DB_URL`, allinearlo esplicitamente perché può prevalere sui singoli campi.

```bash
docker compose run --rm --no-deps laravel.test composer install --no-interaction --prefer-dist
docker compose up -d laravel.test
docker compose exec -T --user sail laravel.test php artisan config:clear
docker compose exec -T --user sail laravel.test php artisan up
```

La migrazione dello stack non aggiunge nuove migrazioni applicative e non modifica gli asset. Per altri aggiornamenti concomitanti applicare solo le migrazioni e le build realmente necessarie.

Verificare la pagina di login e presentare la review al Product Owner: accesso con account esistente, aziende/sedi, ricerca IP, subnet con ordinamento hostname/MAC, osservazioni integrate, riconciliazione e rack. Dopo la convalida del trasferimento fermare il container MySQL conservandolo con il volume. Nessuna sincronizzazione remota è necessaria per verificare i dati importati.

**Soltanto dopo l’approvazione manuale**, eseguire i controlli in `.env.testing` con `sail_test` sul database `testing`; mai sul DB di sviluppo. La CI usa un container MariaDB indipendente. `DatabaseIsolationTest` controlla motore MariaDB e divieto di accesso al database applicativo. La suite esistente copre persistenza, isolamento, transazioni, ricerca, JSON, ordinamento e associazioni; `MikrotikSyncTest::test_observed_values_are_searchable_and_sorted_across_pages_without_documenting_them` copre la query corretta per hostname/MAC. `composer ci:check` comprende build e TypeScript.

L’installazione su DB vuoto si verifica con le migrazioni sul solo `testing`, dopo approvazione. Verificare anche il rollback della migrazione del catalogo sul DB isolato, senza eseguire rollback sullo sviluppo.

## Rollback del database

Il database MySQL conservato fotografa il momento del dump. **Le scritture successive su MariaDB non sono nel vecchio MySQL**: prima del ritorno salvare anche MariaDB e concordare come conservare tali modifiche. Non riaprire il vecchio DB fingendo di averle trasferite.

Per un rollback prima di nuove scritture, o dopo aver risolto questa differenza:

```bash
docker compose exec -T laravel.test php artisan down --retry=60
docker compose exec -T mariadb sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mariadb-dump -uroot --single-transaction --quick --hex-blob "$MYSQL_DATABASE"' > "$NETVIBE_BACKUP/mariadb-before-rollback.sql"
docker start "$NETVIBE_MYSQL"
docker exec "$NETVIBE_MYSQL" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "SET GLOBAL super_read_only=OFF; SET GLOBAL read_only=OFF"'
cp "$NETVIBE_BACKUP/env.mysql" .env
docker compose exec -T --user sail laravel.test php artisan config:clear
docker compose restart laravel.test
docker compose exec -T --user sail laravel.test php artisan up
```

Il container MySQL originale mantiene nome DNS e rete Compose. Questo rollback conserva PHP 8.5 e usa la connessione Laravel `mysql` già presente. Se serve tornare anche a PHP 8.4/codice precedente, salvare il lavoro, ripristinare la revisione registrata con il suo Compose e lockfile, quindi eseguire `composer install` nel runtime precedente e ricreare soltanto il servizio applicativo. Non riutilizzare il lockfile aggiornato con PHP 8.4.

## Differenze corrette

- La query di ordinamento delle osservazioni usa `JSON_EXTRACT` / `JSON_UNQUOTE` invece degli operatori MySQL `->` / `->>`; conserva la distinzione fra stringhe JSON, null e valori vuoti.
- Le colonne testuali di `JSON_TABLE` dichiarano `utf8mb4_unicode_ci`, come le colonne persistenti: il default MariaDB `utf8mb4_uca1400_ai_ci` causava un errore di collation nelle viste subnet e nella ricerca dei connettori.
- Nella ricerca IP, il ramo delle osservazioni dichiara `CAST(NULL AS UNSIGNED)` per preservare il tipo numerico degli ID documentati nella `UNION`.
- Le fixture UniFi usano UUID validi: sui database creati da zero il driver MariaDB crea colonne UUID native.
- Il `down()` della migrazione del catalogo usa `DROP CONSTRAINT`, supportato da MariaDB; lo schema prodotto da `up()` rimane invariato.
- `JSON_VALUE`, `JSON_TABLE`, `INET_ATON`, `INET_NTOA` e `ROW_NUMBER` restano nelle query esistenti: non sono state introdotte astrazioni di compatibilità.

Riferimenti: [JSON MariaDB](https://mariadb.com/docs/server/reference/data-types/string-data-types/json), [ALTER TABLE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table), [supporto LTS](https://mariadb.org/about/#maintenance-policy).
