# Changelog

All notable changes to Modern IPAM will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] - 2025-11-25 (Current - In Progress)

### Phase 5: Scan Actions & UI Customization

**Added:**
- FpingWrapper service for network scanning (`app/Services/Scanners/FpingWrapper.php`)
  - Subnet scanning with CIDR notation support
  - IP range scanning (start → end)
  - Configurable timeout and retry settings
- IP Address display transformation in Filament tables
  - Decimal to human-readable format conversion
  - Automatic formatting using IpConversionService
- State badges with color coding in IP Address Resource
  - 🔴 Offline (1) - Red/Danger
  - 🟢 Used (2) - Green/Success
  - 🔵 Reserved (3) - Blue/Primary
  - ⚪ DHCP (4) - Gray/Secondary
- Improved IP Address table columns
  - Relationship columns (subnet, location)
  - Toggleable columns for better UX
  - MAC address and hostname display

**In Progress:**
- Subnet CIDR display (`192.168.1.0/24` notation)
- "Scan Subnet" Filament Action with scan agent selection
- Scan results review modal
- Import discovered IPs workflow

---

## [0.4.0] - 2025-11-25

### Phase 4: Models & Filament Resources

**Added:**
- 9 Eloquent Models with complete relationships:
  - `Subnet` (9 relationships, hierarchical support)
  - `IpAddress` (4 relationships)
  - `ScanAgent`, `Section`, `Customer`, `Location`, `Device`, `Vlan`, `Vrf`
  - Total: 30+ relationship methods
- IpConversionService with 8 utility methods:
  - `toDecimal()` - Convert IP to decimal format
  - `fromDecimal()` - Convert decimal to IP
  - `parseCidr()` - Parse CIDR notation
  - `toCidr()` - Build CIDR from subnet + mask
  - `calculateUsableHosts()` - Calculate subnet capacity
  - `isIpInSubnet()` - Check IP membership
- 6 Filament Resources (auto-generated):
  - ScanAgentResource (`/admin/scan-agents`)
  - SubnetResource (`/admin/subnets`)
  - IpAddressResource (`/admin/ip-addresses`)
  - SectionResource (`/admin/sections`)
  - DeviceResource (`/admin/devices`)
  - VlanResource (`/admin/vlans`)
  - Each with List, Create, Edit pages

**Changed:**
- Model fillable properties configured for mass assignment
- Type casts implemented for booleans and datetimes
- Relationship methods following Laravel conventions

**Statistics:**
- ~40 files created
- ~600 lines of code
- 20+ admin routes active

---

## [0.3.0] - 2025-11-25

### Phase 3: Database Schema

**Added:**
- 9 IPAM table migrations:
  - `customers` - Customer/tenant management (15 fields)
  - `sections` - Network sections (14 fields)
  - `scan_agents` - Scan agent configuration (7 fields) ⚡
  - `locations` - Physical locations (7 fields)
  - `devices` - Network devices with SNMP (35 fields)
  - `vlans` - VLAN management (7 fields)
  - `vrfs` - VRF configuration (7 fields)
  - `subnets` - IP subnets (48 fields) - Most complex
  - `ip_addresses` - Individual IPs (31 fields) - Core IPAM
- 12 Foreign Key constraints
- 20+ performance indexes
- Database seeders:
  - `ScanAgentSeeder` - Default "Localhost" agent
  - `SectionSeeder` - 2 default sections (Customers, IPv6)
  - `AdminUserSeeder` - Admin user for panel access

**Technical Details:**
- IP addresses stored in decimal format (e.g., `192.168.1.1` → `3232235777`)
- Hierarchical subnet support via `master_subnet_id`
- Scan agent integration with `scan_agent_id` foreign key
- Complete SNMP configuration fields on devices table
- Total migration time: ~2.4 seconds

**Statistics:**
- 12 tables (3 Laravel + 9 IPAM)
- 164 total columns
- ~500 lines of migration code

---

## [0.2.0] - 2025-11-25

### Phase 2: Environment Setup

**Added:**
- Docker Compose stack with 5 services:
  - `app` - PHP 8.4-FPM with custom Dockerfile
  - `webserver` - Nginx 1.28.0 optimized for Laravel
  - `db` - MariaDB 11.8 LTS with IPAM tuning
  - `redis` - Redis 8.4.0 for cache/sessions
  - `node` - Node.js 22 LTS for asset compilation
- Custom PHP 8.4-FPM Dockerfile with extensions:
  - pdo_mysql, gd, redis, bcmath, zip, intl
  - System tools: fping, iputils-ping, nmap
- Laravel 12.40.1 installation
- FilamentPHP 4.2.3 with admin panel
- Required dependencies:
  - `mlocati/ip-lib` 1.22.0
  - `spatie/laravel-data` 4.18.0
- Configuration files:
  - `.env.example` with Modern IPAM settings
  - `docker/nginx/default.conf` - Web server config
  - `docker/mysql/my.cnf` - Database optimization
  - `docker/php/local.ini` - PHP settings
- Project structure directories:
  - `app/Actions`, `app/Data`, `app/Services/Scanners`
- Documentation:
  - `README.md` - Quick start guide
  - `.gitignore` for Laravel + Docker

**Technical Stack Versions:**
- PHP: 8.4.15
- Laravel: 12.40.1
- FilamentPHP: 4.2.3
- MariaDB: 11.8 LTS
- Nginx: 1.28.0
- Redis: 8.4.0
- Node.js: 22 LTS

---

## [0.1.0] - 2025-11-25

### Phase 1: Reverse Engineering

**Added:**
- Complete UI mapping document: `PHPIPAM_UI_MAP.md` (964 lines)
  - Menu structure analysis (Tools, Administration, Subnets)
  - Scan workflow documentation
  - IP Address table column mapping (15+ fields)
  - Conditional column display rules
  - VLAN/VRF management workflows
- Architecture documentation: `PROJECT_SETUP.md`
  - Directory structure planning
  - Scan agent architecture decisions
  - "Strict Replica" UI philosophy
  - Technology stack requirements
- FilamentPHP mapping strategy:
  - Clusters for menu hierarchy
  - Resources for CRUD operations
  - Actions for scan workflows

**Research:**
- Analyzed phpIPAM source code for UI patterns
- Identified scan agent selection requirement
- Documented IP decimal storage format
- Mapped conditional table column logic

---

## Technical Decisions Log

| Component | Choice | Version | Rationale |
|-----------|--------|---------|-----------|
| PHP | 8.4.15 | Latest | Performance, modern syntax |
| Laravel | 12.40.1 | Latest stable | Long-term support |
| FilamentPHP | 4.2.3 | Latest stable | Best admin panel for Laravel |
| Database | MariaDB | 11.8 LTS | 3-year support, MySQL compatible |
| Cache | Redis | 8.4.0 | Fast session + cache driver |
| Web Server | Nginx | 1.28.0 | Performance, FastCGI support |
| IP Library | mlocati/ip-lib | 1.22.0 | Best IPv4/IPv6 support in PHP |
| DTO | spatie/laravel-data | 4.18.0 | Type-safe data objects |

---

## Upcoming Releases

### [0.6.0] - Planned
**Phase 5 Completion:**
- Subnet CIDR display implementation
- Complete Scan Subnet action with agent selection
- Scan results review modal
- Import discovered IPs workflow
- Performance optimizations

### [0.7.0] - Planned
**Advanced Features:**
- Hierarchical subnet tree view
- Conditional IP table columns
- Remote scan agent support (API)
- SNMP scanning integration
- Bulk IP import/export

### [1.0.0] - Planned
**Production Ready:**
- Complete feature parity with phpIPAM
- Asset compilation and optimization
- Comprehensive testing suite
- API documentation
- User authentication & permissions
- Deployment documentation

---

**Legend:**
- ✅ Completed
- 🔄 In Progress
- 📋 Planned
