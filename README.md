# NetVibe

**Status:** Stable / V1.0 MVP

> A modern Laravel 12 + FilamentPHP 4 implementation of IP Address Management

[![Laravel 12](https://img.shields.io/badge/Laravel-12.x-red)](https://laravel.com)
[![FilamentPHP 4](https://img.shields.io/badge/Filament-4.x-orange)](https://filamentphp.com)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## 📋 Quick Links

- **🚀 [Setup Guide](SETUP.md)** - Installation & configuration
- **🏗️ [Project Status & Architecture](_DOCS/STATUS.md)** - System design, schema notes & current green test status
- **✨ [Features](FEATURES.md)** - Implemented features & roadmap
- **🐛 [Known Issues & Technical Debt (archived)](_DOCS/archive/ISSUES.md)** - Problems, fixes & technical debt (archived)
- **📝 [Changelog (archived)](_DOCS/archive/CHANGELOG.md)** - Release history (archived)
- **📚 [Agent Behaviour & Docs Index](_DOCS/AGENT_BEHAVIOR.md)** - Guidance for integrators and AI agents


## 🎯 Project Overview

NetVibe is a clean-slate reimplementation of [phpIPAM](https://phpipam.net/) using modern PHP frameworks while maintaining strict UI/UX compatibility with the original.

### Philosophy: "Strict Replica"

- ✅ Modern codebase (Laravel 12, FilamentPHP 4, PHP 8.4)
- ✅ Docker-based development environment
- ✅ Clean architecture (Action pattern, DTOs, Services)


## ⚡ Technology Stack

| Component | Technology | Version | Rationale |
|-----------|-----------|---------|-----------|
| **Framework** | Laravel | 12.40.1 | Modern PHP framework with LTS |
| **Admin Panel** | FilamentPHP | 4.2.3 | TALL stack admin panel |
| **Language** | PHP | 8.4.15 | Latest stable with performance gains |
| **Database** | MariaDB | 11.8 LTS | 3-year support, MySQL compatible |
| **Cache** | Redis | 8.4.0 | Fast session + cache layer |
| **Web Server** | Nginx | 1.28.0 | High performance, FastCGI |
| **IP Library** | mlocati/ip-lib | 1.22.0 | Best IPv4/IPv6 support |
| **Container** | Docker | Compose | Reproducible environments |

Other notable packages: Spatie Settings (spatie/laravel-settings)

📖 **Details:** [_DOCS/STATUS.md](_DOCS/STATUS.md)


## 🚀 Quick Start

### Start (development)

Use the development override to mount your local files and expose the webserver port:

```bash
docker compose -f docker/docker-compose.yml -f docker/dev/docker-compose.override.yml up -d
```

### Start (production example)

```bash
docker compose -f docker/docker-compose.yml -f docker/production/docker-compose.prod.yml up -d
```

### Prerequisites

# NetVibe

**Status:** Stable / V1.0 MVP

> A modern Laravel 12 + FilamentPHP 4 implementation of IP Address Management

[![Laravel 12](https://img.shields.io/badge/Laravel-12.x-red)](https://laravel.com)
[![FilamentPHP 4](https://img.shields.io/badge/Filament-4.x-orange)](https://filamentphp.com)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## 📋 Quick Links & Context Vault

Per la documentazione completa e il contesto architettonico aggiornato, consulta la cartella `_DOCS/`.

- **🟢 [Project Status](_DOCS/STATUS.md)** - Stato attuale e obiettivi raggiunti (Source of Truth per l'AI)
- **🗺️ [Roadmap](_DOCS/ROADMAP.md)** - Prossime funzionalità e task pianificati
- **🚀 [Setup Guide](SETUP.md)** - Istruzioni di installazione e configurazione

---

## 🎯 Project Overview

NetVibe è una reimplementazione di [phpIPAM](https://phpipam.net/) che utilizza framework PHP moderni, mantenendo la compatibilità UI/UX con l'originale.

### Filosofia: "Strict Replica"

- ✅ Codebase moderno (Laravel 12, FilamentPHP 4, PHP 8.4)
- ✅ Ambiente di sviluppo basato su Docker
- ✅ Architettura pulita (Action pattern, DTOs, Services)

---

## ⚡ Technology Stack

| Component | Choice | Version | Rationale |
|---|---|---|---|
| **PHP** | 8.4.x | Latest | Performance, modern syntax |
| **Framework** | Laravel | 12.x | Long-term support |
| **Admin UI** | FilamentPHP | 4.x | Best admin panel for Laravel |
| **Database** | MariaDB | 11.x LTS | MySQL compatible |
| **Queue/Cache** | Redis | 8.x | Fast session + cache driver |

📖 **Full Architettura & Regole per l'AI:** [_DOCS/AGENT_BEHAVIOR.md](_DOCS/AGENT_BEHAVIOR.md)

---

## 🤝 Contribuzione

1. Leggi [SETUP.md] per configurare l'ambiente
2. Consulta [_DOCS/STATUS.md] for the current status

---

## 📝 Licenza

MIT License - consulta il file LICENSE per i dettagli
  - VLANs with L2 domain support
  - Devices inventory
  - Locations management
  - Customers/tenants

- **Scanning**
  - Scan Agents (localhost + future remote support)
  - FpingWrapper for network discovery
  - Ping status tracking with last_seen timestamps

- **Administration**
  - Filament-based admin panel
  - User authentication
  - Settings management

### 🔄 In Progress

- Subnet CIDR display in tables
- Scan workflow UI (agent selection + results review)
- Import discovered IPs workflow

### 📋 Planned

- Hierarchical subnet tree view
- Remote scan agent support (API)
- SNMP integration
- Bulk import/export
- IP request workflow
- DNS PTR management

📖 **Full Feature List:** [FEATURES.md](FEATURES.md)


## 📊 Project Status

**Current Version:** 1.0.0 — Stable / V1.0 MVP

**Stability:** ✅ **Production-Ready** for small-medium deployments

**Code Quality:**
- ✅ All critical bugs resolved
- ✅ Database schema clean (6 major migrations on 2025-11-26)
- ✅ Security issues fixed (SNMP removed, VRF dropped)
- ✅ Legacy code eliminated
- ⚠️ CI/CD pipeline recommended before scaling

📖 **Known Issues & Fixes (archived):** [_DOCS/archive/ISSUES.md](_DOCS/archive/ISSUES.md)


## 🏗️ Architecture Highlights

### Directory Structure

```
app/
├── Actions/          # Business logic (Action pattern)
├── Data/             # DTOs (spatie/laravel-data)
├── Filament/         # Admin UI resources
│   ├── Resources/    # CRUD (Subnets, IPs, Devices, etc.)
│   └── Widgets/      # Dashboard widgets
├── Models/           # 8 Eloquent models with relationships
├── Services/         # External service wrappers
│   ├── IpConversionService.php
│   └── Scanners/FpingWrapper.php
└── Observers/        # Model event listeners
```

### Key Design Decisions

- **IP Storage:** Decimal format (e.g., `192.168.1.1` → `3232235777`) for efficient indexing
- **Scan Agents:** Support local + remote agents (localhost default, API/MySQL for remote)
- **UI Pattern:** Filament Resources + Actions (no raw controllers)
- **Data Flow:** Request → Action → Service → Model

📖 **Architecture & Status:** [_DOCS/STATUS.md](_DOCS/STATUS.md)


## 📚 Documentation for AI Agents

This project is designed to be maintainable by AI coding agents. All documentation includes:

- ✅ Clear navigation links between related docs
- ✅ Context for each document's purpose
- ✅ File location references (absolute paths)
- ✅ Code examples with explanations
- ✅ Decision rationale

**Start here:** [_DOCS/AGENT_BEHAVIOR.md](_DOCS/AGENT_BEHAVIOR.md) - Agent behavioural protocol & docs landing


## 🤝 Contributing

### For Developers

1. Read [SETUP.md](SETUP.md) to set up your environment
2. Check [ARCHITECTURE.md](ARCHITECTURE.md) for design patterns
3. Review [FEATURES.md](FEATURES.md) for roadmap
4. See [ISSUES.md](ISSUES.md) for known constraints

### For AI Agents

1. Read [NAVIGATION.md](NAVIGATION.md) for documentation map
2. Check `docs/PHPIPAM_UI_MAP.md` for UI requirements
3. Check `docs/IMPLEMENTATION_GAP.md` for what exists
4. Follow patterns in [ARCHITECTURE.md](ARCHITECTURE.md)


## 📝 License

MIT License - see LICENSE file for details


## 🔗 Related Documentation

- **phpIPAM Original:** https://phpipam.net/
- **Laravel 12:** https://laravel.com/docs/12.x
- **FilamentPHP 4:** https://filamentphp.com/docs


**Last Updated:** 2025-11-30  
**Project Maintainer:** See git history  
**For Support:** Check [_DOCS/archive/ISSUES.md](_DOCS/archive/ISSUES.md) or open an issue
