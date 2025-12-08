# NetVibe

NetVibe is an IPAM / asset management project with a Filament-based admin UI.

This repository contains the application and developer documentation. See `SETUP.md` for more detailed environment and local development instructions.

---

## 🧪 Eseguire i test (in sicurezza)

Attenzione: la suite di test usa `RefreshDatabase` che può ricreare/dropare le tabelle del database. Non eseguire i test con il database di produzione o qualunque database che contenga dati reali.

Abbiamo aggiunto due protezioni/aiuti per eseguire i test in modo sicuro durante lo sviluppo:

- Un controllo di sicurezza nel bootstrap dei test: `tests/Pest.php` rifiuta di eseguire la suite di test a meno che `DB_DATABASE` sia `netvibe-test`.
- Uno script helper `scripts/run-tests.sh` per avviare i test dal container `netvibe-web` in modo sicuro e ripetibile. Nota: lo script ora forza sempre `DB_DATABASE=netvibe-test` per il processo di test (ignora eventuali valori env passati) — questo evita rischi accidentali causati da variabili d'ambiente non intenzionali.

Esempi d'uso di `scripts/run-tests.sh`:

```bash
# Mostra l'help
./scripts/run-tests.sh --help

# Esegue l'intera suite (non-interattivo — lo script forzerà l'uso del DB di test `netvibe-test`)
./scripts/run-tests.sh

# Esegue un singolo file di test (non-interattivo)
./scripts/run-tests.sh --pattern 'tests/Unit/DeviceModelCreateRedirectTest.php' --yes

# Esegue tutti i test Unit (non-interattivo)
./scripts/run-tests.sh --pattern Unit --yes
```

Il file `SETUP.md` contiene inoltre dettagli aggiuntivi sui flussi per creare un database di test e usare Docker Compose durante lo sviluppo.

---

Se hai bisogno, posso aggiungere anche un task `make test` o registrare lo script come comoda scorciatoia nel `composer.json` (es. `composer test:docker`). Fammi sapere quale preferisci.

---

## Stato UI Filament

- Rimosse le vecchie viste Livewire con Tailwind (rack visualizer e rack port modal) a favore di componenti nativi Filament; eliminata la view legacy `filament/clusters/racks/rack-view.blade.php`.
- Eliminata l’iniezione CSS custom nel topbar: layout ora interamente gestito da Filament; tenant switcher semplificato usando componenti Filament.
- DeviceResource: la versione “Infrastructure” è nascosta dal menu e ha slug diverso (`infra-devices`) per evitare collisioni; UX unica sotto “Racks & Devices”.
- Tabella Device (Racks) include azione “Test Connection” (dispatch del job `check_connectivity`), colonne per Mgmt IP, stato auth, last seen.
- Form Device (Racks) include monitoraggio, credential profile, driver/auth manuale, management IP; quick-create rack e hardware model sono mantenuti.

## Lacune note

- Mancano widget/header di stato device (uptime, ultimo job) e tab Activity/Interfaces avanzate previste dall’epic.
- ScanAgent “Tuning” espone solo log level/polling base; parametri avanzati non ancora modellati.
- DiscoverySettings non espone ancora tutti i campi roadmap (job_timeout, link_discovery_enabled, offline_threshold_days).
- UI rack è tabellare (nessun visualizer U-based); assenti azioni “install/mount” sulla vista rack.
