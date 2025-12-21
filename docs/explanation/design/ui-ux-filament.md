UI / UX (Filament v4 & Livewire)

NetVibe utilizza esclusivamente **Filament v4** come framework UI. Tutti i componenti, le risorse e i pattern UX devono rispettare le convenzioni di Filament v4; qualsiasi sintassi o componente Filament v3 è proibito.

Panel & navigazione
- Admin panel top-nav con gruppi icone; hook inserisce TenantSwitcher prima della global search (AdminPanelProvider, TenantSwitcher Livewire).
- Risorse scoperte nei cluster: Racks & Devices, Discovery, Organization, IPAM, Infrastructure.

IPAM
- SubnetResource: ValidCidr, eredità customer da VLAN, toggle discovery, scelta agent con hint multi-agent; tabella raggruppata per VLAN con badge utilizzo; infolist link a VLAN/Customer/Location e gateway calcolato.
- IpAddresses relation manager: badge tipo/icone, last_seen formattato, prefill next free IP, create/edit slideOver. IpAddressResource standalone nascosta dalla nav.
- VLAN/Section risorse presenti; Section table mancante (vedi [[99_DEBT_AND_INCONSISTENCIES.md]]).

Discovery
- ScanAgentResource: toggle active, last_seen, relation manager per log e jobs; edit page con modal “Manage API Key” (revoca token e genera PAT, mostra base API endpoint).
- DiscoveryEventResource: elenco conflitti/orfani, filtri stato, bulk approve/discard (approve aggiorna IpAddress e marca APPROVED).
- ManageDiscoverySettings: modifica Spatie DiscoverySettings (auto discovery, ICMP, pruning, polling).

Racks & Devices
- DeviceResource principale in Racks & Devices; il duplicato Infrastructure (`infra-devices`) è stato rimosso.
- Device form: tenant default, filtri rack, quick-create rack/model, monitoring/auth, agent selection hint, credential profile, Mgmt IP.
- Ports relation manager: connect/disconnect con ManageLinkService, mostra VLAN/tagging, richiede porte libere.
- CRUD per Racks, CableTypes, Links (schema/table condivisi).

Settings & widget
- GeneralSettingsPage: site name/color, alert level agent, time display format, app_url/force_https.
- Widget: StatsOverview, TopUtilizedSubnets, LatestIpChanges, LatestScanResults per dashboard.
