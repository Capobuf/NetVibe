# Ricognizione reale MikroTik — 31 ottobre 2026

Rapporto anonimizzato della issue #57, separato dalla [matrice teorica](mikrotik-rest.md). Fonte: riepilogo persistito dall’analisi avviata nell’applicazione, registrato il 31 ottobre 2026 alle 18:21:26 UTC. Nessuna nuova interrogazione del router è stata necessaria per redigere questo rapporto.

## Piattaforma e risultato

- Board dichiarata da RouterOS: `RB5009UG+S+`; architettura `arm64`; versione `7.25`.
- Esito complessivo: `ok`. Tutti i 18 menu della lista consentita risultano effettivamente interrogati e letti.
- Nessun menu vuoto, non disponibile o non leggibile in questa lettura. Questo non prova la disponibilità su altre versioni o piattaforme.
- Il rapporto contiene solo conteggi, nomi dei menu e proprietà tecniche. Non include identità del router, endpoint, nomi cliente, indirizzi, MAC, credenziali o contenuti dei commenti.

## Endpoint effettivamente interrogati

Tutte le richieste sono GET sotto `/rest`, limitate alle proprietà elencate nella matrice tecnica. Conteggi riferiti alla singola lettura:

| Menu | Record | Esito |
| --- | ---: | --- |
| `/system/identity` | 1 | Letto |
| `/system/resource` | 1 | Letto |
| `/interface` | 19 | Letto |
| `/interface/ethernet` | 9 | Letto |
| `/interface/vlan` | 2 | Letto |
| `/interface/list` | 6 | Letto |
| `/interface/list/member` | 1 | Letto |
| `/interface/bridge` | 2 | Letto |
| `/interface/bridge/port` | 9 | Letto |
| `/interface/bridge/vlan` | 3 | Letto |
| `/interface/bridge/host` | 19 | Letto |
| `/ip/address` | 8 | Letto |
| `/ip/arp` | 93 | Letto |
| `/ip/dhcp-server` | 2 | Letto |
| `/ip/dhcp-server/network` | 3 | Letto |
| `/ip/dhcp-server/lease` | 13 | Letto |
| `/ip/pool` | 6 | Letto |
| `/ip/neighbor` | 2 | Letto |

## Interpretazioni supportate dalla lettura

Sono presenti due bridge: uno con VLAN filtering attivo e uno disattivo. Sono state riscontrate interfacce VLAN sia su bridge sia su Ethernet. Le due interfacce VLAN L3 rimangono distinte dalle tre righe di appartenenza L2, due delle quali dinamiche. Le proprietà `tagged`, `untagged`, `current-tagged` e `current-untagged` sono state osservate; l’interprete non ha riscontrato nomi di interface list nei campi tagged/untagged.

Gli otto IPv4 configurati comprendono un’interfaccia con più indirizzi. Le tredici lease comprendono dodici statiche e una dinamica, senza flag non classificabili. Questi conteggi non stabiliscono corrispondenze fra pool, reti DHCP, VLAN e subnet NetVibe. ARP, lease, MAC table e neighbor restano osservazioni differenti; non attestano che un indirizzo sia libero.

Non sono stati rilevati ID VLAN ripetuti fra i contesti nominati esaminati. È un risultato limitato a questo apparato e a questi menu, non una soluzione al conflitto di unicità per sede. Sono state contate diciannove occorrenze di commenti, senza conservarne il contenuto nel riepilogo o dedurne una destinazione NetVibe.

## Verifica della documentazione hardware

La [scheda ufficiale RB5009UG+S+IN](https://mikrotik.com/product/rb5009ug_s_in) documenta nove porte cablate, architettura ARM64 e switch chip 88E6393. Il [manuale Switch Chip Features](https://manual.mikrotik.com/docs/bridging-and-switching/switch-chip-features/) associa la serie RB5009 al chip 88E6393X e indica la configurazione VLAN attraverso bridge hardware VLAN filtering, anziché la tabella alternativa `/interface/ethernet/switch/vlan`.

I menu bridge già interrogati sono quindi pertinenti a questa famiglia. Non è stata aggiunta una lettura indiscriminata delle tabelle switch alternative. Il riepilogo non dimostra che l’offload hardware sia effettivamente attivo: la presenza di `hw` sulle porte non basta a certificarlo. Il manuale consultato espone la versione 7.26, mentre la lettura reale è 7.25; non si attribuiscono al router tutte le capacità del manuale corrente.

## Limiti e decisioni successive

Questa lettura non collauda casi con zero o un solo bridge, menu vuoti/non disponibili, interface list in tagged/untagged, collisioni VLAN fra contesti o hardware alternativo. Le fixture sintetiche restano esplicitamente separate dalle osservazioni reali. Il riepilogo aggregato non consente di ricostruire tutte le associazioni o verificare quali VLAN siano prive di indirizzi.

Nessuna importazione o sincronizzazione è stata introdotta. Restano da decidere, prima della seconda issue, le collisioni VLAN, la destinazione dei commenti, le regole di correlazione e la gestione delle osservazioni e dei conflitti fra apparati, come elencato nella matrice tecnica. La specifica protetta non è stata modificata.
