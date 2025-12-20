# Run discovery on-demand

## Stato nel codice (as-is)
Nel repository esiste un job `ScanSubnetJob` che esegue una scansione ICMP (via `FpingWrapper`) su una subnet e aggiorna/crea IP.

**Non** è presente, in questa versione, un bottone UI dedicato che dispacci automaticamente questo job.

## Esecuzione manuale (CLI)
Esempio (queue):

```bash
php artisan tinker
```

```php
$subnet = \App\Models\Subnet::find(1);
\App\Jobs\ScanSubnetJob::dispatch($subnet);
```

Requisiti:
- la subnet deve avere `customer_id`
- `discover_subnet` deve essere true (altrimenti il job skip)

## Output
Gli esiti sono nei log app e nei campi `last_discovery` della Subnet.
