Schema & relationships

Tenancy
- TenantAware scope su `customer_id` attivo quando `session('tenant_id')` è presente (TenantAware, TenantScope). Customer è l’entità di tenancy; no DB separati.

Organizzazione e auth
- `customers` soft delete con hook che cascata su locations/subnets/vlans/ip_addresses (Customer).
- `locations` → customer; utenti e PAT standard Laravel/Sanctum.

IPAM
- `subnets`: rete decimale + mask con accessor/mutator CIDR; relazioni vlan, scanAgent, customer, location, parent/child; flag discovery/scan (Subnet).
- `ip_addresses`: decimale + ip_str, setter converte; relazioni subnet, device/switch, customer/location; enum `type` (IpAddress).
- `vlans`: scoped per customer.
- Legacy/gap: modello/seeder `sections` senza migration; VRF/L2Domain creati e poi droppati in migrazioni successive (vedi [[99_DEBT_AND_INCONSISTENCIES.md]]).

Discovery & agenti
- `scan_agents`: Authenticatable + Sanctum PAT, uniqueness agent attivo per customer nel boot (ScanAgent).
- `agent_jobs`: UUID PK, payload/result/attempts, lega device+scan_agent+initiator (AgentJob).
- `agent_logs`: log per agent con context JSON (AgentLog).
- `discovery_events`: staging/conflict per IP orfani o mismatch MAC (DiscoveryEvent).
- `credential_profiles`: credenziali cifrate riusabili (CredentialProfile).
- Settings: tabella `settings` (Spatie) gruppi `general`, `discovery`.

DCIM / fisico
- `device_models`: hardware tipizzato con porte e layout; quick-create normalizza enum (DeviceModel).
- `devices`: tenant-aware, inferisce customer da session/location/rack; genera porte on-create; campi monitor/auth (Device).
- `ports`: metadati VLAN/topologia, `tagged_vlans` array, `connected_to_port_id` (Port).
- `links`: connette due porte con cable_type e guardie self/duplicati; `cable_types` dictionary (Link, CableType).
- `racks`: relazione customer/location; devices appartengono a rack.

Supporto
- Code/queue: `jobs`, `failed_jobs`; cache; `notifications` per Filament; `settings` Spatie.
