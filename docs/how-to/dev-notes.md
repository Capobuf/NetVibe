# Dev notes (session)

- **Stack avvio**: build asset con `npm ci && npm run build`, build immagine `netvibe-app`, avvio profilo `dev` (`netvibe-web-dev`, `netvibe-worker-dev`, `netvibe-db`, `netvibe-redis`). Remote agent non avviato.
- **Problema public mancante**: `public/index.php` e `.htaccess` ricreati; `public/uploads/.gitignore` aggiunto.
- **Mount vendor**: nei profili `dev` il compose monta solo codice (non `vendor`/`node_modules`) per usare le dipendenze dell’immagine.
- **Permessi cache/view**: eseguito `chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache` nei container web/worker.
- **DB access denied**: creato utente e grant su `netvibe` nel `netvibe-db` (root/secret), quindi funziona.
- **Wizard bypass**: eseguito `php artisan migrate --force`, creato tenant di default (Customer “Default Customer”, Location “Default Location”), segnato `SystemStateSettings` installed/configured=true e impostato `general.app_url` se vuota.
- **Credenziali admin**: `admin@netvibe.local` / `NetvibeAdmin#123` (da cambiare appena possibile).
