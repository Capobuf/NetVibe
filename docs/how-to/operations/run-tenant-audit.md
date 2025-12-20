# Eseguire l'audit di integrità tenant

Questa procedura serve a verificare che il database non contenga condizioni che possono causare **contaminazione cross-tenant** (es. record senza `customer_id`, mismatch tra tabelle, duplicati).

> È un controllo **fast-fail**: se trova problemi, il comando termina con exit code diverso da 0.

## Quando usarlo

- Prima di un deploy / migrazione importante
- Dopo import massivi / ingest
- In CI/CD, come check “pre-flight”

## Esecuzione

Nel container `netvibe-web` (o equivalente) esegui:

```bash
php artisan tenant:audit
```

Per output JSON (utile in pipeline):

```bash
php artisan tenant:audit --json
```

## Cosa controlla

- Record con `customer_id` NULL nelle tabelle tenant-scoped
- Mismatch tra `customer_id` su tabelle collegate (es. `ip_addresses` vs `subnets`, `ports` vs `devices`)
- Duplicati per tenant (es. `(customer_id, ip_addr)`)

## Risultato

- **OK**: nessun problema rilevato
- **FAIL**: stampa una tabella con tipo problema, tabella, conteggio e dettagli
