# NetVibe

NetVibe è un IPAM IPv4 per MSP, utilizzabile senza integrazioni. Gestisce aziende e sedi, VLAN facoltative, subnet, indirizzi documentati con stato e tag, intervalli esclusi dai suggerimenti e riepiloghi subnet. Include la tabella virtuale paginata degli IP utilizzabili, il suggerimento del primo IP disponibile, la ricerca aziendale e le anteprime delle cancellazioni.

- [Specifica canonica](docs/specification/NETVIBE_SPEC.md)
- [Indice documentazione e baseline tecnica in bozza](docs/README.md)
- [Regole di sviluppo e approvazione](AGENTS.md)

## Base installata

Starter [laravel/react-starter-kit](https://github.com/laravel/react-starter-kit/tree/dbd4817d2d2936be14bc1ca7c94692fcef20b6b1), commit `dbd4817d2d2936be14bc1ca7c94692fcef20b6b1`. I lockfile fissano le dipendenze risolte: Laravel 13, React 19, Inertia 3, Tailwind 4, shadcn/ui, Fortify e PHPUnit. La build resta quella ufficiale Vite Plus; Wayfinder genera le funzioni per le route.

Sail esegue solo PHP 8.4 e MySQL 8.4. Gli asset sono statici, SSR è disabilitato e la coda è sincrona: non servono processi Node, Redis o worker permanenti. Autenticazione e impostazioni personali restano disponibili, inclusa la verifica email; la registrazione pubblica è disabilitata.

## Primo avvio con Docker

Sono necessari Docker e Docker Compose. Dalla radice del repository, solo se `.env` non esiste:

```bash
cp .env.example .env
```

Prima di avviare, imposta in `.env` `APP_URL` con l’indirizzo usato dal browser e, se necessario, cambia `APP_PORT` (predefinita: `8006`). Su Linux esegui il setup con un utente non root proprietario del checkout e usa il suo UID/GID per `WWWUSER` e `WWWGROUP`. Non usare queste credenziali di esempio in produzione.

```bash
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 \
  -u "$(id -u):$(id -g)" -v "$PWD:/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install --no-interaction
./vendor/bin/sail up -d
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

Il database di sviluppo `netvibe` rimane nel volume `sail-mysql` del progetto Compose. Non usare `down -v`, `migrate:fresh`, reset o reseeding sull’istanza di revisione. Non lanciare `composer setup` su dati esistenti: lo script originale rigenera la chiave applicativa.

## Test isolati — solo dopo approvazione manuale

Sail crea `testing` sullo stesso MySQL. `docker/mysql/create-test-user.sql` crea `sail_test`, autorizzato soltanto su `testing`; lo sviluppo usa `sail` su `netvibe`. MySQL non espone porte sull’host.

`.env.testing` contiene esclusivamente valori di test. PHPUnit forza database, connessione e credenziali di test; `Tests\TestCase` blocca l’avvio prima di `RefreshDatabase` se la configurazione è in cache o non corrisponde al database/utente previsto. Il test di isolamento controlla anche che MySQL neghi l’accesso a `netvibe`.

Dopo l’approvazione del Product Owner:

```bash
./vendor/bin/sail artisan test tests/Feature/DatabaseIsolationTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/DashboardTest.php tests/Feature/SuperadminCommandTest.php
```

Se il controllo segnala configurazione in cache, fermati e rimuovi esplicitamente la cache con `./vendor/bin/sail artisan config:clear`, poi ricontrolla la destinazione. Gli script SQL di inizializzazione vengono eseguiti solo alla creazione del volume: non cancellare un volume esistente per applicarli.

La CI esegue Pint, build, TypeScript, test frontend e test mirati di autenticazione e IPAM su un MySQL temporaneo separato. Nessun E2E, coverage gate o analisi PHPStan aggiuntiva nella CI.

## Strumenti per gli agenti

Ponytail e shadcn/ui sono in `.agents/skills`; Boost è una dipendenza dev con le skills selezionate dal pacchetto per lo stack installato. `.codex/config.toml` avvia il server MCP Boost tramite Sail. Le nuove skills/configurazioni sono disponibili ai successivi avvii dell’agente.

Per aggiornare le sole risorse Boost, dopo aver verificato le opzioni della versione installata:

```bash
./vendor/bin/sail artisan boost:install --mcp --skills --no-interaction
```

`boost.json` mantiene `guidelines: false`. Non eseguire installazioni delle guidelines o comandi che riscrivano `AGENTS.md`: i file protetti richiedono l’approvazione specifica del Product Owner. Anche il formatter frontend esclude i percorsi protetti.
