# Features & Roadmap

> **Navigation:** [← README](README.md) | [Architecture](ARCHITECTURE.md) | [Issues](ISSUES.md) | [📚 Docs Map](NAVIGATION.md)

---

## ✅ Implemented Features (V1.0)

### 1. Core IPAM (IPv4)
- **Subnets:** Full hierarchical management (Master/Nested), CIDR parsing and validation.
- **IP Addresses:** Stored as decimal for efficient range queries and indexing.
- **VRF support:** Logical segmentation for multi-tenant networks.
- **Pre-fill:** Async pre-fill jobs to populate subnets with IP addresses.

### 2. Rack Management
- **Racks:** Full 42U rack support, units, and location linking.
- **Devices & Models:** Device models with U sizes, device inventory and per-rack positioning.
- **Visual Rack UI:** 42U visualizations showing device placement, spanning and interactive create/edit (URL-based drag/placement flow).
- **Locations:** Physical sites linked to Customers.
- **Customers:** Multi-tenant support for assets.
- **VLANs:** L2 Domain support, unique constraints.

### 3. Discovery & Scanning
- **Scan Agents:** Local and pluggable agents for scanning.
- **Fping wrapper:** Lightweight wrapper (`FpingWrapper`) to run ICMP scans via `fping`.
- **Jobs & Queue:** Scans are dispatched to the queue and stored with discovery timestamps and results.

### 4. Dashboard & Administration
- **Filament Admin:** Clustered Filament v4 resources for a tidy admin UX.
- **Dashboard widgets:** System overview and latest scan results widgets.
- **Authentication & roles:** Secure login and basic role management for admins.

---

## 🔄 Roadmap / Future Work

### Short-term / Roadmap
- **IPv6 UI Support:** Database schema already prepared; UI support for IPv6 is scheduled (frontend & UX).
- **Remote API Agents:** Remote agent support for distributed scanning and result ingestion.
- **SNMP Integration:** Passive discovery via SNMP (walks) and richer device metadata.

## 📋 Roadmap

### Phase 1: Core IPAM (Completed)
- [x] Database Schema
- [x] Basic CRUD
- [x] IP Logic

### Phase 2: Infrastructure (Completed)
- [x] Racks & Devices Refactor
- [x] Locations & Customers

### Completed Phases
- Core IPAM (IPv4, VRF, Subnets) — completed
- Rack Management (Visual diagrams, URL-based placement) — completed
- Discovery (Local Fping, Scan Agents, Background Jobs) — completed
- Dashboard (Widgets & Overview) — completed

### Phase 4: Advanced Features
- [ ] Circuit Management
- [ ] Power Management (PDU)
- [ ] API Access
