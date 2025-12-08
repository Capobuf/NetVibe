Core DCIM & device logic

Device lifecycle
- Creazione: inferisce `customer_id` da tenant session/location/rack; genera porte dal DeviceModel (GenerateDevicePortsAction); guardia rimuove `scan_agent_id` se colonna mancante.
- DeviceModel quick-create normalizza enum type; CredentialProfile fornisce credenziali cifrate per device/job.

Porte e cablaggio
- Port: pvid/tagged array, status, topology hints; UI di connect/disconnect nel relation manager.
- ManageLinkService: richiede porte down, impedisce self/duplicati, transaction per creare link + aggiornare stati; disconnect resetta entrambi. Link model valida duplicati/self.

Job agent (device-centric)
- DispatchDeviceJobAction: sceglie scan agent (esplicito > device > customer attivo > globale), inserisce credenziali (profilo preferito) e timeout da DiscoverySettings.
- API agent: AgentJobController@index restituisce pending job arricchiti di credenziali; storeResult aggiorna status/output/result e chiama ProcessAgentJobResultAction.
- Remote agent: AgentWorkCommand polla config (60s) e job (5s), maschera token, invia log; JobHandler esegue `check_connectivity` (resource+identity) e `discovery_deep` (interfaces, bridge vlan/host, neighbor, ARP, DHCP) con fallback HTTPS→HTTP e legacy fping su `cidr`/`legacy_fping`.

Ingestion risultati/topologia
- ProcessAgentJobResultAction: aggiorna device auth_status/last_seen per connectivity; `discovery_deep` ingesta prima topologia poi ARP/DHCP; `discovery_fast` solo ARP.
- IngestMikrotikTopologyAction: crea/aggiorna porte da interface list, mappa VLAN da bridge vlan, collega porte per MAC, aggiorna IP→port o crea DiscoveryEvent orfano.
- IngestMikrotikInventoryAction: placeholder che mappa identity/serial/model e memorizza OS/uptime in `auth_extra`.
