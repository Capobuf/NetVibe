# Blacklist (hard exclusion)

## Obiettivo
Escludere VLAN/Subnet/IP dall'import (MikroTik) in modo deterministico. Un elemento in blacklist **non viene creato** e non viene aggiornato.

## VLAN blacklist

- Tabella: `vlan_blacklists`
- Chiave: `(customer_id, vlan_number)`
- Campi accessori: `vlan_name`, `reason`, metadata.

Perché `vlan_number` e non FK: la blacklist deve esistere anche quando la VLAN non esiste ancora in DB.

## Subnet/IP blacklist

- Tabella: `subnet_blacklists` (CIDR/range dove previsto)

## UI (Filament)
La gestione avviene tramite risorse dedicate "Blacklist" (per customer).
