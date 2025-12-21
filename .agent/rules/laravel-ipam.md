# Regole progetto — Laravel 12 + Filament v4 (NetVibe)


## Principi

- Il **PDL è la Source of Truth**.
- Niente assunzioni: ogni campo/API/feature deve essere supportata da codice o documentazione del repo.
- Evitare “workaround”: preferire soluzioni pulite, mantenibili e documentate.

## Livewire

- In generale: **evitare Livewire custom** fuori dal perimetro Filament.
- Eccezioni: ammesse solo se **documentate** (es. `Tenant Switcher` montato via hook Filament) e con controlli di autorizzazione/tenancy chiari.

## Qualità codice

- Niente logica applicativa “pesante” nei controller.
- Preferire metodi piccoli e testabili.
- Evitare duplicazioni: estrarre in Action/Service quando ricorre.
