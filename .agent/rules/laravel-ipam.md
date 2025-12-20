# Regole progetto — Laravel 12 + Filament v4 (NetVibe)

Queste regole servono a mantenere **coerenza con il PDL** (`docs/PDL.md`) e con la struttura reale del repository.

## Principi

- Il **PDL è la Source of Truth**.
- Niente assunzioni: ogni campo/API/feature deve essere supportata da codice o documentazione del repo.
- Evitare “workaround”: preferire soluzioni pulite, mantenibili e documentate.

## Struttura Laravel

- Logica di dominio e trasformazioni: `app/Actions/`
- Servizi/integrazioni: `app/Services/`
- Data objects/DTO: `app/Data/`
- Scanner/parsing: `app/Scanners/`
- Policy e autorizzazioni: `app/Policies/` (o dove previsto dal framework)

## Filament v4

- Le risorse Filament stanno in `app/Filament/Resources/` (come previsto dal PDL).
- UI: seguire le linee guida in `docs/05_UI_UX_FILAMENT.md`.
- Evitare CSS custom.

## Livewire

- In generale: **evitare Livewire custom** fuori dal perimetro Filament.
- Eccezioni: ammesse solo se **documentate** (es. `Tenant Switcher` montato via hook Filament) e con controlli di autorizzazione/tenancy chiari.

## TODO / FIXME

- Ammessi solo quando necessari, con contesto e riferimento tracciabile:
  - preferibile: `TODO[#123]: ...`
  - oppure: `TODO(PDL): ...` con riferimento al capitolo/paragrafo

## Database & Tenancy

- Tabelle e naming coerenti con `docs/02_DATABASE_SCHEMA.md`.
- Migrazioni sempre deterministiche (niente dipendenze da stato locale).
- Qualsiasi dato tenant-scoped deve essere protetto da:
  - filtro tenant nel modello/query
  - policy/autorizzazione in Filament e API

## Qualità codice

- Niente logica applicativa “pesante” nei controller.
- Preferire metodi piccoli e testabili.
- Evitare duplicazioni: estrarre in Action/Service quando ricorre.
- Log significativo e non verboso (seguire `docs/06_OBSERVABILITY_AND_TESTS.md`).
