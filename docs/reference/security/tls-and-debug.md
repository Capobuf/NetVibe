# TLS verification & debug endpoints

## Remote agent → NetVibe server

Il remote agent comunica con il server NetVibe via HTTP(S). La verifica TLS è **abilitata di default**.

Variabili ambiente:

- `NETVIBE_TLS_VERIFY` (`true|false`, default `true`): abilita/disabilita la verifica TLS.
- `NETVIBE_TLS_CA_BUNDLE` (path opzionale): se impostato e `NETVIBE_TLS_VERIFY=true`, il client usa questo file come CA bundle per la verifica.

Nota operativa:
- Disabilitare la verifica (`NETVIBE_TLS_VERIFY=false`) è pensato solo per sviluppo o ambienti controllati. In produzione è consigliato mantenere `true`.

## Remote agent → MikroTik devices

Per le chiamate REST verso MikroTik (se usate su HTTPS), la verifica TLS è **disabilitata di default** perché molti dispositivi usano certificati self-signed.

Variabili ambiente:

- `MIKROTIK_TLS_VERIFY` (`true|false`, default `false`)
- `MIKROTIK_TLS_CA_BUNDLE` (path opzionale): se impostato e `MIKROTIK_TLS_VERIFY=true`, il client usa questo file come CA bundle.

## Debug endpoints (server)

Le route sotto `/_debug/*` sono solo per diagnosi. In produzione rispondono **404** quando:

- l'app non è in ambiente `local`, **e**
- `app.debug` è `false`.

Route attuali:

- `/_debug/throw`
- `/_debug/exception-callbacks`
