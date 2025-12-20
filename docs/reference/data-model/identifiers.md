# Identifiers & naming

Questa sezione raccoglie **regole tecniche** per identificatori e naming.

## Principi
- Gli identificatori devono essere **stabili** (non cambiare se cambia la descrizione).
- Devono essere stampabili su etichette (12mm/24mm).
- Devono essere univoci nel perimetro definito (customer, location...).

## Sezione configurabile

I formati (Customer Code, Location Code, schema ID cavo/porta) sono configurabili e devono stare nelle impostazioni. Il rationale e gli esempi stanno in `docs/explanation/design/labeling-and-naming.md`.

## Placeholders consigliati

- `CUSTOMER_CODE` (breve, maiuscolo, 3–10 char)
- `LOCATION_CODE` (breve, 2–8 char)
- `RACK_CODE` (breve, es. R01)
- `DEVICE_CODE` (breve)
- `PORT_CODE` (normalizzato)

## Cables

Scelta consigliata: ID cavo sequenziale per customer con prefisso (es. `ACME-CAB-000123`) e terminazioni stampate a parte.
