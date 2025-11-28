# System Architecture

> **Navigation:** [← README](README.md) | [Features](FEATURES.md) | [Issues](ISSUES.md) | [📚 Docs Map](NAVIGATION.md)

---

## 🏗️ High-Level Overview

Modern IPAM is built on **Laravel 12** and **FilamentPHP 4**, utilizing a **Dockerized** environment. It follows a strict **Domain-Driven Design** approach where possible, leveraging **Actions** for business logic and **Resources** for UI.

---

## 🧩 Core Components

### 1. Data Model (Eloquent)

#### Racks & Devices Domain
- **Rack:** Represents a physical cabinet.
  - Belongs to `Customer` (mandatory) and `Location` (optional).
  - Has many `Devices`.
  - Attributes: `units` (default 42), `model`.
- **Device:** A physical asset.
  - Belongs to `Rack` (optional), `DeviceModel`, `Location`.
  - Attributes: `rack_position` (U), `rack_size` (U).
- **DeviceModel:** Hardware classification.
  - Attributes: `type` (Router, Switch, etc.), `default_rack_size`.

#### IPAM Domain
- **Subnet:** Hierarchical network segments.
- **IpAddress:** Individual IPs stored as `decimal` for efficient range queries.

### 2. Filament Architecture

#### Clusters
We use **Filament Clusters** to group related resources and keep the sidebar clean.

**Racks & Devices Cluster (`app/Filament/Clusters/Racks`)**
- Contains:
  - `RackResource`
  - `DeviceResource`
  - `DeviceModelResource`
- **Structure:** Follows Filament v4 directory recommendation:
  ```
  app/Filament/Clusters/Racks/
  ├── RacksCluster.php
  └── Resources/
      ├── Racks/
      ├── Devices/
      └── DeviceModels/
  ```

#### Actions Namespace
- **Table Actions:** Located in `Filament\Actions` (e.g., `EditAction`, `DeleteBulkAction`).
- **Page Actions:** Located in `Filament\Actions`.
- *Note:* This differs from standard Filament v3 docs (`Filament\Tables\Actions`), likely due to specific version/config.

### 3. Services & Actions

- **IP Conversion:** `IpConversionService` handles IPv4/IPv6 <-> Decimal conversion.
- **Scanning:** `FpingWrapper` encapsulates the `fping` CLI tool interaction.

---

## 💾 Database Schema

### Key Relationships
- `Customer` (1) -> (N) `Location`
- `Customer` (1) -> (N) `Rack`
- `Rack` (1) -> (N) `Device`
- `DeviceModel` (1) -> (N) `Device`

### Design Decisions
- **Nullable Foreign Keys:** Used for flexible relationships (e.g., Device can exist without Rack).
- **Decimal IP Storage:** Chosen over string for >100x faster range queries.
- **Strict Types:** PHP 8.4 features used extensively.

---

## 🐳 Infrastructure (Docker)

- **App:** PHP 8.4 FPM + Nginx
- **DB:** MariaDB 11.8
- **Cache:** Redis 8.4
- **Network:** Internal bridge network

---

## 🔒 Security

- **SNMP:** Credentials removed from DB (security risk). Future implementation via secure vault.
- **Auth:** Standard Laravel Authentication.
