NetVibe Knowledge Base (entry point)

Router rapido
- Avvio stack / Docker / agent → [[01_INFRASTRUCTURE.md]]
- Schema DB e tenancy → [[02_DATABASE_SCHEMA.md]]
- Logica IPAM (scan, discovery, IP) → [[03_CORE_LOGIC_IPAM.md]]
- Logica DCIM (rack/device/porte/topologia) → [[04_CORE_LOGIC_DCIM.md]]
- UI Filament v4 / Livewire custom → [[05_UI_UX_FILAMENT.md]]
- Logging, observabilità, test → [[06_OBSERVABILITY_AND_TESTS.md]]
- Debito, zombie code, incongruenze → [[99_DEBT_AND_INCONSISTENCIES.md]]
- Piano di lavoro corrente → [[docs/PDL.md]]

Architettura in breve
- Monolite Laravel 12 + Filament v4 (top navigation) su php-fpm/nginx base ServerSideUp; Redis per cache/session, MariaDB per dati (docker-compose).
- Tenancy soft: scope `TenantAware` su `customer_id` attivo quando `session('tenant_id')` è valorizzato; niente multi-DB.
- Discovery/monitoring: Remote Agent PHP standalone (`remote-agent/`) che parla HTTP/S al control plane (Sanctum bearer) e ai device via REST Mikrotik o fping legacy.
- Async: queue worker Laravel (`netvibe-worker`) per job app; agent jobs orchestrati in `agent_jobs` con callback HTTP dal remote agent.
- Settings via `spatie/laravel-settings` (gruppi `general`, `discovery`).
