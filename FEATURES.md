# Features & Roadmap

> **Navigation:** [← README](README.md) | [Architecture](ARCHITECTURE.md) | [Issues](ISSUES.md) | [📚 Docs Map](NAVIGATION.md)

---

## ✅ Implemented Features

### 1. IP Address Management (IPAM)
- **Subnets:** Hierarchical management (Master/Nested), CIDR validation.
- **IP Addresses:** Decimal storage for performance, status tracking (Used, Reserved, DHCP).
- **Pre-filling:** Async job to pre-fill subnets with IP records.

### 2. Network Infrastructure
- **Racks & Devices:**
  - **Racks:** Management of physical racks (Units, Models).
  - **Devices:** Asset management with Rack positioning (U position + size).
  - **Device Models:** Categorization (Router, Switch, Server) with default sizes.
  - **Visual Hierarchy:** "Racks & Devices" cluster in admin panel.
- **Locations:** Physical sites linked to Customers.
- **Customers:** Multi-tenant support for assets.
- **VLANs:** L2 Domain support, unique constraints.

### 3. Scanning & Discovery
- **Scan Agents:** Local agent support.
- **Discovery:** Fping wrapper for network scanning.
- **Status:** Last seen timestamp tracking.

### 4. Administration
- **FilamentPHP Admin Panel:** Modern, responsive UI.
- **Authentication:** Secure login system.
- **RBAC:** Basic user roles.

---

## 🔄 In Progress

- **Rack Visualisation:** Visual diagram of Rack contents (Front/Back view).
- **Scan Results UI:** Interface to review and import scan results.

## 📋 Roadmap

### Phase 1: Core IPAM (Completed)
- [x] Database Schema
- [x] Basic CRUD
- [x] IP Logic

### Phase 2: Infrastructure (Completed)
- [x] Racks & Devices Refactor
- [x] Locations & Customers

### Phase 3: Discovery (Current)
- [ ] Remote Scan Agents
- [ ] SNMP Integration
- [ ] Import Workflow

### Phase 4: Advanced Features
- [ ] Circuit Management
- [ ] Power Management (PDU)
- [ ] API Access
