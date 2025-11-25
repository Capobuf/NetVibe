# PROJECT SETUP & ARCHITECTURE

## 1. Project Overview
**Project Name:** Modern IPAM (Laravel 12 + Filament 4)  
**Description:** Clone moderno di phpIPAM con architettura Laravel moderna.  
**Philosophy:** "Strict Replica" - L'interfaccia replica il workflow phpIPAM originale.  

**Current Status:** ✅ **Phases 1-4 COMPLETED** | 🔄 **Phase 5 IN PROGRESS**

**Stack (Verified Versions):**
- Laravel 12.40.1
- FilamentPHP 4.2.3
- PHP 8.4.15
- MariaDB 11.8 LTS
- Nginx 1.28.0
- Redis 8.4.0
- Docker Compose

**Key Dependencies:**
- `mlocati/ip-lib` 1.22.0 - IP address manipulation
- `spatie/laravel-data` 4.18.0 - DTO pattern

---

## 2. DIRECTORY STRUCTURE (Implemented)

```text
app/
├── Actions/                 # [LOGIC] Business logic actions
├── Data/                    # [DTO] Data Transfer Objects
├── Filament/                # [UI] Filament admin panel
│   ├── Resources/           # CRUD resources (6 implemented)
│   │   ├── ScanAgents/      ✅ Scan agent management
│   │   ├── Sections/        ✅ Network sections
│   │   ├── Subnets/         ✅ Subnet management
│   │   ├── IpAddresses/     ✅ IP address inventory
│   │   ├── Devices/         ✅ Device management
│   │   └── Vlans/           ✅ VLAN management
│   └── Clusters/            # Menu hierarchies (future)
├── Models/                  # [ELOQUENT] 9 models with relationships
├── Services/                # [EXTERNAL] External service wrappers
│   ├── IpConversionService.php      ✅ IP decimal conversion
│   └── Scanners/
│       └── FpingWrapper.php         ✅ Network scanning
└── Database/
    ├── migrations/          # 9 IPAM tables + 3 Laravel defaults
    └── seeders/             # Default data (ScanAgent, Sections, Admin)
```

---

## 3. COMPLETED PHASES

### ✅ PHASE 1: REVERSE ENGINEERING (Completed)
- [x] Analisi sorgenti phpIPAM
- [x] Mappatura UI completa in `PHPIPAM_UI_MAP.md`
- [x] Identificazione workflow scan (Tools → Scan → Agent Selection)
- [x] Documentazione struttura menu e tabelle

**Output:** `PHPIPAM_UI_MAP.md` (964 righe)

---

### ✅ PHASE 2: ENVIRONMENT SETUP (Completed)
- [x] Docker Compose con 5 servizi (app, webserver, db, redis, node)
- [x] PHP 8.4-FPM con estensioni richieste
- [x] Laravel 12.40.1 installato
- [x] FilamentPHP 4.2.3 configurato
- [x] MariaDB 11.8 LTS ottimizzato per IPAM
- [x] Nginx 1.28 configurato
- [x] Redis 8.4 per cache/sessioni

**Access:** `http://localhost:8080/admin`

---

### ✅ PHASE 3: DATABASE SCHEMA (Completed)
- [x] 9 tabelle IPAM migrate:
  - `customers` - Gestione clienti/tenant
  - `sections` - Organizzazione network
  - `scan_agents` ⚡ - Agenti scansione (Localhost default)
  - `locations` - Locazioni fisiche
  - `devices` - Inventario dispositivi con SNMP
  - `vlans` - Gestione VLAN
  - `vrfs` - VRF configuration
  - `subnets` - Subnet con 48 campi (hierarchical support)
  - `ip_addresses` - IP addresses con 31 campi (decimal storage)

- [x] 12 Foreign Key constraints
- [x] 20+ performance indexes
- [x] Seed data: 1 Localhost agent + 2 sections + 1 admin user

**Statistics:** 12 tables, 164 columns, ~2.4s migration time

---

### ✅ PHASE 4: MODELS & FILAMENT (Completed)
- [x] 9 Eloquent Models con relazioni (30+ relationship methods)
- [x] `IpConversionService` con 8 metodi utility:
  - `toDecimal()` / `fromDecimal()` - IP ↔ Decimal
  - `parseCidr()` / `toCidr()` - CIDR parsing
  - `calculateUsableHosts()` - Subnet calculations
  - `isIpInSubnet()` - IP validation

- [x] 6 Filament Resources auto-generati
  - Forms + Tables + Pages (List/Create/Edit)
  - Routes attive su `/admin/*`

**Total Files:** ~40 files, ~600 lines of code

---

### 🔄 PHASE 5: SCAN ACTIONS & UI (In Progress)
**Completed:**
- [x] `FpingWrapper` service (scan CIDR + range)
- [x] IP Address display transformation (decimal → readable)
- [x] State badges con colori:
  - 🔴 Offline (1) - danger
  - 🟢 Used (2) - success
  - 🔵 Reserved (3) - primary
  - ⚪ DHCP (4) - gray

**Remaining:**
- [ ] Subnet CIDR display (`192.168.1.0/24`)
- [ ] "Scan Subnet" Filament Action
- [ ] Scan results review modal
- [ ] Import discovered IPs workflow

---

## 4. ARCHITECTURE DECISIONS

### IP Storage Format
**Decision:** Decimal storage (matching phpIPAM)  
**Rationale:** Efficient indexing, range queries, mathematical operations  
**Example:** `192.168.1.1` → `3232235777`

### Scan Agent Architecture
**Decision:** Support local + remote agents (API/MySQL)  
**Implementation:** `scan_agents` table with type field  
**Default:** Localhost agent (ID=1, type=direct)

### UI Philosophy: Strict Replica
- No invented shortcuts or modern additions
- Scan workflow requires agent selection (like phpIPAM)
- CIDR notation for subnets
- Conditional columns on IP table (future)
- Hierarchical subnet tree view (future)

---

## 5. ADMIN ACCESS

**URL:** `http://localhost:8080/admin`

**Login:**
```
Email: admin@modernipam.local
Password: password
```

---

## 6. NEXT STEPS (Future Development)

### Phase 5 Completion (~1 hour)
- Finish CIDR display on subnets
- Implement scan action button
- Add scan results review workflow
- Test full scan cycle

### Phase 6: Advanced Features (~3-5 hours)
- Hierarchical subnet tree view
- Conditional IP table columns
- Remote scan agent support (API)
- SNMP scanning integration
- Bulk IP import/export
- DNS PTR management
- IP request workflow
- Changelog tracking

### Phase 7: Production Readiness
- Asset compilation (`npm run build`)
- Laravel optimization commands
- Backup/restore procedures
- API documentation
- User authentication & permissions
- Multi-tenancy (if required)

---

## 7. TECHNOLOGY DECISIONS LOG

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **PHP Version** | 8.4.15 | Latest stable, performance improvements |
| **Database** | MariaDB 11.8 LTS | 3-year support, MySQL compatibility |
| **IP Library** | mlocati/ip-lib | Best IPv4/IPv6 support in PHP |
| **Frontend** | Filament 4 | Admin panel with TALL stack |
| **Caching** | Redis 8.4 | Session + cache driver |
| **Web Server** | Nginx 1.28 | Performance, FastCGI support |

---

## 8. FILE LOCATIONS

**Documentation:**
- `PROJECT_SETUP.md` - This file
- `README.md` - Quick start guide
- `CHANGELOG.md` - Version history
- `PHPIPAM_UI_MAP.md` - UI reverse engineering
- `.gemini/antigravity/brain/.../` - Walkthrough artifacts

**Configuration:**
- `.env` - Environment variables
- `docker-compose.yml` - Docker stack
- `docker/` - Docker configs (PHP, Nginx, MySQL)

**Code:**
- `app/Models/` - Eloquent models
- `app/Services/` - Business services
- `app/Filament/Resources/` - Admin UI
- `database/migrations/` - Database schema

---

**Last Updated:** 2025-11-25  
**Project Status:** ✅ Functional MVP | 🔄 UI Refinement In Progress