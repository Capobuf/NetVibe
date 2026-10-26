# NetVibe — Baseline tecnica (BOZZA, non approvata)

**Stato: proposta di progettazione tecnica, 26 ottobre 2026.** Il contenuto di questo file **non autorizza l'implementazione** delle proposte. Le decisioni funzionali già approvate e il flusso obbligatorio per modificarle sono in [NETVIBE_SPEC.md](NETVIBE_SPEC.md); le regole per gli agenti sono in [AGENTS.md](../../AGENTS.md). Eventuali approvazioni devono essere esplicite e riferirsi alle proposte interessate.

## 1. Tecnologie confermate e ipotesi da approvare

**Già deciso:** backend Laravel, frontend React/Inertia, MySQL, UI shadcn/ui + Tailwind, sviluppo Docker, applicazione utilizzabile senza integrazioni, IPv4 soltanto. Non sono obbligatori in produzione Node, Redis, worker permanenti, SSR, probe, VPN o SNMP.

**Proposta:** partire dallo starter kit ufficiale Laravel per React, con Laravel 13/PHP 8.3+, React 19, Inertia 3, TypeScript, Tailwind CSS 4, shadcn/ui, autenticazione Laravel Fortify, Vite, Pest e Pint. **Verificare le versioni e compatibilità attuali al momento dello starter**, prima di rendere vincolante questa scelta; non importare Filament o vecchi componenti perché presenti nella precedente codebase.

**Da verificare prima dello sviluppo ampio:** build frontend senza Node permanente in produzione; deploy PHP-FPM/MySQL non Docker su hosting rappresentativo; estensioni PHP e requisiti cron/HTTPS concretamente necessari.

## 2. Design proposto: monolite Laravel leggero

Un'applicazione Laravel e un database. Preferire le convenzioni del framework, senza introdurre un framework di plugin o microservizi:

- `app/Models/`: record e relazioni realmente necessari.
- `app/Http/`, `app/Policies/`: endpoint, validazione e autorizzazioni.
- `app/Actions/Ipam/`: operazioni IPAM quando richiedono regole condivise.
- `app/Support/Ipam/`: calcoli puri IPv4/CIDR/intervalli.
- `resources/js/pages/`, `resources/js/components/`: interfaccia Inertia/React con componenti shadcn/ui già presenti.
- `app/Integrations/<Provider>/`: creare le cartelle dei provider **soltanto quando approvati e implementati**.

**Confine da mantenere:** l'IPAM non dipende da classi di MikroTik, UniFi, Snipe-IT o Zabbix. Le integrazioni leggono le API esterne, traducono i dati nel necessario formato interno e li rendono disponibili al servizio applicativo di acquisizione, senza aggirare le regole IPAM. L'eventuale contratto comune va definito quando si realizza **la prima integrazione**, non prima. Evitare architetture hexagonal/CQRS/DDD applicate indiscriminatamente, event bus e interfacce preventive.

## 3. IPAM: punti di implementazione da decidere prima delle issue

Questi punti sono **proposte tecniche**, non nuovi requisiti funzionali:

1. Rappresentazione tecnica della sede: registrare una sede reale nel database anche quando la UI mostra soltanto l'azienda; non imporre una VLAN predefinita. Aggiungendo una seconda sede, la prima viene nominata senza spostare i dati.
2. Normalizzazione IPv4: scegliere una rappresentazione MySQL che permetta ordinamento numerico, verifica di CIDR e sovrapposizioni, paginazione virtuale degli IP e calcolo efficiente dei contatori. Non copiare tipi `inet/cidr/macaddr` propri di PostgreSQL.
3. Unicità e simultaneità: vincolo database sull'IP documentato nella subnet e sull'ID VLAN nella sede; gestire anche l'inserimento concorrente di subnet sovrapposte, non soltanto un controllo preventivo dalla UI.
4. Indirizzi virtuali: visualizzare indirizzi liberi a pagine senza generare tutte le righe nel database; gestire correttamente /31 e /32.
5. Intervalli esclusi: conservarli come intervalli, unirli quando sovrapposti dopo avviso e consentire rimozioni parziali senza cancellare IP documentati.
6. Metadati e cancellazioni: stati globali, tag aziendali, campi riepilogo locali alla subnet; anteprima dell'impatto reale e conferma esplicita delle cancellazioni.
7. Fonti future: distinguere documentazione, configurazione esplicita acquisita e osservazioni temporanee (A03), senza forzare record automatici documentati né modificare dati manuali arbitrariamente.
8. Autorizzazioni: prima delle UI multiutente definire il minimo modello di accesso per azienda/sede e applicarlo sul backend.
9. A02: approvare prima i flussi e la presentazione di selezione azienda/sede, subnet, tabella IP, creazione rapida, avvisi e cancellazioni; non imporre una vista modellata sulle tabelle interne.

**Questione aperta distinta:** con integrazioni attive bisogna chiarire come osservazioni, pool DHCP e freschezza dei dati influenzano suggerimenti e disponibilità, preservando le regole standalone di A01. Non risolverla con un motore generalizzato di riconciliazione.

## 4. Test e sviluppo

Le regole **già approvate** su test, Docker e ordine delle approvazioni sono in A14 e `AGENTS.md`. Operativamente:

- database MySQL di test distinto, mai confuso con il database di sviluppo navigabile;
- niente browser E2E, suite ridondanti o obiettivi obbligatori di copertura;
- test piccoli per rischi reali della singola issue, eseguiti dopo la verifica manuale del Product Owner;
- aggiornare l'istanza Docker dopo ogni iterazione pertinente, senza rebuild superflui;
- CI iniziale minima e ampliabile soltanto quando necessario.

## 5. GitHub e storico

Repository selezionato: `ShitWRKS/NetVibe`. Si conserva `main` e tutta la storia Git raggiungibile; il codice legacy non è fonte progettuale. Un commit normale che elimina il codice precedente mantiene la storia di `main`. Prima di eliminare branch storici con commit non presenti su `main`, conservare i relativi riferimenti Git (ad esempio tag), verificandoli.

**Pulizia/reset della codebase, installazione dello starter e cancellazione dei branch sono attività separate e non sono autorizzate dal solo inserimento della specifica su GitHub.**
