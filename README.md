# NetVibe

NetVibe is an IPAM / asset management project with a Filament-based admin UI.

This repository contains the application and developer documentation. See `SETUP.md` for more detailed environment and local development instructions.

---

## 🧪 Eseguire i test (in sicurezza)

Attenzione: la suite di test usa `RefreshDatabase` che può ricreare/dropare le tabelle del database. Non eseguire i test con il database di produzione o qualunque database che contenga dati reali.

Abbiamo aggiunto due protezioni/aiuti per eseguire i test in modo sicuro durante lo sviluppo:

- Un controllo di sicurezza nel bootstrap dei test: `tests/Pest.php` rifiuta di eseguire la suite di test a meno che `DB_DATABASE` sia `netvibe-test`.
- Uno script helper `scripts/run-tests.sh` per avviare i test dal container `netvibe-web` in modo sicuro e ripetibile.

Esempi d'uso di `scripts/run-tests.sh`:

```bash
# Mostra l'help
./scripts/run-tests.sh --help

# Esegue l'intera suite (interattivo — chiederà conferma se DB non corrisponde a netvibe-test)
./scripts/run-tests.sh

# Esegue un singolo file di test (non-interattivo)
./scripts/run-tests.sh --pattern 'tests/Unit/DeviceModelCreateRedirectTest.php' --yes

# Esegue tutti i test Unit (non-interattivo)
./scripts/run-tests.sh --pattern Unit --yes
```

Il file `SETUP.md` contiene inoltre dettagli aggiuntivi sui flussi per creare un database di test e usare Docker Compose durante lo sviluppo.

---

Se hai bisogno, posso aggiungere anche un task `make test` o registrare lo script come comoda scorciatoia nel `composer.json` (es. `composer test:docker`). Fammi sapere quale preferisci.
