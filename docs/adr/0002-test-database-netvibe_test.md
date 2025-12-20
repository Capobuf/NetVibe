# ADR 0002: Test database naming (netvibe_test)

## Status
Accepted

## Context
I test usano `RefreshDatabase` e non devono mai colpire DB reali.

## Decision
- Il DB test canonico si chiama `netvibe_test`.
- I test puntano al servizio MariaDB dedicato `netvibe-test-db`.

## Consequences
- `tests/Pest.php` forza DB host e nome.
- `scripts/run-tests.sh` e la documentazione seguono questa convenzione.
