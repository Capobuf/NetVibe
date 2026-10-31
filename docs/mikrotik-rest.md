# Ricognizione MikroTik per dispositivo

Implementazione della [issue 57](https://github.com/ShitWRKS/NetVibe/issues/57), decisione del PO del 31 ottobre 2026. Documento tecnico, non sostitutivo della specifica canonica. La divergenza con A04/A04.1 è descritta nella [proposta non applicata](mikrotik-spec-proposal.md).

## Connessione e confini

Una connessione appartiene all’ID del dispositivo, riconosciuto come MikroTik tramite il nome del produttore effettivamente associato, senza dipendenze dal tipo o dal modello. La tab compare nel dettaglio; gestione e letture sono riservate al Superadmin. Le route verificano la catena azienda → sede → dispositivo.

L’endpoint HTTPS non è un indirizzo documentato. Salvataggio, verifica e analisi non chiamano alcuna funzione di assegnazione IPAM. Username e password sono cifrati; il browser riceve solo username e presenza della password. Una password vuota conserva quella salvata per lo stesso indirizzo e username. Cambiare destinazione o username richiede di reinserirla, per non inviare implicitamente il segreto a un altro destinatario.

La rimozione cancella solo connessione e riepilogo. L’eliminazione guidata del dispositivo elimina anche la connessione tramite chiave esterna. Cambio modello, cambio produttore del modello, rinomina produttore e aggiornamento Snipe-IT non possono nascondere una connessione esistente: restituiscono un errore o una segnalazione, conservando le credenziali. La loro rimozione resta esplicita.

## Protocollo e sicurezza

Le REST API corrispondono ai menu CLI sotto `/rest`. GET legge i record; i menu singoli possono restituire un oggetto. Numeri e booleani arrivano anche come stringhe. `.proplist` limita i campi richiesti. La documentazione ufficiale indica HTTPS tramite `www-ssl`, Basic Auth e un utente dedicato con policy `read,api,rest-api`. Configurazione del servizio, certificato, utente e firewall spettano all’operatore: NetVibe non li modifica. [REST API ufficiale](https://manual.mikrotik.com/docs/developer-guides/rest-api/).

Il client applica una lista chiusa di 18 menu GET. Nessun percorso libero, comando POST, ping, script, export o menu segreti. TLS è verificato salvo opt-in esplicito. Sono ammessi IPv4 RFC1918 e pubblici; loopback, link-local, indirizzi riservati/condivisi, metadata e forme URL ambigue sono rifiutati. Tutti gli indirizzi A risolti sono validati e la connessione è vincolata a un indirizzo validato, mantenendo il nome TLS. Redirect e proxy d’ambiente sono disabilitati.

Limiti applicati: 10 azioni/minuto per utente sulle route; 80 richieste/minuto per endpoint; una lettura contemporanea per dispositivo; 3 secondi per connessione, 8 per richiesta, budget complessivo HTTP di 45 secondi; 2 MB per risposta, 4 MB complessivi, 5.000 record per menu. Il tempo del resolver DNS di sistema può aggiungersi al budget HTTP. Nessun retry automatico, worker, scheduler o chiamata al router durante il render.

Il supporto di questa slice è RouterOS 7 con REST disponibile. Versioni differenti vengono segnalate e interrompono la ricognizione. Un 401 è distinto da un 403; un 404 non dimostra da solo se manchi il menu, la REST API o il servizio configurato. Timeout, TLS, rete, redirect, risposte non JSON e limiti hanno messaggi controllati. Le risposte di errore remote non sono mostrate né registrate.

## Matrice di copertura ufficiale

La matrice descrive possibilità documentate, non dati di un router collaudato. La UI registra separatamente gli esiti realmente ottenuti: letto, vuoto, non disponibile, errore o non interrogato. Le colonne opzionali mostrano menu e proprietà effettivamente riscontrate. Proprietà mancanti non assumono valori predefiniti. Il riepilogo conserva conteggi e interpretazioni prudenti, non i dump.

| Configurazione o fonte | Menu e campi pertinenti | Possibile significato in NetVibe | Limite della slice |
| --- | --- | --- | --- |
| Identità e piattaforma | `/system/identity`: `name`; `/system/resource`: `version`, `board-name`, `architecture-name`, `platform` | Identificare l’apparato interrogato | Non rinomina il dispositivo né modifica il modello documentato. [Identity](https://manual.mikrotik.com/docs/cli-reference/system/identity/), [resource](https://manual.mikrotik.com/docs/cli-reference/system/resource/). |
| Interfacce generiche e fisiche | `/interface`: `name`, `type`, `mac-address`, `running`, `disabled`; `/interface/ethernet`: `default-name`, `switch` | Candidati per correlare porte documentate e fisiche | Nome/MAC non sono chiavi universali; nessuna associazione ai connettori grafici. [Interface](https://manual.mikrotik.com/docs/cli-reference/interface/), [Ethernet](https://manual.mikrotik.com/docs/cli-reference/interface/ethernet/). |
| Zero, uno o più bridge; filtering sì/no | `/interface/bridge`: `name`, `vlan-filtering`, `pvid`; `/interface/bridge/port`: `bridge`, `interface`, `pvid`, `frame-types`, `ingress-filtering`, `hw` | Contesto interno necessario a interpretare VLAN e porte | Nessuna entità Bridge. PVID non prova da solo appartenenza o egress tagged/untagged. Filtering mancante resta indeterminato. [Bridging](https://manual.mikrotik.com/docs/bridging-and-switching/). |
| VLAN su bridge o fisica, anche senza indirizzo | `/interface/vlan`: `name`, `interface`, `vlan-id`, `use-service-tag`; `/interface/bridge/vlan`: `bridge`, `vlan-ids` | Distinguere interfacce VLAN L3 da appartenenze L2 | Una riga L2 può indicare più ID o intervalli; nessuna VLAN/subnet creata. Q-in-Q e parentela annidata non vengono appiattiti. [VLAN](https://manual.mikrotik.com/docs/bridging-and-switching/vlan/), [bridge VLAN CLI](https://manual.mikrotik.com/docs/cli-reference/interface/bridge/vlan/). |
| Tagged, untagged, dinamici, liste | `/interface/bridge/vlan`: `tagged`, `untagged`, `current-tagged`, `current-untagged`, `dynamic`; `/interface/list`: `include`, `exclude`; `/interface/list/member`: `list`, `interface`, `dynamic` | Confrontare configurazione con appartenenza effettiva | Liste in tagged/untagged da RouterOS 7.17; non espandere solo i membri statici né confondere configurato e corrente. La slice registra presenza delle proprietà, senza dedurre porte finali. [Liste nella tabella VLAN](https://manual.mikrotik.com/docs/bridging-and-switching/#interface-lists-in-vlan-table), [liste CLI](https://manual.mikrotik.com/docs/cli-reference/interface/list/). |
| ID VLAN riutilizzati in contesti diversi | Parent di `/interface/vlan` e bridge di `/interface/bridge/vlan`, inclusi intervalli `vlan-ids` | Segnalare possibili collisioni rispetto all’unicità per sede di A01 | Il riepilogo conta ID ripetuti in contesti nominati diversi, senza fonderli. Non dimostra indipendenza fisica; non confronta più router o switch chip. Espansione limitata a 20.000 ID; oltre il limite resta indeterminato. |
| IPv4 su fisica, bridge, VLAN; più indirizzi per interfaccia | `/ip/address`: `address`, `network`, `interface`, `actual-interface`, `dynamic`, `disabled`, `invalid` | Candidati subnet e indirizzi configurati | Nessuna persistenza IPAM. Il campo `network` non sostituisce la normalizzazione CIDR, specialmente nei collegamenti punto-punto. [IPv4 address CLI](https://manual.mikrotik.com/docs/cli-reference/ip/address/). |
| DHCP solo su alcune reti | `/ip/dhcp-server`: `interface`, `address-pool`; `/ip/dhcp-server/network`: `address`, `gateway`, `netmask`, `dns-server`, `domain`; `/ip/pool`: `ranges`, `next-pool` | Configurazione DHCP distinta dalle subnet documentate e dalle esclusioni | Un pool non implica una VLAN; nessun intervallo escluso dedotto dal DHCP. [DHCP server](https://manual.mikrotik.com/docs/network-management/dhcp/server/), [pool CLI](https://manual.mikrotik.com/docs/cli-reference/ip/pool/). |
| Prenotazioni e lease operative | `/ip/dhcp-server/lease`: `address`, `dynamic`, `status`, `server`, `active-address`, `active-mac-address`, `active-server` | Distinguere configurazione statica da osservazione dinamica | `address` può essere anche il nome di un pool; una lease statica non implica un IPv4 fisso. Flag mancante = non classificabile. Non trasforma client in dispositivi o prenotazioni IPAM. [Lease CLI](https://manual.mikrotik.com/docs/cli-reference/ip/dhcp-server/lease/). |
| ARP, MAC table e neighbor | `/ip/arp`: `address`, `mac-address`, `interface`, `status`, `dynamic`; `/interface/bridge/host`: `on-interface`, `vid`, `dynamic`, `local`, `external`; `/ip/neighbor`: `interface`, `address`, `identity`, `platform`, `board`, `version` | Osservazioni con semantiche diverse | Assenza di record non significa IP libero; più MAC sulla porta non identificano un percorso fisico. Nessun dispositivo o cavo creato. [ARP CLI](https://manual.mikrotik.com/docs/cli-reference/ip/arp/), [bridge host CLI](https://manual.mikrotik.com/docs/cli-reference/interface/bridge/host/), [neighbor CLI](https://manual.mikrotik.com/docs/cli-reference/ip/neighbor/). |
| Commenti | `comment` nei menu di configurazione selezionati: interfacce, VLAN, bridge/porte, IP, ARP, DHCP e pool | Testo riferito all’oggetto sorgente specifico | Nessuna copia su nome, note o descrizione. Si conserva solo il conteggio nel riepilogo. La CLI documenta `comment` come proprietà comune delle liste. [CLI reference](https://manual.mikrotik.com/docs/cli-reference/#standard-list-entry-properties). |

## Switch chip e versioni non collaudate

La documentazione distingue switch che usano bridge hardware VLAN filtering da chip con tabella `/interface/ethernet/switch/vlan`. Nei secondi sono pertinenti `switch`, `vlan-id`, `ports`, e `/interface/ethernet/switch/port` con `default-vlan-id`, `vlan-mode`, `vlan-header`. QCA8337/Atheros8327, per esempio, hanno regole specifiche per access/hybrid; modelli con più chip richiedono di mantenere il contesto del chip. CRS1xx/2xx e Marvell Prestera hanno manuali dedicati. [Switch Chip Features](https://manual.mikrotik.com/docs/bridging-and-switching/switch-chip-features/).

Questi menu non vengono interrogati indiscriminatamente. Dopo la prima lettura reale, modello/versione devono essere confrontati con il manuale hardware pertinente prima di aggiungere eventuali GET alla lista chiusa. La UI li indica come non interrogati, non come assenti. Il primo collaudo non certificherà altre piattaforme o versioni; SwOS non è incluso.

## Fixture e controlli predisposti

`tests/Fixtures/mikrotik/synthetic.json` contiene esclusivamente dati sintetici derivati dalle fonti sopra: bridge multipli, filtering vero/falso/mancante, VLAN su bridge e fisica, intervalli VLAN e collisioni, liste/current members, VLAN senza indirizzo locale, più IPv4 per interfaccia, DHCP parziale, lease statiche/dinamiche/non classificabili, commenti e menu vuoti. I test coprono anche zero bridge e menu non disponibili. Non è un emulatore RouterOS.

I controlli sono in `tests/Feature/MikrotikTest.php` e `tests/Unit/MikrotikAnalysisTest.php`. Dopo l’accettazione manuale del PO sono passati i test MikroTik, di isolamento del database e le regressioni su dispositivi e sincronizzazione Snipe-IT: 45 casi complessivi. Sono passati anche TypeScript, formattazione PHP dei file interessati e build degli asset. La suite del progetto impone MySQL `testing` con utente `sail_test`, distinto dall’istanza consultabile.

## Decisioni richieste prima della seconda issue

1. Come presentare e gestire lo stesso VLAN ID in contesti L2 diversi, senza aggirare l’unicità per sede o introdurre Bridge/VRF.
2. Quali oggetti/commenti sorgente possono alimentare quali campi NetVibe, preservando dati manuali e Snipe-IT.
3. Quali evidenze bastano per correlare interfacce/porte, subnet e VLAN; cosa fare con parentela annidata, pool condivisi e sovrapposizioni non rappresentabili in A01.
4. Quali lease statiche costituiscono configurazione da documentare e quali dati restano osservazioni, con durata e freschezza esplicite.
5. Come trattare più apparati della stessa sede che descrivono la stessa rete. Nessuna precedenza o fusione automatica è introdotta qui.

## Collaudo reale

La lettura reale del 31 ottobre 2026, eseguita dopo la configurazione nell’applicazione, è documentata nel [rapporto anonimizzato](mikrotik-real-report.md): RouterOS 7.25 su RB5009UG+S+, tutti i 18 menu letti. Il rapporto conserva solo dati aggregati e modello/versione, separati da questa matrice e dalle fixture sintetiche. Il collaudo non certifica altre configurazioni, piattaforme o versioni.
