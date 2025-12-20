# NetVibe Documentation

Questa cartella è l'unica fonte di documentazione del progetto.

## Come leggere la documentazione (Diátaxis)

La documentazione è organizzata secondo **Diátaxis**:

- **Tutorials** (`docs/tutorials/`): percorsi guidati "end-to-end" per imparare e arrivare al primo risultato.
- **How-to** (`docs/how-to/`): procedure operative (task-oriented) da seguire quando devi fare qualcosa.
- **Reference** (`docs/reference/`): riferimento "as-is" (fatti verificabili nel codice). Niente roadmap qui.
- **Explanation** (`docs/explanation/`): contesto, rationale, roadmap ed epiche.

Le **decisioni architetturali** sono tracciate in `docs/adr/` (Architecture Decision Records).

## Regole di coerenza (Source of Truth)

- **Reference** descrive *solo* ciò che il codice fa oggi.
- **Roadmap/Epics** descrivono ciò che vogliamo fare (o che manca) e includono criteri di accettazione.
- Se una modifica cambia un assunto architetturale: aggiungi/aggiorna un **ADR**.

## Indici rapidi

- PDL (work-in-progress): `docs/explanation/pdl.md`
- Roadmap & Epiche: `docs/explanation/roadmap/`
- Stato implementazione (matrice feature): `docs/reference/status/implementation-status.md`
- Tenancy (customer scoping): `docs/reference/architecture/tenancy.md`
- Protocollo agent (server↔agent): `docs/reference/api/agent-protocol.md`
- Blacklist (hard exclusion): `docs/reference/config/blacklist.md`
- Soft-retire (lifecycle): `docs/reference/operations/soft-retire-policy.md`
- TLS & debug endpoints: `docs/reference/security/tls-and-debug.md`

## Documentazione vendor

I riferimenti upstream (solo consultazione) sono in:

- Laravel v12: `docs/vendor/laravelv12/`
- Filament v4: `docs/vendor/filamentv4/`
