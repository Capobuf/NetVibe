# NetVibe — Documentazione

## Fonte ufficiale

- **[Specifica funzionale e UX](specification/NETVIBE_SPEC.md)** — documento canonico. Le aree A01–A13 derivano integralmente dalla specifica di lavoro v0.1 su Google Drive (26 ottobre 2026); A14 registra le decisioni operative successive già espresse dal Product Owner.
- **[Baseline tecnica — bozza](specification/TECHNICAL_BASELINE_DRAFT.md)** — ipotesi tecniche ancora da valutare. Non autorizza l'implementazione delle parti indicate come proposte.

## Tutela della specifica

Tutti i file in `docs/specification/`, questo indice, `AGENTS.md` e `.github/CODEOWNERS` richiedono **autorizzazione esplicita del Product Owner prima di qualsiasi modifica**, inclusi spostamenti, formattazioni e correzioni minori. L'agent deve prima illustrare la modifica proposta, l'impatto e le possibili incongruenze. Vedere [AGENTS.md](../AGENTS.md).

`.github/CODEOWNERS` identifica il responsabile delle revisioni; **non blocca autonomamente i push o i merge**. Per un vincolo tecnico serve configurare le protezioni del branch su GitHub.

## Materiale storico (non normativo)

I documenti preesistenti come `docs/PROJECT_SPECIFICATIONS.md` e `docs/MASTER_ARCHITECTURE.md`, nonché il codice e gli altri riferimenti nel vecchio repository, appartengono a versioni precedenti di NetVibe. Possono descrivere architetture o funzionalità oggi escluse (per esempio Filament, remote agent, Redis e discovery distribuita). **Non devono essere impiegati come requisiti, stack approvato o esempi di implementazione** salvo richiesta esplicita del Product Owner.
