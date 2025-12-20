# Labeling & naming

## Obiettivo
Avere un sistema di etichettatura coerente e stampabile (browser/PDF) per:

- rack
- device
- porte
- cavi

## Approccio

- I pattern devono essere **configurabili dall'utente** (MSP), con default sensati.
- Le azioni pericolose (reset pattern, rigenerazione batch, apply massivo) devono avere doppio warning.

## Sezione dedicata "Naming"

Queste scelte devono stare in una sezione dedicata delle impostazioni:

- Customer Code (obbligatorio)
- Location Code (opzionale; se customer monosede può essere auto)
- Regole caratteri ammessi / lunghezze
- Schema ID cavo

## Consiglio pratico

Per evitare ambiguità in campo:

- Etichettare **entrambe** le estremità del cavo.
- Stampare anche la terminazione (es. `R01-U12 P03` → `R02-U03 P24`).

## Collegamenti

- Regole tecniche (as-is): `docs/reference/data-model/identifiers.md`
- Stampa etichette: `docs/how-to/printing/print-cable-labels.md`
