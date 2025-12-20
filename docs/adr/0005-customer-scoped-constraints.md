# 0005 - Customer-scoped constraints

**Status:** Accepted

## Context
In contesto MSP, IP/MAC/VLAN possono duplicarsi tra clienti. Senza vincoli DB, una regressione di codice può reintrodurre contaminazione cross-tenant.

## Decision
Applicare vincoli e indici per rendere esplicito l'ownership per customer:

- `customer_id NOT NULL` sulle entità customer-scoped principali (subnets, vlans, ip_addresses, devices, racks, ports, discovery_events).
- Indici/unique per customer dove appropriato (es. VLAN: `(customer_id, number)`).
- Migrazioni "fast-fail" che interrompono l'upgrade se esistono record non assegnabili senza ambiguità.

## Consequences
- Maggiore sicurezza contro regressioni.
- Migrazioni possono fallire su database legacy "sporchi" e richiedere remediation manuale.
