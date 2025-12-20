# Deploy server

Questa guida descrive il deploy del server NetVibe tramite Docker Compose.

## Prerequisiti
- Docker + Docker Compose v2
- DB raggiungibile dal container (nel compose è incluso un servizio DB)

## Procedura (as-is)
1. Configura `.env` a partire da `.env.example`.
2. Avvia lo stack con profile `prod` (vedi `compose.yml`).
3. Esegui migrazioni/seed e pulizia cache.

Per i comandi esatti (dev/prod/test) vedi: `SETUP.md`.
