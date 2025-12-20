# ADR 0001: Docker Compose profiles and service names

## Status
Accepted

## Context
Il progetto usa Docker Compose per avvio locale e runtime single-node. La configurazione prevede profili (`dev`, `prod`, `worker`, `test`) e naming coerente con il PDL.

## Decision
- I servizi canonici sono: `netvibe-web`, `netvibe-worker`, `netvibe-db`, `netvibe-redis`, `netvibe-test-db`.
- I servizi sono definiti sotto **profiles**: è necessario usare `docker compose --profile ...`.

## Consequences
- Documentazione e script usano i service names canonici.
- `docker compose up -d` senza profili non avvia nulla (comportamento atteso).
