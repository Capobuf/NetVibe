# Review proposals and merge (Discovery Events)

## Dove
Filament → **Discovery** → **Discovery Events**.

## Cosa vedi
Ogni record rappresenta un conflitto o una proposta (es. MAC mismatch, hostname change) generata dall'ingest.

Campi chiave:
- Agent
- Time
- IP
- MAC / Hostname
- Conflict type
- Status: PENDING / APPROVED / DISCARDED

## Approvazione (as-is)
È presente una bulk action **Approva e Unisci** che:
- cerca l'IP all'interno del **customer dell'evento**
- aggiorna MAC/hostname solo se presenti
- marca l'evento come APPROVED

Nota: la merge policy "solo campi vuoti" è implementata per alcuni path, ma non è globalmente garantita per ogni attributo (vedi `docs/reference/status/known-gaps.md`).
