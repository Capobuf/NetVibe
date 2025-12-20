# Architecture overview

NetVibe è un monolite Laravel + Filament con un **remote agent** opzionale.

Componenti:
- **Server** (`netvibe-web`): UI Filament + API (`/api/v1/...`)
- **Worker** (`netvibe-worker`): queue worker Laravel
- **DB** (`netvibe-db`) e **Redis** (`netvibe-redis`)
- **Test DB** (`netvibe-test-db`): solo per test (profile `test`)
- **Remote agent** (`remote-agent/`): processo esterno che fa polling API e interagisce con device (Mikrotik)

Multi-tenancy: soft tenancy via `customer_id` + `tenant_id` (vedi PDL).
