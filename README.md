# NetVibe

NetVibe è un IPAM IPv4 per MSP, utilizzabile senza integrazioni. Gestisce aziende e sedi, VLAN facoltative, subnet, indirizzi documentati con stato e tag, intervalli esclusi dai suggerimenti e riepiloghi subnet. Include la tabella virtuale paginata degli IP utilizzabili, il suggerimento del primo IP disponibile, la ricerca aziendale e le anteprime delle cancellazioni.

- [Specifica canonica](docs/specification/NETVIBE_SPEC.md)
- [Indice documentazione e baseline tecnica in bozza](docs/README.md)
- [Regole di sviluppo e approvazione](AGENTS.md)

## Base installata

Starter [laravel/react-starter-kit](https://github.com/laravel/react-starter-kit/tree/dbd4817d2d2936be14bc1ca7c94692fcef20b6b1), commit `dbd4817d2d2936be14bc1ca7c94692fcef20b6b1`. I lockfile fissano le dipendenze risolte: Laravel 13, React 19, Inertia 3, Tailwind 4, shadcn/ui, Fortify e PHPUnit. La build resta quella ufficiale Vite Plus; Wayfinder genera le funzioni per le route.

Sail esegue solo PHP 8.5 (patch di sicurezza corrente, almeno 8.5.12) e MariaDB 11.4 LTS. Gli asset sono statici, SSR è disabilitato e la coda è sincrona: non servono processi Node, Redis o worker permanenti. Autenticazione e impostazioni personali restano disponibili, inclusa la verifica email; la registrazione pubblica è disabilitata.

## Primo avvio con Docker

Sono necessari Docker e Docker Compose. Dalla radice del repository, solo se `.env` non esiste:

```bash
cp .env.example .env
```

Prima di avviare, imposta in `.env` `APP_URL` con l’indirizzo usato dal browser e, se necessario, cambia `APP_PORT` (predefinita: `8006`). Su Linux esegui il setup con un utente non root proprietario del checkout e usa il suo UID/GID per `WWWUSER` e `WWWGROUP`. Non usare queste credenziali di esempio in produzione.

```bash
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 \
  -u "$(id -u):$(id -g)" -v "$PWD:/var/www/html" -w /var/www/html \
  composer:2 composer install --no-interaction --no-scripts
./vendor/bin/sail up -d
./vendor/bin/sail composer install --no-interaction
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan netvibe:superadmin
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

`key:generate` serve solo alla prima installazione: non rigenerare la chiave di un’istanza con dati. PHP deve poter scrivere in `storage` e `bootstrap/cache`; la build deve poter scrivere nelle directory generate e in `node_modules`. Evita di alternare root e l’utente Sail per installazioni e build.

Apri l’`APP_URL` configurato e accedi con l’account superadmin predisposto dal comando. Dopo l’accesso si apre l’elenco delle aziende accessibili: tutte per i Superadmin, soltanto quelle associate per gli utenti aziendali.

Senza impostazioni SMTP salvate, le email di sviluppo sono scritte in `storage/logs/laravel.log` (`MAIL_MAILER=log`): i link di verifica email e recupero password si trovano nel log. Le passkey richiedono un contesto sicuro supportato dal browser (HTTPS oppure localhost).

Da **Amministrazione → Email** il Superadmin configura il server SMTP globale, il trasporto (nessuna cifratura, STARTTLS obbligatorio o SSL/TLS), le credenziali facoltative e il mittente. Dopo il salvataggio tutte le email Laravel, inclusi inviti, verifica e recupero password, usano queste impostazioni anche con la configurazione applicativa in cache. La password è cifrata con la chiave applicativa e non viene restituita al browser: lasciare vuoto il campo la conserva; inserirne una nuova la sostituisce. La prova usa i dati salvati ed è limitata a tre tentativi al minuto per Superadmin. Il successo indica l’accettazione del server SMTP, non la consegna nella casella del destinatario.

## Account superadmin

`SUPERADMIN_EMAIL` e `SUPERADMIN_PASSWORD` nel `.env` alimentano il comando `netvibe:superadmin` tramite `config/superadmin.php`, senza credenziali predefinite nel codice. Nell'ambiente di sviluppo entrambi valgono `admin@netvibe.test`: il login usa l'email come identificativo. Questi valori semplici sono esclusivamente per sviluppo.

Il comando crea un account con email già verificata e password salvata come hash. Alle esecuzioni successive aggiorna il primo Superadmin anche se cambia l'email; rifiuta email già appartenenti ad altri utenti. Il Gate `administer` riserva l’amministrazione globale ai Superadmin; il Gate `access-company` autorizza l’IPAM e la gestione sedi per le aziende associate all’utente.

Da **Amministrazione → Utenti** i Superadmin invitano altri Superadmin e gestiscono gli account. Da **Azienda → Impostazioni → Utenti** invitano utenti aziendali: un’email esistente viene associata senza duplicare l’account. Gli inviti nuovi usano i token del reset password Fortify; dopo aver impostato la password, l’utente accede e completa la verifica email esistente. Il nome iniziale coincide con l’email ed è modificabile dal profilo. Se il link scade o l’invio fallisce, è disponibile «Password dimenticata?» nella pagina di accesso.

«Elimina utente» cancella l’intero account e tutte le associazioni, anche dalla pagina aziendale, conservando i dati IPAM. L’ultimo Superadmin è protetto anche dall’autoeliminazione.

Per applicare modifiche alle credenziali nel `.env`:

```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan netvibe:superadmin
```

Il `.env` è la configurazione di provisioning, non viene consultato a ogni login. Le modifiche effettuate dal profilo restano nel database finché non si riesegue il comando, che riapplica le credenziali configurate e rinnova il token «Ricordami». Non rimuove utenti, dati o configurazioni 2FA/passkey; le sessioni già aperte restano attive.

## Riavvio e aggiornamenti

```bash
./vendor/bin/sail up -d
./vendor/bin/sail npm run build
```

Ricompila gli asset solo dopo modifiche frontend; esegui `composer install`/`npm ci` solo quando cambiano i lockfile, `artisan migrate` solo per nuove migrazioni, e ricostruisci l’immagine solo se cambia il runtime. Per fermare senza cancellare dati usa `./vendor/bin/sail stop`.

Il database di sviluppo `netvibe` rimane nel volume `sail-mariadb` del progetto Compose. Non usare `down -v`, `migrate:fresh`, reset o reseeding sull’istanza di revisione. Non lanciare `composer setup` su dati esistenti: lo script originale rigenera la chiave applicativa.

## Migrazione da MySQL e PHP 8.4

Per un’istanza esistente seguire [la procedura di migrazione e rollback](docs/migration-mariadb.md) prima di avviare il nuovo stack. Il volume MySQL non deve essere montato su MariaDB. Conservare `.env`, `APP_KEY`, database originale e backup verificato fino all’accettazione.

## Hosting PHP-FPM

Servono PHP 8.5 aggiornato (CLI e PHP-FPM coerenti), le estensioni richieste dal lockfile e MariaDB 11.4 LTS. Verificare queste versioni con il provider prima del deploy: non è ancora stato convalidato un hosting di produzione. Configurare `DB_CONNECTION=mariadb` e host/credenziali forniti dall’hosting; il driver PHP resta `pdo_mysql`. Distribuire gli asset già compilati e puntare la document root a `public`, preservando `APP_KEY` negli aggiornamenti. Docker resta uno strumento di sviluppo.

## Deploy in produzione

Dopo ogni CI verde su `main`, `.github/workflows/deploy.yml` compila asset e dipendenze senza pacchetti dev, carica con rsync una nuova release in `~/netvibe/releases/<data>-<sha>` ed esegue `deploy/activate.sh` sul server: collega `.env` e `storage` condivisi (`~/netvibe/shared`), esegue `migrate --force` e `optimize`, poi sposta atomicamente il symlink `~/htdocs/netvibe.bydot.it` (document root CloudPanel: `public`). Se le migrazioni falliscono la release resta inattiva e viene rimossa. Restano solo le ultime 5 release; i log Laravel sono giornalieri e conservati 14 giorni. Il deploy si può rilanciare a mano da **Actions → Deploy → Run workflow**.

Secret richiesti nel repository: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` (chiave privata autorizzata sul server) e `DEPLOY_KNOWN_HOSTS` (output di `ssh-keyscan` dell’host).

Rollback: `ln -sfn ~/netvibe/releases/<release-precedente> ~/htdocs/netvibe.bydot.it` (le migrazioni già applicate non vengono annullate).

## Sincronizzazione Snipe-IT e cron

Il Superadmin acquisisce l’anteprima da **Azienda → Impostazioni → Integrazioni → Snipe-IT**, conferma i dispositivi (produttori, tipologie e modelli necessari vengono creati o riutilizzati durante l’importazione) e attiva la sincronizzazione oraria. Gli asset rimandati nella prima acquisizione restano in attesa di una scelta esplicita. «Sincronizza ora» usa la stessa logica del comando `php artisan snipe-it:sync` (quest’ultimo riguarda solo le aziende già attivate).

Su hosting condiviso configura il cron dell’hosting ogni minuto, sostituendo i percorsi con quelli effettivi:

```cron
* * * * * cd /percorso/netvibe && /percorso/php artisan schedule:run >> /percorso/netvibe/storage/logs/scheduler.log 2>&1
```

Laravel avvia Snipe-IT all’inizio di ogni ora e impedisce esecuzioni sovrapposte. Non occorrono altri container, queue worker, Redis o processi Node. In sviluppo il cron dell’host può richiamare `docker compose exec -T --user sail laravel.test php artisan schedule:run` dalla directory del progetto. Una lettura incompleta o un errore API conserva l’ultima copia valida; la pagina mostra l’errore e l’ultimo aggiornamento riuscito.

## Test isolati — solo dopo approvazione manuale

`docker/mariadb/create-test-user.sql` crea il database `testing` e `sail_test`, autorizzato soltanto su `testing`; lo sviluppo usa `sail` su `netvibe`. MariaDB non espone porte sull’host.

`.env.testing` contiene esclusivamente valori di test. PHPUnit forza database, connessione e credenziali di test; `Tests\TestCase` blocca l’avvio prima di `RefreshDatabase` se la configurazione è in cache o non corrisponde al database/utente previsto. Il test di isolamento controlla anche che MariaDB neghi l’accesso a `netvibe`.

Dopo l’approvazione del Product Owner:

```bash
./vendor/bin/sail artisan test tests/Feature/DatabaseIsolationTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/DashboardTest.php tests/Feature/SuperadminCommandTest.php
```

Se il controllo segnala configurazione in cache, fermati e rimuovi esplicitamente la cache con `./vendor/bin/sail artisan config:clear`, poi ricontrolla la destinazione. Gli script SQL di inizializzazione vengono eseguiti solo alla creazione del volume: non cancellare un volume esistente per applicarli.

La CI esegue Pint, build, TypeScript, test frontend e test mirati di autenticazione e IPAM su un MariaDB temporaneo separato. Nessun E2E, coverage gate o analisi PHPStan aggiuntiva nella CI.

## Strumenti per gli agenti

Ponytail e shadcn/ui sono in `.agents/skills`; Boost è una dipendenza dev con le skills selezionate dal pacchetto per lo stack installato. `.codex/config.toml` avvia il server MCP Boost tramite Sail. Le nuove skills/configurazioni sono disponibili ai successivi avvii dell’agente.

Per aggiornare le sole risorse Boost, dopo aver verificato le opzioni della versione installata:

```bash
./vendor/bin/sail artisan boost:install --mcp --skills --no-interaction
```

`boost.json` mantiene `guidelines: false`. Non eseguire installazioni delle guidelines o comandi che riscrivano `AGENTS.md`: i file protetti richiedono l’approvazione specifica del Product Owner. Anche il formatter frontend esclude i percorsi protetti.
