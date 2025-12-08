Debito, incongruenze, zombie

Schema vs codice
- `Section` modello/seeder senza migration; VRF/L2Domain create e poi droppate (ordine migrazioni fragile).
- Tenant scope attivo solo con `session('tenant_id')`: CLI/API senza sessione operano cross-tenant.
- DeviceResource duplicata (Racks vs Infrastructure) con slug `infra-devices`.

Logica incompleta/stub
- IngestMikrotikInventoryAction minimale (no normalizzazione hw/licenze/interfacce).
- ProcessAgentJobAction placeholder non usato.
- SubnetObserver no-op (prefill deprecato).

Sicurezza/affidabilità
- SSL verify disabilitato in MikrotikApiDriver e NetVibeHttpClient → rischio MITM.
- Remote agent loop senza watchdog/backoff globale (solo 5/30s su pollJobs).
- Debug endpoint attivi: `/_debug/throw`, `/_debug/exception-callbacks` (dump callback, protezione solo parziale).

Dati duplicati/magic
- `tagged_vlans` mix string/array tra PortsRelationManager e ingest Mikrotik → formattazione incoerente.
- DiscoveryEvent bulk approve non forza tenant/customer, possibile insert IP fuori scope.
- Device boot inferisce customer da session/rack/location senza verifiche cross-tenant/credenziali.

Legacy/compatibilità
- Stub `docker/remote-agent/remote_scanner.php` fallisce senza env (può confondere test).
- ForceCanonicalUrl solo web: API raggiungibili su host alternativi anche con canonical richiesto.
