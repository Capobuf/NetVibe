# 📚 Documentation Navigation Map

> **For AI Agents:** This file provides a navigation map of all project documentation with clear context for each document.

## 🎯 Quick Navigation

### For New Users/Agents
1. Start here: [README.md](README.md) - Project overview & quick start
2. Then: [SETUP.md](SETUP.md) - Technical setup instructions
3. Understand: [ARCHITECTURE.md](ARCHITECTURE.md) - System design & decisions

### For Development
- **Feature Planning**: [FEATURES.md](FEATURES.md)
- **Known Issues**: [ISSUES.md](ISSUES.md)
- **Release History**: [CHANGELOG.md](CHANGELOG.md)

### For phpIPAM Migration
- **UI Reference**: [docs/PHPIPAM_UI_MAP.md](docs/PHPIPAM_UI_MAP.md)
- **Gap Analysis**: [docs/IMPLEMENTATION_GAP.md](docs/IMPLEMENTATION_GAP.md)

---

## 📖 Document Descriptions

### Core Documentation

#### [README.md](README.md)
**Purpose:** Landing page for the project  
**Audience:** Everyone  
**Content:**
- Project description & goals
- Technology stack overview
- Quick start guide
- Links to detailed docs

**Next Steps:**
- New users → [SETUP.md](SETUP.md)
- Developers → [ARCHITECTURE.md](ARCHITECTURE.md)
- Contributors → [FEATURES.md](FEATURES.md)

---

#### [SETUP.md](SETUP.md)
**Purpose:** Complete technical setup guide  
**Audience:** Developers setting up the environment  
**Content:**
- Docker stack configuration
- Environment variables
- Database migrations
- Admin access credentials
- Development workflow

**Depends On:** [README.md](README.md)  
**Next Steps:** [FEATURES.md](FEATURES.md) to understand what to build

---

#### [ARCHITECTURE.md](ARCHITECTURE.md)
**Purpose:** System architecture & technical decisions  
**Audience:** Senior developers, architects, AI agents implementing features  
**Content:**
- Technology stack (versions & rationale)
- Directory structure
- Design patterns (Action pattern, DTO usage)
- Database schema overview
- IP storage format (decimal)
- Scan agent architecture

**Related:**
- Database details → migrations in `database/migrations/`
- Code structure → see actual `app/` directory
- UI decisions → [docs/PHPIPAM_UI_MAP.md](docs/PHPIPAM_UI_MAP.md)

---

#### [FEATURES.md](FEATURES.md)
**Purpose:** Feature inventory & development roadmap  
**Audience:** Product managers, developers planning work  
**Content:**
- ✅ Implemented features (what works now)
- 🔄 In-progress features (current work)
- 📋 Planned features (backlog)
- Feature priority matrix

**Related:**
- Implementation status → [docs/IMPLEMENTATION_GAP.md](docs/IMPLEMENTATION_GAP.md)
- Known bugs → [ISSUES.md](ISSUES.md)
- Release history → [CHANGELOG.md](CHANGELOG.md)

---

#### [ISSUES.md](ISSUES.md)
**Purpose:** Known issues, technical debt, and applied fixes  
**Audience:** Developers, QA, AI agents debugging  
**Content:**
- ✅ Resolved issues (with fix references)
- ⚠️ Known issues (with workarounds)
- 🔧 Technical debt items (with effort estimates)
- Migration history (what changed & why)

**Related:**
- Migrations → `database/migrations/2025_11_26_*`
- Code fixes → check git history
- Planned fixes → [FEATURES.md](FEATURES.md)

---

#### [CHANGELOG.md](CHANGELOG.md)
**Purpose:** Release history following semantic versioning  
**Audience:** Users tracking releases  
**Content:**
- Version history (0.1.0 → current)
- Features added per release
- Breaking changes
- Upcoming releases

**Format:** [Keep a Changelog](https://keepachangelog.com/)  
**Related:** [FEATURES.md](FEATURES.md) for roadmap

---

### Reference Documentation

#### [docs/PHPIPAM_UI_MAP.md](docs/PHPIPAM_UI_MAP.md)
**Purpose:** Complete reverse engineering of original phpIPAM UI/UX  
**Audience:** Developers implementing UI, AI agents replicating features  
**Content:**
- Menu structure (Tools, Administration, Subnets)
- IP Address table columns (default + conditional)
- Scan workflow (step-by-step)
- Design patterns & UI conventions
- FilamentPHP mapping strategy

**Status:** Reference only - DO NOT MODIFY  
**Use Case:** When implementing a new UI feature, check this doc first  
**Related:** [docs/IMPLEMENTATION_GAP.md](docs/IMPLEMENTATION_GAP.md)

---

#### [docs/IMPLEMENTATION_GAP.md](docs/IMPLEMENTATION_GAP.md)
**Purpose:** Gap analysis between phpIPAM and Modern IPAM  
**Audience:** Product managers, developers prioritizing work  
**Content:**
- ✅ Implemented features (DB + UI)
- ⚠️ Partially implemented (backend exists, UI missing)
- ❌ Missing features (not started)
- Priority recommendations

**Updated:** 2025-11-25  
**Next Review:** After major feature additions  
**Related:**
- UI Reference → [docs/PHPIPAM_UI_MAP.md](docs/PHPIPAM_UI_MAP.md)
- Roadmap → [FEATURES.md](FEATURES.md)

---

## 🔄 Documentation Update Workflow

### When to Update Which Doc

**Scenario** → **Update**
- New feature completed → [CHANGELOG.md](CHANGELOG.md) + [FEATURES.md](FEATURES.md)
- Bug discovered → [ISSUES.md](ISSUES.md)
- Bug fixed → [ISSUES.md](ISSUES.md) + [CHANGELOG.md](CHANGELOG.md)
- Architecture change → [ARCHITECTURE.md](ARCHITECTURE.md)
- New migration added → [ISSUES.md](ISSUES.md) (if fixes debt)
- Environment change → [SETUP.md](SETUP.md)
- Gap closed → [docs/IMPLEMENTATION_GAP.md](docs/IMPLEMENTATION_GAP.md)

---

## 🤖 AI Agent Quick Reference

### "I need to understand the project"
1. Read [README.md](README.md)
2. Read [ARCHITECTURE.md](ARCHITECTURE.md)
3. Check [FEATURES.md](FEATURES.md) for current state

### "I need to implement a feature"
1. Check [docs/PHPIPAM_UI_MAP.md](docs/PHPIPAM_UI_MAP.md) for UI requirements
2. Check [docs/IMPLEMENTATION_GAP.md](docs/IMPLEMENTATION_GAP.md) for what exists
3. Check [ARCHITECTURE.md](ARCHITECTURE.md) for patterns to follow
4. Check [ISSUES.md](ISSUES.md) for known constraints

### "I need to fix a bug"
1. Check [ISSUES.md](ISSUES.md) for known issues
2. Check [ARCHITECTURE.md](ARCHITECTURE.md) for system design
3. Read related migration files in `database/migrations/`

### "I need to set up the project"
1. Read [SETUP.md](SETUP.md)
2. If errors occur → [ISSUES.md](ISSUES.md)

---

## 📁 File Locations

```
phpipam-laravel/
├── README.md                      ← Start here
├── SETUP.md                       ← Technical setup
├── ARCHITECTURE.md                ← System design
├── FEATURES.md                    ← What's implemented
├── ISSUES.md                      ← Known problems
├── CHANGELOG.md                   ← Release history
├── NAVIGATION.md                  ← This file
└── docs/
    ├── PHPIPAM_UI_MAP.md         ← UI reference (read-only)
    └── IMPLEMENTATION_GAP.md     ← Gap analysis
```

---

**Last Updated:** 2025-11-28  
**Maintained By:** Documentation should be updated by developers after major changes
