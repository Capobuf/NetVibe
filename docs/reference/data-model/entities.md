# Data model (entities)

Questa pagina descrive le entità principali e la loro ownership (global vs customer-scoped).

## Global (shared)

- Manufacturer
- DeviceModel
- CableType
- LabelTemplate

## Customer-scoped

Tutte queste entità hanno `customer_id`:

- Customer
- Location
- ScanAgent
- Subnet
- VLAN
- IpAddress
- Device
- Port
- Rack
- Cable
- Circuit/WAN
- DiscoveryEvent

## Lifecycle

`IpAddress`, `Device`, `Port` hanno campi lifecycle (Active/Stale/Retired). Vedi: `docs/reference/operations/soft-retire-policy.md`.
