# 🐛 Known Issues & Technical Debt

> **Navigation:** [← README](README.md) | [Architecture](ARCHITECTURE.md) | [Features](FEATURES.md) | [📚 Docs Map](NAVIGATION.md)

---

## 📊 Status Overview

**Last Audit:** 2025-11-28  
**Major Issues:** 0 active, 11 resolved  
**Technical Debt:** 2 resolved, 4 remaining (low priority)  
**Project Health:** ✅ **Stable & Production-Ready**

---

## ✅ RESOLVED ISSUES (2025-11-26 Migration Batch)

### 1. ✅ Customer Coordinates & Contact Fields

**Problem:** Coordinates (`lat`, `long`) and contact fields (`contact_person`, `contact_phone`, `contact_mail`) were cluttering Customer UI  
**Fixed By:** `database/migrations/2025_11_26_120000_drop_customer_coordinates_and_contacts.php`  
**Changes:**
- Dropped 5 columns from `customers` table
- Updated `app/Filament/Resources/Customers/Schemas/CustomerForm.php` (line 26 comment)
- Updated `app/Filament/Resources/Customers/Tables/CustomersTable.php` (line 27 comment)
- Updated `app/Models/Customer.php` fillable array (line 10)

**Status:** ✅ Fully resolved

---

### 2. ✅ Location Coordinates & Customer Relationship

**Problem:** Locations had coordinates but no parent-child relationship with Customers  
**Fixed By:** `database/migrations/2025_11_26_121500_locations_drop_coords_add_customer.php`  
**Changes:**
- Dropped `lat` and `long` columns from `locations`
- Added `customer_id` foreign key (nullable, cascade on delete)
- Updated `app/Filament/Resources/Locations/Schemas/LocationForm.php` (lines 23-27)
- Added `customer()` relationship to `app/Models/Location.php` (line 15)

**Status:** ✅ Fully resolved

---

### 3. ✅ Device SNMP Fields (Security Risk)

**Problem:** 12 SNMP columns exposed in UI and DB (security sensitive: `snmp_community` leaked)  
**Fixed By:** `database/migrations/2025_11_26_123000_drop_devices_snmp_columns.php`  
**Changes:**
- Dropped all 12 SNMP columns: `snmp_community`, `snmp_version`, `snmp_port`, `snmp_timeout`, `snmp_queries`, v3 auth fields
- Updated `app/Filament/Resources/Devices/Schemas/DeviceForm.php` (line 29 comment)
- Updated `app/Models/Device.php` fillable (line 10)
- **Security improvement:** No SNMP credentials stored in plaintext

**Status:** ✅ Fully resolved

---

### 4. ✅ VRF Removal

**Problem:** VRF table and references were unused, adding complexity  
**Fixed By:** `database/migrations/2025_11_26_131000_drop_vrfs_and_vrf_id_from_subnets.php`  
**Changes:**
- Dropped `vrf_id` foreign key from `subnets` table
- Dropped entire `vrfs` table
- No VRF Filament resources found in `app/` (verified with find)
- Added comment in `app/Models/Customer.php`: "VRF removed from project" (line 15)

**Status:** ✅ Fully resolved

---

### 5. ✅ Scan Agent Customer Scoping

**Problem:** Scan agents were global, no multi-tenant support  
**Fixed By:** `database/migrations/2025_11_26_150000_add_customer_id_to_scan_agents.php`  
**Changes:**
- Added `customer_id` foreign key to `scan_agents` (nullable, after 'id')
- Updated `app/Models/ScanAgent.php` - customer_id in fillable, `customer()` relationship (lines 10, 23-26)
- Database supports customer scoping

**Remaining UX Work:**
- ⚠️ UI still shows global navigation (under "Administration")
- **Recommendation:** Move to Customer detail page or add customer filter

**Status:** ✅ Database resolved, ⚠️ UI improvement optional

---

### 6. ✅ VLAN Unique Constraints

**Problem:** No constraint preventing duplicate VLAN numbers in same domain  
**Fixed By:** `database/migrations/2025_11_26_124500_add_unique_index_vlans_domain_number.php`  
**Changes:**
- Added unique constraint on (`l2_domain_id`, `number`)
- VlanForm has numeric validation on `number` field

**Status:** ✅ Fully resolved

---

### 7. ✅ Legacy Code Removal

**Problem:** 21K files from `phpipam-master/` directory cluttering repository  
**Fixed By:** Manual deletion  
**Verification:** `grep -r "phpipam-master"` finds no references in PHP/MD files

**Status:** ✅ Fully resolved

---

### 8. ✅ IP Address `last_seen` Placeholder

**Problem:** Used legacy placeholder `'1970-01-01 00:00:01'` instead of NULL  
**Fixed By:** `database/migrations/2025_11_26_101010_alter_ip_addresses_last_seen_nullable.php`  
**Changes:**
- Migrated legacy placeholder values to NULL (lines 16-18)
- Altered column default to NULL on MySQL (line 23)
- Proper rollback in down() method

**Status:** ✅ Fully resolved

---

### 9. ✅ Icon Rendering Test Coverage

**Problem:** Missing unit tests for heroicon rendering  
**Fixed By:** Created `tests/Unit/IconRenderingTest.php`  
**Improvement:** Basic test coverage for icon rendering

**Remaining Work:**
- ⚠️ Expand coverage to all Filament resources (optional)

**Status:** ✅ Resolved, ⚠️ enhancement optional

### 10. ✅ Racks Visibility in Filament
**Problem:** "Racks & Devices" cluster was not visible in the admin panel.
**Fixed By:**
- Restructured `app/Filament/Clusters/Racks` to follow Filament v4 directory recommendations.
- Updated `AdminPanelProvider` to use `discoverClusters()`.
- Cleared Filament component cache.
**Status:** ✅ Fully resolved

### 11. ✅ Filament Table Actions Import Error
**Problem:** `Class "Filament\Tables\Actions\EditAction" not found` error when accessing Racks table.
**Fixed By:**
- Identified that in the installed Filament version, Table actions reside in `Filament\Actions` namespace.
- Updated imports in `RacksTable`, `DevicesTable`, and `DeviceModelsTable`.
**Status:** ✅ Fully resolved

---

## ⚠️ REMAINING TECHNICAL DEBT (Low Priority)

### 1. ⚠️ Magic Number Hardcoding (Prefix Mask Limit)

**Location:**
- `app/Services/IpPrefillService.php` line 19: `if ((int) $subnet->mask < 22)`
- `app/Observers/SubnetObserver.php` line 14: `if ((int) $subnet->mask >= 22)`

**Problem:** Mask threshold (22) is hardcoded in 2 files  
**Impact:** Low - works correctly, but not configurable  
**Recommendation:** Create `config/ipam.php` with `'prefill_mask_limit' => 22`  
**Effort:** 15 minutes (create config, update 2 files, add test)

**Example Fix:**
```php
// config/ipam.php
return [
    'prefill_mask_limit' => env('IPAM_PREFILL_MASK_LIMIT', 22),
];

// app/Services/IpPrefillService.php
if ((int) $subnet->mask < config('ipam.prefill_mask_limit', 22)) {
```

---

### 2. ⚠️ No Systematic Filament Namespace Checks

**Problem:** No PHPStan or static analysis to detect deprecated Filament namespaces  
**Impact:** Medium - manual fixes applied, risk of regression  
**Recommendation:** Add PHPStan with Filament ruleset  
**Effort:** 1 hour (configure PHPStan + add to CI)

**Status:** Manual review working, automation missing

---

### 3. ⚠️ Queue Worker Documentation Missing

**Problem:** IpPrefillJob exists but no docs for running queue workers  
**Impact:** Low - code works, but setup unclear for new developers  
**Recommendation:** Document queue worker setup in README (Windows/Docker)  
**Effort:** 30 minutes (documentation only)

**Current workaround:** See old README.md for basic instructions

---

### 4. ⚠️ No CI/CD Pipeline

**Problem:** `.github/workflows/` directory does not exist  
**Impact:** Medium - no automated testing on commits/PRs  
**Recommendation:** Add GitHub Actions workflow for phpunit + pint  
**Effort:** 1 hour (create workflow YAML + test)

**Tests exist:** `tests/Feature/` and `tests/Unit/` ready to run

---

## 📋 OPTIONAL UX IMPROVEMENTS

### 1. IP Address Global Navigation

**File:** `app/Filament/Resources/IpAddresses/IpAddressResource.php`  
**Current:** Still has global navigation (line 24-28)  
**Recommendation:** Hide from top nav, implement scoped search in header  
**Impact:** Low - does not break functionality, just UX preference

---

### 2. Scan Agent UI Organization

**File:** `app/Filament/Resources/ScanAgents/ScanAgentResource.php`  
**Current:** Global navigation under "Administration" (line 23)  
**Database:** Already supports customer scoping via `customer_id`  
**Recommendation:** Move to Customer relation manager or add customer filter  
**Impact:** Low - works as-is, optimization for multi-tenant use

---

## 📝 Migration History

**2025-11-26 Migrations (6 total):**
1. `120000_drop_customer_coordinates_and_contacts.php`
2. `121500_locations_drop_coords_add_customer.php`
3. `123000_drop_devices_snmp_columns.php`
4. `124500_add_unique_index_vlans_domain_number.php`
5. `131000_drop_vrfs_and_vrf_id_from_subnets.php`
6. `150000_add_customer_id_to_scan_agents.php`

**Result:** All migrations have proper up/down methods with rollback support

---

## 🎯 Priority Actions (If Time Permits)

**High Value, Low Effort:**
1. Create `config/ipam.php` and centralize mask threshold (15 min)
2. Document queue worker setup in README (30 min)

**Medium Value, Medium Effort:**
3. Add GitHub Actions CI workflow (1 hour)
4. Configure PHPStan for Filament namespace checks (1 hour)

**Low Priority:**
5. Expand IconRenderingTest coverage to all resources (optional)

---

## 🏆 Project Health Summary

✅ **All critical bugs resolved** (DB schema, security, architecture)  
✅ **Code is maintainable** (clean patterns, documented)  
✅ **Production-ready** for small-medium deployments  
⚠️ **CI/CD recommended** before scaling to multi-contributor teams

---

**Related Docs:**
- [Features Implemented](FEATURES.md)
- [Architecture Decisions](ARCHITECTURE.md)
- [Release History](CHANGELOG.md)
- [📚 Documentation Map](NAVIGATION.md)
