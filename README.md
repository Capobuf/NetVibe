# NetVibe

NetVibe è un progetto **IPAM + DCIM** per MSP, con UI basata su **Filament** e un **remote agent** che comunica col server via **HTTP polling**.

## Documentazione

La documentazione “ufficiale” è tutta in **`docs/`**:
- Start here: `docs/README.md`
- Tutorial / How-to / Reference / Explanation (Diátaxis)
- Decisioni architetturali: `docs/adr/`

## Note sui test (sicurezza)

La suite di test usa `RefreshDatabase` e può ricreare/dropare le tabelle. Non eseguire i test su DB di produzione.

Nel progetto è prevista una configurazione dedicata per test (`netvibe_test` su `netvibe-test-db`).

