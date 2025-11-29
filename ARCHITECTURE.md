# System Architecture

> **Navigation:** [← README](README.md) | [Features](FEATURES.md) | [Issues](ISSUES.md) | [📚 Docs Map](NAVIGATION.md)

---

## 🏗️ High-Level Overview

Modern IPAM is built on **Laravel 12** and **FilamentPHP 4**, and runs in a Dockerized environment. The project favors a clean resource-driven admin UI using Filament Clusters, and uses an Action/Service pattern for business logic.

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

- **IP Conversion:** `IpConversionService` handles IPv4 and IPv6 conversions to and from decimal for efficient storage and range queries.
- **Scanning/Discovery:** The discovery pipeline is implemented using a `FpingWrapper` (calls the `fping` binary) executed via Laravel's Process APIs, with results dispatched and persisted using the application's queue workers. The discovery architecture expects queue workers to be running to process scan jobs and persist results.

---

## 💾 Database Schema & Persistence

### Key Relationships
- `Customer` (1) -> (N) `Location`
- `Customer` (1) -> (N) `Rack`
- `Rack` (1) -> (N) `Device`
- `DeviceModel` (1) -> (N) `Device`

### Design Decisions
- **Strict Schema:** Migrations enforce types and relationships. Soft deletes are used extensively on primary entities for safer data retention and audit.
- **Decimal IP storage:** IPv4 and IPv6 values are stored in integer/decimal-friendly formats for efficient indexing and range queries.
- **Queue-driven processes:** Discovery and long-running tasks are delegated to the queue system to keep the web process responsive.

---

## 🔑 Key Patterns & Conventions

- **Filament Clusters:** All admin CRUD resources are organized under `app/Filament/Clusters/*` to keep the UI and codebase tidy and coherent.
- **Native Enums + Eloquent Casts:** The codebase uses PHP 8.1+ native Enums with explicit Eloquent casts so UI/forms and DB remain in sync.
- **Decimal IP Storage:** Storing IP addresses as decimals/integers improves index performance and simplifies range queries.
- **Process Wrapper for Scanning:** External scanning tools (e.g., `fping`) are executed through a process wrapper (`FpingWrapper`) to capture outputs and manage timeouts and error codes.

---

## 🐳 Infrastructure (Docker)

- **App:** PHP 8.4 FPM + Nginx (plus required tooling like `fping` for discovery)
- **DB:** MariaDB (or SQLite for local testing)
- **Cache & Queue:** Redis — used by queues and caching services
- **Queues/Workers:** The discovery pipeline and background jobs require the queue worker to be running (`php artisan queue:work` in container)

---

## 🔒 Security

- **Secrets:** Sensitive credentials removed from DB and planned to be stored with secure vault integration for remote agents in the future.
- **Auth:** Standard Laravel authentication and role controls are present in the Filament admin UI.
