# Changelog (Archived)

All notable changes to Modern IPAM are documented in this file (moved to `_DOCS/archive/CHANGELOG.md`).

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

---

... (contents moved)
