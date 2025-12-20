# Testing & QA Guidelines — NetVibe IPAM/DCIM

Queste linee guida traducono il capitolo Testing/QA del PDL in istruzioni operative per chi sviluppa e contribuisce al repository.

## 1. Come scrivere un nuovo test

### 1.1 Unit test (Pest)

- Posizione: `tests/Unit/...Test.php`.  
- Struttura tipica:

```php
<?php

use App\Services\MergePolicyService;

it('prefers locked fields over remote', function () {
    $service = new MergePolicyService();

    $local = ['hostname' => 'manual', '_locked' => ['hostname' => true]];
    $remote = ['hostname' => 'router'];

    $merged = $service->merge($local, $remote);

    expect($merged['hostname'])->toBe('manual');
});
```

- Obiettivo: testare singole classi/servizi senza DB o con DB minimo (sqlite in-memory).

### 1.2 Integration test (Pest)

- Posizione: `tests/Integration/...Test.php`.  
- Usano DB test con `migrate:fresh` e, se necessario, il worker.  
- Esempio di scenario:

```php
it('creates an agent job when device discovery is triggered', function () {
    $device = Device::factory()->create([...]);
    $agent  = ScanAgent::factory()->create([...]);
    $device->update(['scan_agent_id' => $agent->id]);

    app(\App\Actions\Agent\DispatchDeviceJobAction::class)
        ->execute($device, type: 'discovery_deep', initiatedByUserId: $user->id);

    expect(AgentJob::where('device_id', $device->id)->where('type', 'discovery_deep')->exists())->toBeTrue();
});
```

### 1.3 Dusk test (UI E2E)

- Posizione: `tests/Browser/...Test.php`.  
- Esecuzione: `php artisan dusk`.  
- Esempio:

```php
public function test_setup_wizard_creates_admin_user(): void
{
    $this->browse(function (Browser $browser) {
        $browser->visit('/setup')
            ->assertSee('PHP Version')
            ->click('@run-migrations')
            ->type('name', 'Admin')
            ->type('email', 'admin@example.com')
            ->type('password', 'secret1234')
            ->type('password_confirmation', 'secret1234')
            ->press('Create Admin')
            ->assertPathIs('/');
    });
}
```

### 1.4 Snapshot test

- Posizione: `tests/Snapshots/...Test.php` (+ file in `tests/Snapshots/__snapshots__`).  
- Uso:
  - Filament table HTML/JSON.  
  - Risposte API critiche.  
- Strumento: package snapshot supportato da Pest (es. `spatie/pest-plugin-snapshots`).

## 2. Script e ambiente test

### 2.1 DB test e reset

- I database di test devono essere isolati da `main`.  
- Possibili DB:
  - `testing.sqlite` (o equivalente) per unit/feature test leggeri.  
  - `testing_full.sqlite` (o DB dedicato) per integration/distruttivi (wizard, reset, migrazioni complesse).
- Script ufficiali:
  - `./dev/test` — esegue lint + Pest + Dusk sull’ambiente di test completo.  
  - `./dev/test-fast` — esegue solo i test rapidi (unit + feature base).  
  - `./dev/test-ui` — UI/Filament tests + snapshot.  
  - `./dev/test-agent` — test agent-protocol + schema `device_result`.  
  - `./dev/test-ci` — entrypoint usato in CI.
- Ogni script deve:
  - verificare che i DB di test siano up;  
  - resettare il DB rilevante (`migrate:fresh --env=testing` o equivalente);  
  - eseguire le suite previste (Pest, Dusk, lint) in ordine chiaro.

### 2.2 Agent di test

- Configurare un container agent dedicato ai test con `.env.test-agent`, ad es.:

```env
SERVER_URL=http://app-test:8080
AGENT_API_TOKEN=...
LOG_LEVEL=debug
```

- Scenario tipico:
  - avviare l’app in ambiente `testing`.  
  - far girare l’agent test (in un container o processo separato) puntato a `SERVER_URL` di test.  
  - eseguire test integration che:
    - assegnano job a ScanAgent.  
    - aspettano che l’agent li consumi.  
    - verificano gli effetti in DB.

## 3. Come testare pipeline ingest/discovery

- Esempio: testare ingest Mikrotik ARP:
  - preparare un `Device` con customer/location e un `ScanAgent`.  
  - costruire una fixture `device_result` con `arp` e `dhcp_leases` normalizzati.  
  - chiamare direttamente `IngestMikrotikArpAction::execute($device, $deviceResultArray)`.  
  - verificare in DB:
    - IPAddress creati/aggiornati.  
    - eventuali DiscoveryEvent generati.  
    - rispetto di blacklist VLAN/Subnet.
- Per testare merge policy:
  - usare `MergePolicyService` in unit test con combinazioni di `locked`, `REST`, `SNMP`, timestamps diversi.  

## 4. Snapshot per UI Filament

- Per una Resource Filament critica (es. DiscoveryEventResource):
  - usare un test integration/snapshot che renderizza la Table (via `Livewire` o helper Filament) e confronta l’HTML/JSON con uno snapshot.  
  - quando un refactoring modifica intenzionalmente la UI, si aggiorna lo snapshot con consapevolezza.

## 5. Reset DB test e teardown

- Test destructivi (drop user, simulazione reset wizard, ecc.) devono usare sempre il DB test.  
- La suite `tests/Integration/Setup/*` può:
  - simulare primo setup.  
  - verificare reindirizzamenti di `CheckSystemInstalled`.  
  - poi lasciare il DB in stato pulito (tramite `migrate:fresh` tra i test, o usando transazioni/test database).

## 6. Linee guida generali

- Ogni nuova feature:
  - deve avere almeno un test unit/integration;  
  - se modifica UI Filament, deve avere almeno un test Dusk o snapshot per la vista principale.  
- Ogni bug fix:
  - deve aggiungere un test che fallisce con il codice vecchio e passa con il nuovo.  
- Refactoring:
  - non deve cambiare gli observable di test (o, se li cambia, deve aggiornare gli snapshot e i test con motivazione esplicita nel commit/PR).

## 7. Checklist qualità per il remote-agent

Gli agent devono rispettare una serie di vincoli per evitare regressioni o accoppiamenti sbagliati con il server:

- Nessun file creato fuori dalle cartelle specificate (`/agent/logs`, `/agent/cache`, ecc.); niente scritture in percorsi arbitrari.  
- Nessun riferimento a `docker-compose.yml`: la documentazione e gli script devono usare solo `compose.yml` / `docker compose`.  
- Nessun riferimento o dipendenza da Filament (l’agent è headless); in particolare nessun uso di componenti o snippet Filament v3.  
- Directory e naming conformi alla struttura target descritta nel PDL (Drivers/, Jobs/, Http/, Support/, config/, bin/, tests/, docs/).  
- `.env.example` dell’agent aggiornato e coerente con il protocollo (`SERVER_URL`, token, `LOG_LEVEL`, feature flags).  
- Tutti i job seguono la pipeline definita nel PDL (Mikrotik REST → `device_result`, SNMP Fase 1 come enrichment, chunking/gzip sopra soglia, error taxonomy unificata).  
- Agent e server rispettano `schema_version` e `min_supported_schema_version`; agent troppo vecchi ricevono errori 426 e non devono inviare payload legacy.  
- Test snapshot per le risposte chiave (schema `device_result`, error payload), in modo da rilevare cambiamenti non intenzionali nel contratto.
