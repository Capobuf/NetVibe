# Vision and scope

## Visione

NetVibe è un'applicazione **MSP-only** per gestire, per ogni cliente:

- IPAM (subnet, IP, VLAN, ruoli)
- DCIM (rack, device, porte, cablaggio, circuiti WAN)
- Discovery automatica tramite agent (outbound-only) con proposta merge controllata

## Scope Fase 1 (vincoli confermati)

- **Solo MikroTik** (RouterOS API) come sorgente discovery.
- **Nessun IPv6** (mai).
- **1 agent container per customer**. La raggiungibilità verso i dispositivi è gestita fuori da NetVibe (VPN/overlay).
- **Automerge "safe"**:
  - crea record se non esistono
  - aggiorna solo campi vuoti
  - append note / tag VLAN / alias MAC dove non distruttivo
  - altrimenti proposta
- **Blacklist hard** (VLAN/Subnet/IP) = elementi esclusi dall'import.
- **Soft-retire** (stale/retired) per ciò che non viene più trovato, configurabile.

## Non-obiettivi

- WebSocket (comunicazione server↔agent resta su HTTP polling).
- Enrollment agent "request/approve" (resta token-based).

## Dove sta cosa nella doc

- Stato attuale: `docs/reference/`
- Roadmap ed epiche: `docs/explanation/roadmap/`
- ADR: `docs/adr/`
