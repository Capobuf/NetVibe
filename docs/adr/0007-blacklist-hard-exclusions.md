# 0007 - Blacklist hard exclusions (VLAN/Subnet/IP)

**Status:** Accepted

## Context
In fase di import da MikroTik l'operatore deve poter escludere in modo deterministico VLAN/Subnet/IP. La blacklist deve funzionare anche prima che l'oggetto esista in DB.

## Decision
- VLAN blacklist modellata su numero VLAN, non su FK verso `vlans`:
  - tabella `vlan_blacklists` con `(customer_id, vlan_number)` (+ nome opzionale e metadata).
- Subnet/IP blacklist basata su CIDR e range (dove previsto dalla UI).
- Comportamento: elemento in blacklist **non viene importato**.

## Consequences
- La blacklist sopravvive a delete di VLAN/Subnet.
- In ingest è necessario consultare la blacklist prima di creare record.
