# NetVibe — Specifica funzionale e UX

*Documento di lavoro v0.1 · 26 ottobre 2026 · Bozza per definizione del prodotto*

Scopo: raccogliere il perimetro di NetVibe e suddividere gli approfondimenti in aree autonome. La prima priorità di progettazione è l’IPAM senza integrazioni, sia nella logica sia nell’esperienza d’uso. Le altre sezioni sono schede di lavoro, non istruzioni di implementazione.
Legenda: «Confermato» indica una decisione espressa nella discussione; «Proposta» indica un orientamento da validare; «Da definire» indica una domanda ancora aperta. Il documento è evolutivo: non autorizza automaticamente lo sviluppo delle funzionalità elencate.


## 0. Identità del prodotto, principi e confini

### 0.1 Che cos’è NetVibe

NetVibe è un IPAM leggero rivolto principalmente a MSP che gestiscono più clienti e più sedi. Deve funzionare anche senza integrazioni. Quando sono presenti, le fonti esterne alimentano automaticamente la vista della rete, mentre l’utente può comunque riservare IP, documentare informazioni non rilevabili e preparare modifiche future. La rappresentazione della connettività logica e fisica dipende dalle evidenze realmente disponibili.
### 0.2 Principi confermati

* IPAM utilizzabile senza MikroTik, UniFi, Zabbix, Telegram, inventario rack o altri servizi.
* Niente scope creep: una necessità reale e verificata giustifica una funzionalità; la sola possibilità di usarla in futuro non basta.
* Le integrazioni verso l’infrastruttura sono di sola lettura e non devono modificare configurazioni di router, switch e controller.
* Nessuna sonda remota e nessuna VPN come requisiti o opzioni del prodotto attualmente previsto.
* Nessun supporto SNMP: gli apparati interrogabili esclusivamente via SNMP saranno trattati come non interrogabili.
* Le informazioni non disponibili non devono essere inventate, e la mancanza di un’integrazione non deve bloccare le funzioni IPAM.
* UI/UX concepita sui compiti del tecnico, evitando di esporre tutte le entità e i dettagli del dominio nelle schermate principali.
* Preferenza per architettura proporzionata: niente worker permanenti, Redis o ulteriori servizi obbligatori introdotti senza necessità verificata.
### 0.3 Stack applicativo confermato

* Backend: Laravel. Frontend: React con Inertia e asset statici compilati, con componenti in stile shadcn/ui. Database: MariaDB 11.4 LTS.
* Sviluppo iniziale in Docker. Ambiente di produzione non ancora scelto: l’implementazione non deve imporre inutilmente Docker e deve restare valutabile per hosting condivisi PHP, cPanel e CloudPanel.
* Node.js ammesso in build e nello sviluppo, non come servizio di produzione obbligatorio. Evitare SSR e altri processi permanenti non necessari.
* Interfaccia curata, responsive e personalizzabile, con animazioni discrete e pagine custom quando migliorano il flusso.
### 0.4 Fuori perimetro finché non richiesto

* Motore generalizzato di riconciliazione, coda di approvazione e regole configurabili di prevalenza o sincronizzazione fra fonti.
* Scansione tramite agent installati presso i clienti, tunnel VPN e supporto SNMP.
* Configurazione o modifica automatica degli apparati di rete.
* CMDB, monitoraggio o gestione rack completi come prerequisito dell’IPAM di base.
* Piattaforma di plugin, microservizi e astrazioni preventive non giustificate.


## A01 — IPAM di base: funzionamento logico, senza integrazioni

Stato: A01 conclusa
Specifica funzionale dell’IPAM standalone consolidata dopo quattro blocchi di intervista (40 risposte, 26 ottobre 2026). Non autorizza da sola lo sviluppo e non definisce il dettaglio UI/UX, che appartiene ad A02. Il perimetro è IPv4; non si introducono VRF, domini L2, CMDB o integrazioni obbligatorie.
### Otto invarianti fondamentali

I1 — Contesto azienda/sede. L’azienda può avere una o più sedi. Quando il contesto è unico, nella UI si opera direttamente sull’azienda senza creare o mostrare una sede predefinita; l’eventuale rappresentazione tecnica interna resta una scelta implementativa. Quando viene aggiunta una seconda sede, l’operatore dà un nome alla sede già esistente e tutte le reti restano associate a quest’ultima.
I2 — VLAN facoltative. Ogni subnet appartiene al contesto di una sede e può avere una VLAN oppure essere «Senza VLAN»; nessuna VLAN 1 implicita. Un ID VLAN non può duplicarsi nella medesima sede, mentre può essere riutilizzato in sedi diverse. Una VLAN può essere vuota o contenere più subnet; si può crearla durante la creazione della subnet.
I3 — Subnet non sovrapposte per sede. Per creare una subnet è obbligatorio soltanto un CIDR IPv4 valido; nome, descrizione e VLAN sono facoltativi. Non sono ammesse sovrapposizioni, parziali o totali, tra subnet della stessa sede, anche su VLAN differenti. Tra sedi diverse la stessa rete può essere riutilizzata.
I4 — Un solo record per IP nella subnet. Ogni indirizzo IP documentato è un record unico nella sua subnet; il requisito minimo è IP più almeno uno fra Descrizione, Hostname o nome del dispositivo effettivamente associato. Il dispositivo non è obbligatorio. L’IP può essere modificato all’interno della stessa subnet, mantenendo i dati se il nuovo indirizzo non è già documentato. Un record creato automaticamente da un’integrazione è giustificato dalla fonte: NetVibe lo popola con i valori su cui le fonti concordano (MAC, hostname, commento o alias come descrizione, dispositivo tramite riferimento stabile) e lo aggiorna finché il tecnico non lo modifica. Il requisito minimo vale per la creazione manuale.
I5 — La disponibilità non dipende dallo stato. Ogni IP registrato occupa l’indirizzo qualunque sia lo stato, anche se personalizzato o chiamato «Libero». Un indirizzo non documentato può essere escluso dai suggerimenti; pertanto «non documentato» e «proponibile» non sono sinonimi. Nulla di ciò dimostra che l’indirizzo sia realmente inutilizzato sulla rete. Fa eccezione il record creato e gestito solo automaticamente, mai arricchito dal tecnico: trascorso il periodo di conservazione dell’azienda (default 30 giorni dall’ultima presenza affidabile) e dopo una lettura completa riuscita che confermi l’assenza delle evidenze, viene eliminato e l’indirizzo torna proponibile.
I6 — Intervalli esclusi indipendenti dal DHCP. Sono intervalli configurabili per subnet, esclusi dal suggerimento automatico ma non dalla documentazione manuale. Gli IP documentati inclusi in tali intervalli restano validi. Le sovrapposizioni fra intervalli vengono comunicate all’utente e unite; una rimozione parziale può dividere un intervallo in due.
I7 — Tre tipi distinti di metadati. (a) Stato: catalogo globale dell’installazione, uno o nessuno per IP, senza effetti sulla disponibilità. (b) Tag: catalogo aziendale, zero o più per IP, utilizzato per ricerca e raggruppamento. (c) Riepilogo subnet: campi liberi locali alla subnet, ciascuno con nome e uno o più valori testuali; non sono tag e non ereditano o collegano automaticamente IP di altre subnet.
I8 — Cancellazioni esplicite. L’eliminazione di record o contenitori con dati non è bloccata a priori, ma le operazioni distruttive richiedono il riepilogo dell’impatto e una conferma esplicita. La rimozione di uno stato può conservare o riassegnare gli IP senza cancellarli; la rimozione di una VLAN può conservarne le subnet senza VLAN oppure eliminare anche le subnet collegate. Non si eliminano automaticamente dispositivi esterni o indipendenti.
### Regole operative definite

#### Indirizzi e tabella della subnet

La vista della subnet mostra virtualmente gli indirizzi IPv4 utilizzabili, anche quando non esistono record documentati; la tabella è paginata e non vengono salvati gli IP liberi. In subnet ordinarie si nascondono gli indirizzi di rete e broadcast non utilizzabili. Per /31 e /32 si adottano le eccezioni tecniche IPv4 pertinenti, senza impostazioni aggiuntive.
Contatori distinti e senza sovrapposizioni: (1) IP documentati, (2) IP esclusi ma non documentati, (3) IP proponibili per una nuova assegnazione. Il numero proponibile deriva dagli indirizzi utilizzabili meno gli IP documentati meno gli indirizzi esclusi non documentati. Un IP documentato in un intervallo escluso viene contato una sola volta.
La proposta «Primo IP disponibile» seleziona l’indirizzo numericamente più basso fra quelli utilizzabili, non documentati e non esclusi. La stessa unicità va garantita anche se due operatori provano contemporaneamente a registrare il medesimo indirizzo.
La registrazione manuale di un IP appartenente a un intervallo escluso è consentita, ma accompagnata da un avviso. Vale anche quando si modifica l’indirizzo di un record esistente. La rimozione/liberazione di un IP elimina il suo record e le relative informazioni; l’indirizzo torna proponibile soltanto se non è escluso.
#### Modifica delle reti e degli intervalli

Il CIDR di una subnet può essere modificato solo quando tutti i record IP e gli intervalli esclusi associati restano nel nuovo perimetro e la subnet modificata non si sovrappone ad altre subnet della sede. In caso contrario l’operazione è bloccata con motivazione; niente riallocazione automatica.
All’aggiunta di un intervallo escluso sovrapposto a uno esistente, NetVibe segnala la sovrapposizione e indica che i due intervalli saranno uniti. Se l’utente rimuove solo una porzione, viene conservato il resto, eventualmente suddiviso in due intervalli. Gli indirizzi già documentati non vengono cancellati o alterati.
#### Stati, tag e campi personalizzati

Il catalogo globale degli stati contiene alcuni valori iniziali, per esempio «Assegnato» e «Riservato», modificabili o eliminabili. Non è obbligatorio selezionarne uno creando un IP e nessuno stato predefinito altera le regole di disponibilità. Ogni IP può avere al massimo uno stato.
Quando si elimina uno stato in uso, si può scegliere: (1) lasciare gli IP documentati senza stato, (2) migrare gli IP a un altro stato, (3) eliminare anche i record IP associati. La conferma mostra l’impatto su indirizzi e aziende dell’intera installazione; l’opzione (3) è chiaramente distruttiva.
I tag sono liberi e condivisi soltanto all’interno dell’azienda. Possono essere associati in numero multiplo a un IP e utilizzati nei filtri, nelle ricerche e nei raggruppamenti; non hanno significati di sistema predefiniti.
Ogni subnet può avere un proprio riepilogo modificabile con voci libere come «DNS: 1.1.1.1, 192.168.10.10», «Gateway: 192.168.20.1» o «Domain Controller: 192.168.10.10». I valori sono testo libero, non collegamenti strutturati o riferimenti ereditati: l’utente sceglie cosa scrivere per quella specifica subnet.
#### Eliminazioni e protezione della documentazione

VLAN: due scelte distinte. «Elimina soltanto la VLAN» rende Senza VLAN le subnet collegate e conserva i loro IP. «Elimina VLAN e subnet collegate» elimina anche IP, intervalli esclusi e riepiloghi appartenenti a quelle subnet, con anteprima dell’impatto.
Subnet: eliminabile anche con dati, con riepilogo di indirizzi documentati, intervalli esclusi e riepiloghi cancellati. Sede: eliminabile con i dati che contiene, dopo conferma. Azienda: eliminabile con tutte le sedi e reti, dopo anteprima complessiva e conferma distruttiva. Non lasciare relazioni orfane; l’eventuale contesto tecnico di sede unico deve restare coerente anche quando la UI mostra soltanto l’azienda.
### Casi funzionali di accettazione

C1 — Un’azienda appena creata può ospitare direttamente la subnet 192.168.1.0/24 senza obbligare a creare una sede o una VLAN nella UI. Quando viene aggiunta una seconda sede, viene richiesto il nome di quella esistente senza perdere le reti.
C2 — La subnet 192.168.1.0/24 nella VLAN 10 impedisce di creare 192.168.1.0/24 o 192.168.1.128/25 nella stessa sede, anche in un’altra VLAN, ma non impedisce lo stesso CIDR in un’altra sede.
C3 — Una subnet /16 senza indirizzi documentati mostra gli IP utilizzabili a pagine senza generarli tutti nel database; /31 e /32 producono i corretti indirizzi utilizzabili.
C4 — Due intervalli esclusi 192.168.1.100–150 e 192.168.1.140–180 vengono uniti con avviso. Un IP documentato al loro interno rimane registrato e occupato, mentre un IP escluso non documentato non viene suggerito.
C5 — Un IP senza stato o con stato personalizzato rimane occupato. La creazione richiede almeno Descrizione o Hostname se non esiste un dispositivo associato. Più tag aziendali possono essere assegnati allo stesso IP.
C6 — Rimuovendo uno stato globale, l’operatore vede i record e le aziende coinvolti e sceglie di lasciare gli IP senza stato, riassegnarli o eliminarli; le altre aziende non vengono modificate senza il risultato esplicitamente selezionato.
C7 — Eliminando una VLAN si può conservare ogni subnet come «Senza VLAN» oppure eliminarla con tutti i dati associati; analogamente, eliminare una subnet, sede o azienda con dati richiede una conferma basata sull’impatto reale.
### Perimetro escluso e passaggio ad A02

Fuori da A01: gestione strutturata di dispositivi/interfacce, inventario hardware, integrazione Snipe-IT, discovery, riconciliazione e topologia. Sono conservati per un’area separata i requisiti preliminari: un dispositivo può avere più interfacce; un’interfaccia può avere più IP; ogni IP può essere collegato al massimo a un’interfaccia; il MAC può essere annotato sull’IP standalone e attribuito all’interfaccia quando questa esiste. Questa area non deve dipendere da Snipe-IT.
A02 deve progettare la UI per: (1) azienda con sede implicita o più sedi; (2) navigazione VLAN / Senza VLAN / subnet e creazione rapida contestuale; (3) tabella paginata degli IP e tre conteggi; (4) dettaglio/modale rapido di creazione e modifica IP con avvisi per esclusioni e duplicati; (5) riepilogo subnet personalizzabile distinto dai tag; (6) anteprime chiare per le cancellazioni. Non reintrodurre una VLAN predefinita o obblighi di inventario.
Dipendenza interarea da affrontare quando si studierà A10: l’esportazione Excel «un foglio per VLAN» deve comprendere anche le subnet Senza VLAN. A02/A13 contengono ancora riferimenti esplorativi ai dispositivi: non autorizzano a portarli dentro l’IPAM base. Ruoli, audit, backup e integrazioni restano nelle rispettive aree.


## A02 — IPAM di base: UI/UX e flussi operativi

### Obiettivo

Progettare la navigazione e le schermate prima di fissare il database definitivo. I componenti tecnici non devono dettare la struttura dell’interfaccia.
### Flussi prioritari da prototipare

* Entro in NetVibe → scelgo cliente e sede → individuo rapidamente reti e IP rilevanti.
* Cerco un IP o MAC dalla ricerca globale → vedo subito dove si trova e a cosa è associato.
* Apro una subnet → consulto indirizzi, disponibilità e assegnazioni → creo o modifico un IP da un pannello/modale senza perdere il contesto.
* Creo un cliente, una sede, una VLAN e una subnet tramite percorso guidato ma non macchinoso.
* Visualizzo il dettaglio di un IP: assegnazione, descrizione, eventuale dispositivo e informazioni tecniche secondarie.
* Gestisco i casi privi di MAC, hostname, dispositivo o rete integrata senza schermate vuote inutili.
### Direzione UI proposta, non approvata

* Layout sobrio con ricerca globale, selezione cliente/sede visibile, schermate dedicate alla consultazione e modifica rapida.
* Vista subnet con tabella leggibile, indicatori sintetici e azioni contestuali; valutare rappresentazione a griglia solo se realmente utile.
* Dettaglio IP sintetico; dati avanzati in sezioni espandibili; icone di avviso piccole e discrezionali.
* Responsive per desktop e tablet; dark mode e contrasto accurati; animazioni brevi, non decorative.
* Niente pagina diversa per ogni tabella tecnica. Le entità sono strutture interne; l’utente vede attività e informazioni.
* Dashboard non sovraccarica di KPI superflui. Definire cosa un MSP vuole vedere all’apertura dell’applicazione.
### Da approfondire prima di scrivere codice

* Modello di navigazione principale e scelta del cliente/sede; cosa cambia per utenti che vedono un solo cliente.
* Vista predefinita degli IP: elenco, griglia, raggruppamenti e filtri persistenti.
* Campi davvero indispensabili nei modali di creazione; cosa mostrare nel dettaglio e cosa collassare.
* Stati vuoti, errori, notifiche toast, conferme e ritorno al punto di partenza.
* Prototipi dei flussi reali e test di usabilità con scenari manuali, prima dello sviluppo dell’intera area.


## A03 — Regole condivise: osservazioni, discrepanze, vendor MAC e topologia

### Stato e campo di applicazione

Decisioni condivise il 26 ottobre 2026. Le regole seguenti sono trasversali a tutte le integrazioni; non introducono un motore generalizzato di riconciliazione e non autorizzano l’implementazione prima degli approfondimenti specifici. A01 resta la specifica valida per l’IPAM senza integrazioni.
Le integrazioni rendono l’IPAM più aderente alla rete senza richiedere al tecnico di ricopiare continuamente informazioni che i sistemi esterni già conoscono. NetVibe resta un IPAM: permette prenotazioni, documentazione manuale e modifiche previste anche quando un indirizzo o un dispositivo non è ancora visibile in rete.


### Un’unica sede e acquisizione automatica

- Azienda e sede sono entità uniche, sia prima sia dopo l’attivazione delle integrazioni: non creare una «sede manuale» e una «sede importata» parallele.
- Se la sede parte vuota, i dati supportati dalle API (per esempio VLAN, subnet, IP configurati e osservazioni di rete) alimentano automaticamente la vista ordinaria, senza un’importazione manuale iniziale per ciascun record.
- Se esistono dati manuali, la prima acquisizione tenta corrispondenze non ambigue nel corretto contesto di sede (per esempio VLAN per ID e subnet per CIDR IPv4 normalizzato). Per le associazioni realmente ambigue serve una scelta puntuale, senza sovrascritture o duplicazioni arbitrarie.
- Le successive acquisizioni aggiornano le informazioni provenienti dalle fonti. Non è previsto un processo ricorrente in cui l’utente debba «approvare» ogni aggiornamento tecnico.
- Ogni IPv4 attribuibile senza ambiguità a una subnet della sede (configurazione IP, prenotazione o lease DHCP, ARP con MAC, neighbor, client e prenotazioni UniFi) compare come unico IP documentato; le osservazioni temporanee non diventano prenotazioni permanenti né dispositivi inventariati e restano occupate finché presenti o entro il periodo di conservazione.


### Significato e provenienza dei dati

- Distinguere logicamente configurazione esplicita (es. prenotazione DHCP statica), osservazione operativa (es. ARP o MAC appreso su una porta), inventario dichiarato da un sistema esterno e informazioni inserite dal tecnico (prenotazioni, note, valori previsti).
- Conservare almeno fonte, oggetto a cui l’informazione si riferisce e momento dell’acquisizione/ultima osservazione, con la precisione effettivamente disponibile.
- Le fonti non competono automaticamente per un generico campo «vero»: una prenotazione DHCP con MAC A e un’osservazione ARP con MAC B sono fatti distinti e possono coesistere. Il secondo valore non riscrive il primo.
- Non assumere che l’ultimo dato letto sia necessariamente corretto o che il MAC associato a un IP identifichi in modo certo il dispositivo fisico.


### Modifica manuale di un valore alimentato dalle integrazioni

Chiedere il significato della modifica soltanto quando il tecnico cambia un’informazione che è anche alimentata da una fonte esterna e la scelta influisce sul comportamento. Non applicare questa richiesta a note, tag, descrizioni o campi esclusivamente manuali.
- «Modifica prevista»: il valore nuovo è una condizione attesa per un intervento futuro (es. sostituzione di un server o nuova VM). Rimane distinto dall’ultimo valore osservato. Se una successiva acquisizione attendibile riscontra il valore previsto sul medesimo oggetto, NetVibe lo riconosce automaticamente e non chiede conferma; ciò non certifica da solo il completamento di tutto l’intervento.
- «Mantieni valore manuale»: il tecnico vuole conservare quella documentazione anche se la fonte esterna riporta un valore diverso. Il valore manuale non viene sovrascritto; il valore della fonte resta consultabile e può generare un piccolo avviso contestuale.
- Le modifiche in NetVibe non aggiornano automaticamente router, switch, controller o inventari esterni. Le prenotazioni manuali e i dati aggiunti dall’utente persistono anche se non sono presenti nelle osservazioni.
- Non introdurre una casistica distinta per ogni motivo operativo (sostituzione server, cambio scheda, nuova installazione ecc.): le due modalità descrivono il significato del dato, non il tipo di lavoro.


### Discrepanze e presentazione UI

- La UI ordinaria mostra un solo elenco IPAM e un’informazione principale utile al tecnico; non aggiungere colonne permanenti «NetVibe / Router», contatori «Documentati / Rilevati / Differenze» o una dashboard obbligatoria di anomalie.
- Mostrare discrepanze significative con un piccolo indicatore vicino al campo interessato. Tooltip o dettaglio contestuale espongono i valori confrontati, la fonte e il momento della lettura; eventuali azioni sono locali al caso, non un flusso di approvazione.
- Segnalare solo informazioni realmente confrontabili o situazioni tecnicamente rilevanti: una prenotazione DHCP statica con MAC A e un’osservazione ARP recente con MAC B possono suggerire una configurazione da verificare, senza decidere automaticamente quale sia sbagliata.
- La semplice assenza di un client da ARP, lease dinamici o client Wi-Fi non dimostra da sola che l’indirizzo sia libero: solo dopo il periodo di conservazione e una lettura completa riuscita un record esclusivamente automatico viene liberato. Un’integrazione irraggiungibile mantiene consultabile l’ultima osservazione disponibile, chiaramente contrassegnata come non aggiornata.
- Nessuno stato obbligatorio «Da verificare», nessuna coda di approvazione, nessuna richiesta ripetitiva di scegliere «Aggiorna» o «Mantieni» dopo ogni refresh.


### Coesistenza di più integrazioni e identità

- Ogni integrazione contribuisce con i dati effettivamente disponibili. Non definire una precedenza globale del tipo «MikroTik vince sempre su Snipe-IT» o «UniFi vince sempre su MikroTik».
- Per gli asset esterni collegati a dispositivi NetVibe privilegiare riferimenti stabili forniti dal sistema esterno. IP, MAC e hostname sono indizi da verificare, non chiavi universali sufficienti a identificare un dispositivo.
- Quando due fonti riportano informazioni divergenti, conservare la distinzione fra i fatti. Se sono davvero affermazioni incompatibili sul medesimo oggetto e contesto, mostrare un avviso discreto senza risolverle arbitrariamente.
- Il dispositivo può mostrare il collegamento al relativo asset Snipe-IT o un dettaglio alimentato dalle sue API. La correzione dei dati in Snipe-IT avviene su Snipe-IT; al successivo refresh NetVibe visualizza il nuovo dato.


### Limite funzionale contro lo scope creep

- Consentite: acquisizione e aggiornamento automatico delle osservazioni; associazioni iniziali affidabili; modifiche manuali puntuali; riconoscimento automatico di un valore previsto effettivamente riscontrato; indicatori contestuali.
- Escluse: regole personalizzabili per cliente, campo o integrazione; priorità configurabili fra fonti; «ignora sempre» come motore di eccezioni; aggiornamento massivo automatico delle identità/dispositivi da un MAC osservato; sincronizzazione di ritorno e scrittura verso le integrazioni.
- Ogni proposta futura di automazione deve essere valutata rispetto a questo confine: se richiede una politica arbitraria di precedenza o una nuova gestione generalizzata dei conflitti, è fuori perimetro salvo una nuova decisione di prodotto.
- NetVibe non diventa un controller cloud MikroTik/UniFi e non assume gestione o modifica della configurazione degli apparati.


### Casi funzionali di accettazione del modello integrato

C1 — Sede vuota: collegando una fonte funzionante, reti e osservazioni supportate compaiono nell’IPAM senza inserimento manuale duplicato.
C2 — Sede già compilata: una subnet esistente senza VLAN e una corrispondente subnet rilevata con VLAN vengono associate quando l’identità è affidabile; l’eventuale attribuzione discordante della VLAN richiede solo una decisione puntuale e non duplica la subnet.
C3 — Cambio MAC previsto: il tecnico registra il nuovo MAC come modifica prevista. Il valore osservato rimane distinto; quando la fonte lo rileva in modo attendibile, NetVibe segnala automaticamente la corrispondenza.
C4 — Valore manuale diverso: il tecnico sceglie di mantenerlo. I refresh non lo cancellano e l’eventuale differenza resta disponibile nel dettaglio senza richieste continue.
C5 — DHCP/ARP discordanti: la prenotazione DHCP e il MAC osservato non si sovrascrivono; la differenza viene indicata con fonte e momento di acquisizione.
C6 — Asset collegato: un asset Snipe-IT e un dato di rete discrepante vengono mostrati senza regole di prevalenza né modifiche via API a Snipe-IT; dopo la correzione esterna la vista si aggiorna.
C7 — Fonte offline: non vengono eliminati record manuali o prenotazioni e l’ultima osservazione resta visibile come obsoleta.


### Da approfondire senza anticipare implementazioni

- Quali dati e identificativi le singole API espongono realmente, con quale freschezza e quale livello di attendibilità; non dedurre associazioni VLAN o topologia senza evidenze.
- Associazione di dispositivi e interfacce fra fonti multiple; nessuna fusione automatica basata su un solo IP/MAC quando ambigua.
- Conseguenze delle osservazioni sul conteggio degli IP occupati e sul suggerimento del primo IP disponibile, mantenendo gli invarianti di A01 per l’IPAM standalone.
- Rappresentazione di configurazioni rilevate che non rispettano il vincolo A01 sulle subnet non sovrapposte: mostrare i dati acquisiti senza nasconderli né modificarli, ma non inserirli forzatamente nei record documentali.
- UX minima della prima associazione e criterio preciso per considerare riscontrata una modifica prevista; definire sui flussi reali, non mediante un motore di regole preventivo.


### Vendor MAC e tipologia suggerita

Valutare la consultazione locale degli elenchi IEEE MA-L/MA-M/MA-S, con aggiornamenti controllati, senza API esterna obbligatoria. Il vendor del MAC può suggerire una tipologia (es. telefono IP), ma non la dimostra. I MAC locali/randomizzati e i casi senza assegnazione nota devono rimanere classificabili manualmente.
### Topologia minima

* Usare ARP, DHCP, MAC/FDB, LLDP/CDP/MNDP, informazioni di porta e uplink effettivamente esposte.
* Rappresentare collegamenti conosciuti, incompleti o non determinabili senza inventare un percorso fisico.
* Più MAC sulla stessa porta possono indicare bridge, telefono con PC, AP, switch non gestito o virtualizzazione; suggerire, non decidere.
* Consentire la classificazione manuale di dispositivo, AP, telefono e switch non gestito e l’aggiunta di collegamenti manuali.
* Se non ci sono apparati/interfacce conosciuti, non mostrare un grafo artificiale nell’IPAM standalone.


## A04 — Integrazione MikroTik: gateway RouterOS

### Scopo

L’utente configura la connessione al gateway MikroTik per una sede. Da quel momento NetVibe acquisisce automaticamente, in sola lettura, le informazioni supportate dal dispositivo e le utilizza per arricchire IPAM e topologia senza importazioni manuali ripetitive.
### Informazioni da validare sui payload REST reali

* Identità RouterOS, versione e interfacce fisiche/logiche.
* Indirizzi IP e subnet configurati; interfacce VLAN, bridge, porte del bridge e appartenenze VLAN tagged/untagged.
* Server DHCP, pool, lease statici e dinamici e relativi stati.
* ARP, bridge host/MAC table, dati di neighbor discovery LLDP/CDP/MNDP.
* Possibilità di eseguire un ping dal router verso indirizzi della LAN, come operazione diagnostica controllata.
* Quali dati permettono di associare un IP osservato a un MAC e a un dispositivo senza attribuzioni arbitrarie.
### Configurazioni particolari

* Più bridge, VLAN filtering attivo/disattivo e VLAN definite su bridge o interfacce fisiche.
* VLAN senza subnet IP, più subnet nella stessa VLAN e ID VLAN uguali su segmenti distinti.
* Router con DHCP su alcune reti e IP statici su altre; apparati non visibili nelle lease.
* Dati incompleti o temporanei: definire gli effetti di un ARP scomparso o di un lease non più bound.
### Connessione, sicurezza e automazione

* Connessione REST HTTPS verso endpoint configurato; esposizione ammessa soltanto dal server NetVibe mediante firewall e IP di uscita consentito.
* Credenziali dedicate e privilegi minimi; nessuna modifica configurativa via NetVibe.
* Certificati self-signed supportabili tramite configurazione esplicita e indicazione del rischio.
* Sincronizzazione automatica con cron leggero ove disponibile, più refresh puntuale manuale; niente worker permanente come prerequisito.
* Gestione dei timeout e dei router irraggiungibili senza rendere lenta o indisponibile la UI.
### Decisioni aperte

* Come rappresentare reti, indirizzi e lease statici nella vista integrata secondo A03, distinguendo configurazione e osservazione senza produrre assegnazioni manuali persistenti per ogni client rilevato.
* Come determinare se un pool DHCP o una subnet rilevata appartengono alla sede e alla VLAN giuste.
* Intervalli e limiti delle letture; persistenza e scadenza delle osservazioni.
* Livello effettivo di raggiungibilità da usare nella UI (ping, ARP, DHCP) e denominazioni corrette.


## A04.1 — Sottoarea MikroTik: switch RouterOS

### Scopo

Supportare switch MikroTik con RouterOS tramite endpoint REST indipendenti; non includere SwOS via SNMP. Uno switch scoperto da LLDP/MNDP può restare visibile senza endpoint API configurato.
### Ipotesi operativa da testare

In presenza di NAT, ciascuno switch RouterOS può essere pubblicato dal gateway su una porta HTTPS esterna distinta e filtrata sul solo IP pubblico di NetVibe. L’operatore associa l’endpoint allo switch esistente o appena identificato, senza richiedere sonde o VPN.
### Approfondimenti

* Interfacce fisiche, stato porte, MAC/FDB, bridge, VLAN, neighbors, uplink e LLDP disponibili via API per modelli/versioni rappresentativi.
* Come correlare il MAC osservato a una porta finale distinguendo porte di accesso, trunk, telefoni VoIP e switch non gestiti.
* Come evitare duplicati fra apparato scoperto da neighbor e apparato aggiunto tramite endpoint API.
* Rappresentazione di switch SwOS, TP-Link o altri apparati solo manualmente/non interrogabili.
* Limiti di accesso NAT per più switch e gestione di certificati, credenziali e modifiche degli IP di uscita.


## A05 — Integrazione UniFi

### Scopo

Acquisire le reti e la topologia degli apparati UniFi adottati, sia quando UniFi è il gateway sia quando la sede ha gateway MikroTik e switch/AP UniFi.
### Informazioni e fattibilità da verificare

* Cloud Gateway UniFi e console UniFi Network: versioni richieste e API ufficiali disponibili.
* Site Manager API / Cloud Connector: modalità di accesso, permessi, rate limit e disponibilità dei dettagli dei dispositivi.
* Reti, VLAN, subnet, DHCP, prenotazioni statiche e client connessi effettivamente esposti.
* Switch e access point adottati: porte, stato, uplink, associazioni client-MAC-porta e topologia fra apparati.
* Come integrare MikroTik gateway e UniFi switch senza due identità o due topologie concorrenti.
* Quali client wireless rendere consultabili come osservazioni, con origine e momento di acquisizione, senza trasformarli automaticamente in assegnazioni permanenti o dispositivi inventariati.
### Vincoli

* Usare API ufficiali; non fondare funzioni essenziali su endpoint interni non documentati.
* Le prenotazioni Fixed IP, non esposte dall’API ufficiale, si leggono in sola lettura dall’endpoint dell’applicazione Network con la stessa chiave API; se non disponibile, l’ultimo dato resta consultabile come non aggiornato.
* Non assumere che le informazioni visibili nella GUI UniFi siano tutte disponibili via API.
* L’integrazione non configura automaticamente UniFi e non deve impedire l’uso dell’IPAM se la console è offline.


## A06 — Integrazione Snipe-IT

### Identificazione e scopo

Il sistema esterno di asset management è Snipe-IT, con API REST ufficiale (https://snipe-it.readme.io/reference/api-overview). Il ruolo dell’integrazione è arricchire la consultazione dei dispositivi collegati, senza replicare l’intero gestionale patrimoniale.
### Approfondimenti

* Che ruolo deve avere il sistema esterno: inventario patrimoniale, assegnazione utenti, dispositivi, seriali, modelli o altro.
* Dati patrimoniali dichiarati da Snipe-IT e informazioni di rete configurate/osservate hanno significati distinti. Applicare A03: nessuna precedenza globale Snipe-IT vs MikroTik/UniFi.
* Associazione tra device NetVibe e asset esterno, identificativi stabili e ricerca dei duplicati.
* Integrazione di sola lettura. Collegamento navigabile all’asset esterno oppure dettaglio NetVibe alimentato dalle API; non aggiornare o sincronizzare verso Snipe-IT le differenze rilevate.
* Autorizzazioni multi-cliente e separazione degli asset dei clienti.
* Minimo di dati necessari per non trasformare NetVibe in una replica di un gestionale asset.


## A07 — Telegram: bot rich text e Mini App

### Scopo

Permettere al tecnico autorizzato di consultare e assegnare IP rapidamente da Telegram e, per operazioni con più campi, utilizzare una Mini App in React collegata allo stesso backend Laravel.
### Flussi prioritari

* Richiesta: primo IP disponibile nel cliente/sede/subnet selezionati.
* Risposta con indirizzo proposto e indicazione sintetica della qualità della verifica.
* Inserimento di MAC, descrizione e, se necessario, associazione a dispositivo; conferma prima del salvataggio.
* Ricerca di un IP, MAC, hostname o dispositivo e collegamento al relativo dettaglio web.
* Mini App per selezione cliente/sede, subnet, compilazione di più campi e gestione di contesti complessi.
* Messaggi HTML/rich text e pulsanti dove sono utili; niente elenco definitivo di pulsanti prima dello studio dei flussi.
### Da approfondire

* Collegamento sicuro account NetVibe ↔ Telegram, autorizzazioni e revoca accesso.
* Webhook HTTPS e verifica della firma dei dati della Mini App.
* Gestione di più utenti che tentano di assegnare contemporaneamente lo stesso IP.
* Ambiguità nella selezione di clienti e subnet omonime; durata di una conversazione e annullamento.
* Nessun servizio Node permanente richiesto soltanto per bot o Mini App.


## A08 — Integrazione Zabbix

### Scopo

Arricchire IPAM e dispositivi con stato, disponibilità e problemi di monitoraggio già presenti in Zabbix. NetVibe non deve reimplementare Zabbix.
### Approfondimenti

* Connessione via API ufficiali Zabbix e associazione host ↔ dispositivo/IP NetVibe.
* Gestione di host multi-IP, omonimi o di clienti diversi; identificazione affidabile.
* Stato di raggiungibilità, disponibilità delle interfacce e problemi da mostrare in modo sintetico.
* Quali informazioni di inventario esistenti possano essere riutilizzate senza duplicazione.
* Eventuale uso di dati SNMP già raccolti da Zabbix: NetVibe non effettua SNMP direttamente.
* Permessi API e gestione di Zabbix non raggiungibile senza degradare l’IPAM.


## A09 — Rack e documentazione delle installazioni (possibile prodotto separato)

### Visione

Editor visuale ispirato a Rackula, finalizzato sia a rappresentare la situazione reale sia a progettare un’installazione futura con istruzioni pratiche per i tecnici sul campo.
### Funzioni da definire

* Creazione di armadi rack con dimensione in unità, vista frontale/posteriore e posizionamento dei dispositivi.
* Catalogo leggero dei dispositivi e dimensioni fisiche; eventuale riutilizzo dei device NetVibe senza duplicati.
* Collegamenti fra porte: apparati, switch, patch panel, uplink e cavi, solo al livello di dettaglio necessario.
* Distinzione fra stato attuale e stato pianificato, con possibilità di confrontarli.
* Produzione di documentazione stampabile (preferibilmente PDF): schema rack, posizioni U, elenco apparati, tabella cablaggi e istruzioni operative.
* Documentazione da consegnare al tecnico per nuove installazioni: cosa montare, dove, come cablare e come verificare il risultato.
* Eventuale stampa di checklist operative o piano di lavoro, senza trasformare subito NetVibe in un project management.
### Decisione architetturale aperta

Valutare se il rack debba essere un modulo NetVibe o un prodotto separato, integrato soltanto a livello di dispositivi e collegamenti. Non sviluppare oggi un motore rack generico come prerequisito dell’IPAM.
### Da approfondire

* Casi concreti: rack esistente da documentare, nuovo rack da allestire, intervento su rack parziale.
* Livello grafico e precisione richiesta; importazione o creazione visuali dispositivi.
* Gestione delle versioni di un progetto d’installazione e delle stampe consegnate ai tecnici.
* Confine fra diagramma rete, cablaggio fisico e pianificazione dell’installazione.
* Funzionalità di Rackula da prendere come ispirazione e limiti di riuso del codice.


## A10 — Google Drive: esportazione IPAM unidirezionale

### Obiettivo confermato

Esportare automaticamente da NetVibe a Google Drive una copia di consultazione dell’IPAM in formato Excel (.xlsx). Flusso esclusivamente NetVibe → Drive: le modifiche ai fogli su Drive non vengono reimportate.
### Formato richiesto

* File Excel con un foglio «Riepilogo» e un foglio per ogni VLAN.
* I fogli devono contenere indirizzi IP e informazioni utili alla consultazione esterna; campi precisi e presentazione sono da definire.
* Il file deve restare leggibile anche se NetVibe o il server non sono disponibili.
* La struttura deve tenere conto di clienti e sedi: VLAN con lo stesso numero possono esistere in sedi differenti.
* Distinguere gli IP documentati dalle eventuali informazioni osservate, per non presentare dati temporanei come assegnazioni certe.
### Da approfondire

* Un file per cliente, per sede o per intera installazione? Quale struttura cartelle Drive?
* Nomenclatura dei fogli, collisioni tra VLAN omonime e limite Excel di 31 caratteri per nome foglio.
* Colonne e ordinamento dei fogli; ipotesi di riepilogo con VLAN/subnet, utilizzo e ultimo aggiornamento.
* Frequenza e meccanismo di esportazione: schedulato, manuale, entrambi; gestione di sovrascritture e versioni.
* Autenticazione Google (OAuth/servizio supportato), token, permessi minimi e disconnessione.
* Comportamento in assenza di Drive, esportazioni fallite o spazio esaurito.
* Non includere credenziali, segreti o altre informazioni sensibili non necessarie nei file Excel.
### Distinzione fondamentale

L’Excel su Google Drive è una copia leggibile e operativa dell’IPAM. Non sostituisce il backup tecnico completo dell’applicazione.


## A11 — Google Drive: backup ZIP e ripristino completo o per cliente

### Obiettivo confermato

Produrre archivi ZIP di backup caricabili su Google Drive per ripristinare un’installazione NetVibe dopo un guasto, oppure recuperare i dati di un singolo cliente senza dover necessariamente ripristinare gli altri.
### Due tipi di backup da progettare

* Backup completo: dati applicativi, impostazioni, utenti e permessi, dati dei clienti, collegamenti, eventuali allegati e tutti gli elementi necessari a ricostruire lo stato previsto dell’installazione.
* Backup di un singolo cliente: sedi, VLAN, subnet, IP, dispositivi, informazioni e collegamenti pertinenti, preservando la coerenza delle relazioni.
* Verificare quali informazioni siano globali e non esportabili insieme a un singolo cliente, per esempio impostazioni generali e utenze condivise.
* Le credenziali di integrazione e i segreti richiedono una politica esplicita: inclusione cifrata, reinserimento manuale o altra scelta. Un ZIP privo di materiale indispensabile non può essere chiamato backup completo senza avvertimento.
### Ripristino: scenari concreti

* Server perso o danneggiato → nuova installazione compatibile → importazione del backup completo.
* Cliente eliminato o corrotto → ripristino di un solo cliente.
* Cliente già esistente al momento dell’importazione → evitare duplicazioni e sovrascritture indesiderate.
* Ripristino tra versioni differenti dell’applicazione → specificare compatibilità e migrazioni supportate.
* Verifica di integrità, contenuto e versione dell’archivio prima di scrivere dati.
* Test periodico di ripristino effettivo, non soltanto produzione riuscita dello ZIP.
### Aspetti di sicurezza e operatività aperti

* Cifratura del backup, protezione e recuperabilità della chiave, permessi Drive.
* Retention, numero di versioni, cancellazione automatica o conservazione manuale.
* Limiti di dimensione ZIP, allegati e tempi di esecuzione su hosting condiviso; eventuale creazione a blocchi se necessario.
* Coerenza del database durante il backup e compatibilità del dump MariaDB con gli hosting obiettivo.
* Separazione fra esportazione Excel, backup tecnico e funzionalità di importazione dati.
* Scelta del perimetro del backup in caso di futura separazione del modulo rack in un altro prodotto.


## A12 — Requisiti trasversali da definire senza over-engineering

### Multi-cliente, utenti e sicurezza

* Isolamento dei clienti e sedi; operatori MSP che lavorano su più clienti; eventuale accesso di personale dei singoli clienti.
* Autorizzazioni semplici, verificate dal backend su UI, API e integrazioni.
* Credenziali di integrazione cifrate, privilegi minimi, connessioni TLS e protezione degli endpoint di gestione esposti.
* Audit minimo delle modifiche gestionali essenziali (chi, cosa, quando), senza log smisurati di ogni osservazione.
### Esecuzione, refresh e hosting

* Nessuna dipendenza obbligatoria da worker e servizi server permanenti. Un cron è ammissibile per sincronizzazioni periodiche purché proporzionato.
* Ping standalone soltanto se l’hosting e il percorso di rete lo consentono; una LAN privata dietro NAT non diventa raggiungibile dal server pubblico.
* Refresh su richiesta e dati acquisiti periodicamente; timeout e errori visibili senza bloccare l’IPAM.
* Verifica durante lo sviluppo del deploy Laravel PHP-FPM con asset precompilati su un ambiente non-Docker rappresentativo.
* CI e test essenziali per IPAM, segregazione dei clienti, concorrenza nelle assegnazioni, importazioni, backup/ripristino e incongruenze UI.
### Nessuna decisione prematura

Docker resta l’ambiente di sviluppo. Hosting di produzione, intervalli di aggiornamento, eventuali componenti server aggiuntivi e confini di un prodotto rack separato devono essere decisi sui requisiti e su prove reali, non anticipati.


## A13 — Ordine degli approfondimenti e registro decisioni

### Ordine di discussione proposto (non roadmap di sviluppo)

1. A01 — Logica dell’IPAM standalone: clienti, sedi, VLAN, subnet, IP, disponibilità e relazione con dispositivi/interfacce.
2. A02 — UI/UX standalone: navigazione, ricerca, lista subnet, dettaglio IP, assegnazione e gestione dei dispositivi facoltativi.
3. A03 — Modello comune confermato di acquisizione, modifiche manuali, fonti discordanti e limite alla riconciliazione.
4. A04 e A04.1 — MikroTik gateway e switch RouterOS, dati REST e topologia realistica.
5. A05 — UniFi con reti e topologia multi-vendor.
6. A06, A07, A08 — Asset esterno («Snipit»), Telegram e Zabbix.
7. A10 e A11 — Esportazione Excel, backup e ripristino Google Drive.
8. A09 — Rack e documentazione delle installazioni, inclusa la decisione modulo/prodotto separato.
9. A12 — Conferma requisiti trasversali e fattibilità degli ambienti di esecuzione.
### Questioni ad alto impatto, ancora aperte

* Qual è il modello minimo corretto fra IP, dispositivo, interfaccia e MAC senza imporre una CMDB completa?
* Quali informazioni alimentano la vista operativa e quali rimangono osservazioni temporanee, senza creare automaticamente assegnazioni permanenti? Come distinguere i dati acquisiti dai record manuali di A01?
* Come rappresentare un IP disponibile se il DHCP ha un pool, la rete non è raggiungibile o le osservazioni sono obsolete?
* Qual è la minima semantica della topologia per distinguere collegamento accertato, percorso indiretto e dato sconosciuto?
* Quale approccio garantisce backup completi e recuperabili, anche dopo perdita dell’installazione originaria?
* Quali identificativi e informazioni di Snipe-IT servono davvero per collegare un asset al dispositivo NetVibe senza replicare un inventario completo?
### Regola per chi svilupperà

Le sezioni «Da approfondire» non autorizzano gli agent a scegliere e implementare liberamente una soluzione. Ogni area sarà raffinata con decisioni esplicite, esempi di flusso, criteri di accettazione e confini prima di trasformarla in istruzioni operative. Non recuperare indiscriminatamente il codice o le astrazioni dei precedenti repository NetVibe: verificarli caso per caso.

---

## A14 — Governance della specifica e regole di sviluppo

**Stato: confermato dal Product Owner, 26 ottobre 2026.** Questa sezione integra le decisioni operative senza modificare gli invarianti funzionali di A01–A13. Per workflow e tutela della specifica, prevale su indicazioni generiche precedenti. Le scelte tecniche precedentemente indicate come «Proposta» non diventano «Confermato» per effetto di questa sezione.

### Specifica canonica e approvazione obbligatoria

- Il presente file su GitHub è la **specifica ufficiale**. La copia Google Drive originaria rimane una fonte storica, non la fonte normativa dello sviluppo.
- La specifica e i suoi allegati protetti possono essere modificati, creati, spostati, formattati o eliminati **solo dopo autorizzazione preventiva, esplicita e circoscritta del Product Owner**. Questo requisito vale anche per modifiche apparentemente innocue e automatiche.
- Prima di qualsiasi modifica, l'agent deve proporre il cambiamento puntuale, indicare le sezioni interessate, motivarlo, illustrare l'impatto concreto e segnalare possibili incongruenze con altre sezioni, decisioni approvate, flussi, moduli, interfacce, sicurezza, database e test. Se una conseguenza non è verificata, deve dichiararlo.
- Una issue di sviluppo non autorizza automaticamente modifiche della specifica. Le integrazioni, i test, i refactoring e l'installazione di skills non possono riscriverla per «allinearla» al codice. Il Product Owner deve approvare **la modifica documentale proposta**, non soltanto la funzionalità.
- Le istruzioni di tutela sono riprese in `AGENTS.md` e l'assegnazione della review in `.github/CODEOWNERS`. Il controllo di GitHub richiede separatamente regole di protezione del branch; `CODEOWNERS` da solo non impedisce i push.

### Comportamento degli agenti

- Non inventare, non assumere e non dare niente per scontato. Leggere integralmente i file pertinenti, anche in più parti. Non recuperare automaticamente codice o requisiti dallo storico dei precedenti NetVibe.
- Implementare esclusivamente ciò che è richiesto, nel modo più piccolo e coerente possibile. Applicare DRY quando elimina reale duplicazione di logica, senza introdurre livelli, infrastrutture, dipendenze, controlli e astrazioni non necessari.
- Evitare audit rituali prima/durante/dopo, calcoli SHA256 o altri checksum generati per banali confronti, inventari e script non richiesti.
- **Solo IPv4**. IPv6 non è supportato e non deve essere implementato o predisposto.
- UI italiana; codice e commenti non ovvi in inglese, spiegando il perché delle scelte.

### Branch, ambiente Docker, verifica e test

- Ogni issue si lavora su un branch `issue/<numero>-<nome-breve>` dedicato. I compiti articolati senza issue usano un branch `task/...`. Piccole modifiche isolate senza issue possono essere fatte su `main`.
- Dopo le modifiche, aggiornare l'istanza di sviluppo Docker **solo con le operazioni necessarie**, preservando i dati, e mostrare al Product Owner le schermate o i link realmente accessibili e alcuni passaggi per verificare.
- Ricevere prima feedback e approvazione funzionale/UI. **I test automatizzati si eseguono soltanto dopo tale approvazione** e solo se proporzionati ai rischi reali della issue; una rinomina banale normalmente non richiede nuovi test.
- Vietati browser E2E, percentuali di copertura obbligatorie, ripetizioni della stessa verifica a più livelli e suite estese senza necessità. Privilegiare un singolo test mirato di logica IPv4 oppure un test MariaDB di persistenza/sicurezza quando serve.
- Nei test usare un **database MariaDB distinto** dal database dell'istanza Docker consultabile dal Product Owner. Mai azzerare o alterare quel database mediante i test.
- Correzioni emerse dai test che modificano UI o comportamento devono essere ripresentate al Product Owner.
- Dopo approvazione e test pertinenti, eseguire push, PR, merge e chiusura issue. Il push anticipato è possibile soltanto se l'attività chiede espressamente di pubblicare contenuti su GitHub, senza presumere l'autorizzazione al merge.

### Skills e CI

- Skills iniziali: **Ponytail** (approccio minimale), **Laravel Boost** (documentazione e skills legate alle versioni installate) e skill ufficiale **shadcn/ui**. Non installare indiscriminatamente altre skills né accettare riscritture automatiche di file protetti.
- CI iniziale **minima**, commisurata al codice effettivamente implementato. Nessuna infrastruttura complessa di test o quality gate preventivo; ampliarla soltanto su necessità verificata e approvata.
- Lo storico GitHub precedente viene conservato per consultazione ma **non costituisce una fonte di implementazione**. La pulizia del vecchio codice e dei branch è un'operazione distinta, da svolgere senza riscrivere la cronologia e senza perdere commit ancora raggiungibili solo da vecchi branch.
