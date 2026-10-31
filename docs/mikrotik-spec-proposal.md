# Proposta non applicata per A04 e A04.1

**Stato: da approvare specificamente dal Product Owner. Nessuna modifica applicata alla specifica.**

File interessato: `docs/specification/NETVIBE_SPEC.md`. Solo i punti A04/A04.1 elencati sotto. La [issue 57](https://github.com/ShitWRKS/NetVibe/issues/57) decide la connessione per dispositivo, mentre A04 descrive ancora il gateway per sede. Senza aggiornamento, la specifica resta divergente dal flusso richiesto nella issue.

## Testo proposto

### A04 titolo e Scopo

**Prima — titolo:** `## A04 — Integrazione MikroTik: gateway RouterOS`

**Dopo — titolo:** `## A04 — Integrazione MikroTik: dispositivi RouterOS`

**Prima — intero paragrafo Scopo:**

> L’utente configura la connessione al gateway MikroTik per una sede. Da quel momento NetVibe acquisisce automaticamente, in sola lettura, le informazioni supportate dal dispositivo e le utilizza per arricchire IPAM e topologia senza importazioni manuali ripetitive.

**Dopo — sostituzione del paragrafo:**

> L’utente configura una connessione REST nel dettaglio di ciascun dispositivo NetVibe con produttore MikroTik, indipendentemente dal modello o dal ruolo di router/switch. La connessione appartiene all’ID del dispositivo e al suo contesto di sede; l’indirizzo tecnico HTTPS può differire dagli IPv4 documentati e non crea né modifica dati IPAM. La prima slice consente salvataggio, verifica e ricognizione manuale in sola lettura, senza importazione, sincronizzazione o aggiornamento di dispositivi, interfacce, reti, indirizzi, MAC, commenti, cavi o rack. Il successivo arricchimento automatico previsto da A03 richiede le decisioni della seconda slice.

### A04 Informazioni da validare sui payload REST reali

Sostituire soltanto il punto:

- **Prima:** `Possibilità di eseguire un ping dal router verso indirizzi della LAN, come operazione diagnostica controllata.`
- **Dopo:** `Ricognizione limitata a GET su menu documentati e predefiniti; nessun ping attivo, script, export o accesso ai menu segreti.`

### A04 Connessione, sicurezza e automazione

Sostituire soltanto i seguenti punti:

- **Prima:** `Credenziali dedicate e privilegi minimi; nessuna modifica configurativa via NetVibe.`
- **Dopo:** `Credenziali per dispositivo cifrate lato server, gestione riservata al Superadmin, password mai restituita al browser. Utente RouterOS dedicato con privilegi read, api e rest-api; nessuna modifica configurativa via NetVibe. La rimozione della connessione conserva dispositivo e IPAM; l’eliminazione esplicita del dispositivo elimina le credenziali. Un cambio produttore non deve nascondere né eliminare silenziosamente una connessione esistente.`

- **Prima:** `Certificati self-signed supportabili tramite configurazione esplicita e indicazione del rischio.`
- **Dopo:** `Verifica TLS attiva per impostazione predefinita; accettazione di certificati non verificati soltanto tramite scelta esplicita con indicazione del rischio.`

- **Prima:** `Sincronizzazione automatica con cron leggero ove disponibile, più refresh puntuale manuale; niente worker permanente come prerequisito.`
- **Dopo:** `Nella prima slice solo verifica e analisi manuali, senza richieste al render. Sincronizzazione e relativa automazione appartengono alla seconda slice, dopo le decisioni del PO; nessun worker permanente come prerequisito.`

Gli altri due punti della sezione restano invariati.

### A04 Decisioni aperte

Sostituire soltanto il punto:

- **Prima:** `Livello effettivo di raggiungibilità da usare nella UI (ping, ARP, DHCP) e denominazioni corrette.`
- **Dopo:** `Distinzione in UI fra esito della connessione REST e osservazioni ARP/DHCP, senza dedurre raggiungibilità o disponibilità di un IP dalla sola presenza o assenza di osservazioni.`

### A04.1 Scopo

**Prima — intero paragrafo:**

> Supportare switch MikroTik con RouterOS tramite endpoint REST indipendenti; non includere SwOS via SNMP. Uno switch scoperto da LLDP/MNDP può restare visibile senza endpoint API configurato.

**Dopo — sostituzione del paragrafo:**

> Gli switch MikroTik con RouterOS usano la stessa connessione per dispositivo definita in A04, senza una configurazione separata per sede o tipo di apparato. SwOS via SNMP resta escluso. Nella prima slice la neighbor discovery produce soltanto un riepilogo di ricognizione; la successiva rappresentazione di uno switch scoperto senza endpoint API configurato resta da definire nella seconda slice.

Titolo, Ipotesi operativa da testare e Approfondimenti di A04.1 restano invariati e mantengono il loro stato esplorativo.

## Impatto e incongruenze

- **IPAM:** invarianti A01 invariati; endpoint REST escluso da assegnazioni e contatori. Nessuna fusione di VLAN con ID uguale in contesti distinti.
- **UI/UX:** tab MikroTik nel dispositivo, disponibile per produttore effettivo; nessuna pagina di integrazione globale/per sede. La modifica al testo non costituisce approvazione della UI implementata.
- **Database:** la slice introduce `mikrotik_connections` con riferimento univoco al dispositivo e cancellazione in cascata. Nessuna migrazione o riscrittura degli IPv4 documentati. L’approvazione di questa proposta documentale non richiede ulteriori migrazioni rispetto all’implementazione della issue.
- **Isolamento e API:** binding azienda/sede/dispositivo e gestione Superadmin; HTTP esterno solo GET HTTPS. Nessuna scrittura sul router o modifica del contratto IPAM.
- **Snipe-IT e catalogo:** il cambio produttore viene sospeso quando esiste una connessione; non viene migrato o eliminato un segreto per effetto della sincronizzazione. Nome, seriale e altri campi restano regolati dal connettore esistente.
- **Test e workflow:** copertura mirata per autorizzazione, SSRF/TLS, credenziali, indipendenza IPAM e protezione del produttore; esecuzione solo dopo revisione manuale come A14.
- **Contraddizioni residue:** A03 descrive ancora la futura acquisizione automatica e A04.1 conserva un’ipotesi NAT/discovery; non diventano funzionalità della prima slice. Il nuovo Scopo circoscrive esplicitamente la fase. Le decisioni sulla seconda slice restano aperte e nessun’altra sezione viene modificata.
- **Incertezze:** raggiungibilità del router reale, varianti hardware/RouterOS, interpretazione dei commenti, collisioni L2 e corrispondenze tra fonti richiedono collaudo e decisioni puntuali. Nessuna compatibilità universale è dichiarata.

## Alternativa minima

Sostituire solo il paragrafo Scopo di A04 e quello di A04.1 renderebbe esplicita l’associazione al dispositivo, ma lascerebbe ping e sincronizzazione automatica formulati senza distinzione di fase. Non è equivalente per chiarezza alla proposta sopra. In attesa dell’approvazione, la soluzione minima corretta è conservare la specifica e mantenere esplicita la divergenza con la decisione della issue.

Per applicare la proposta serve approvazione esplicita dei soli cambi elencati in questo documento, come richiesto da `AGENTS.md` §1. Nessuna approvazione è presunta dall’assegnazione della issue o dalla revisione dell’implementazione.
