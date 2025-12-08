# Security Audit: Phase 4 Ingest Actions
**Date:** 2025-12-16  
**Focus:** Production Vulnerability Assessment  
**Status:** COMPREHENSIVE FINDINGS DOCUMENTED

---

## Executive Summary

Three critical vulnerabilities identified in ingest actions that could cause data corruption, duplicate records, and incomplete topology in production:

| Vulnerability | Severity | Location | Impact |
|---|---|---|---|
| **Race Condition (Concurrent IP Updates)** | CRITICAL | `IngestMikrotikArpAction.php` | Data corruption, duplicate IPs, conflicting MAC mappings |
| **Missing MAC Normalization** | HIGH | `IngestMikrotikArpAction.php` | Duplicate MAC entries (formatting inconsistency) |
| **Unidirectional Topology Links** | HIGH | `IngestMikrotikTopologyAction.php` | Incomplete topology visualization, directional bias in reporting |
| **Missing Transaction Wrapper (Inventory)** | MEDIUM | `IngestMikrotikInventoryAction.php` | Partial device updates if save fails mid-operation |

---

## VULNERABILITY #1: RACE CONDITION IN IngestMikrotikArpAction.php

### Location
Lines 48-59 (DHCP handling), 127-135 (ARP handling)

### Current Code (VULNERABLE)
```php
$ip = IpAddress::where('ip_addr', (string) $dec)->first();
if ($ip) {
    $ip->mac_address = $mac;           // ← NO LOCK, race condition!
    $ip->hostname = $hostname ?? $ip->hostname;
    $ip->last_seen = Carbon::now();
    $ip->save();                       // ← Could fail silently or overwrite concurrent updates
    continue;
}
```

### Vulnerability Analysis

**Scenario 1: Concurrent Agents Update Same IP**
```
Agent A at t=1: SELECT * FROM ip_addresses WHERE ip_addr='192.168.1.10' 
              Gets: mac='aa:bb:cc:dd:ee:ff', hostname='old-name'
Agent B at t=2: SELECT * FROM ip_addresses WHERE ip_addr='192.168.1.10'
              Gets: SAME record (no lock)
Agent A at t=3: UPDATE ... SET mac='11:22:33:44:55:66', hostname='name-from-scan-a'
Agent B at t=4: UPDATE ... SET mac='77:88:99:aa:bb:cc', hostname='name-from-scan-b'
              ← WINS! Agent A's MAC is lost forever
```

**Result:** MAC address from Agent A completely overwritten. If Agent A scanned this IP from different port, topology is corrupted.

**Scenario 2: Check-Then-Create Race (Lines 65-90)**
```
Agent A at t=1: SELECT * FROM subnets WHERE ... (mapping subnet)
Agent A at t=2: SELECT COUNT(*) FROM ip_addresses (checking before create)
Agent B at t=2: Same queries running
Agent A at t=3: INSERT INTO ip_addresses (...) 
Agent B at t=3: INSERT INTO ip_addresses (...)  ← Same IP! Violation of implicit uniqueness
```

**Database integrity:** Potential duplicate records or constraint violations.

---

### Root Cause

**No Database-Level Locking:**
- Uses `.first()` without `.lockForUpdate()` 
- No transaction wrapper
- No uniqueness constraint on `(subnet_id, ip_addr)` preventing duplicates

**No Atomic Check-Create Pattern:**
- DHCP/ARP handling uses separate checks and creates
- Race window between SELECT and INSERT

---

### Fix Required

**Option A: Pessimistic Locking (Recommended for IPAM)**
```php
use Illuminate\Support\Facades\DB;

// Inside transaction for atomicity
DB::transaction(function() use ($dec, $mappedSubnet, $mac, $hostname, $dynamic) {
    // Lock existing record if found
    $ip = IpAddress::where('ip_addr', (string) $dec)
        ->lockForUpdate()  // ← PostgreSQL: FOR UPDATE, MySQL: FOR UPDATE
        ->first();
    
    if ($ip) {
        $ip->mac_address = $mac;
        $ip->hostname = $hostname ?? $ip->hostname;
        $ip->last_seen = Carbon::now();
        $ip->save();
        return;
    }
    
    // Create only if still missing (after lock released)
    if ($mappedSubnet) {
        IpAddress::create([...]);
    } else {
        DiscoveryEvent::create([...]);
    }
}, attempts: 3, timeout: 5);  // Retry on deadlock
```

**Option B: Upsert Pattern (Database Handles Atomicity)**
```php
// MySQL 8.0.20+ / MariaDB 10.3.3+
IpAddress::upsert(
    [
        'subnet_id' => $mappedSubnet->id,
        'ip_addr' => (string) $dec,
        'mac_address' => $mac,
        'hostname' => $hostname,
        'type' => $type,
        'last_seen' => Carbon::now(),
        'state' => 2,
        'description' => 'Discovered by DHCP',
    ],
    uniqueBy: ['subnet_id', 'ip_addr'],  // Unique key
    update: ['mac_address', 'hostname', 'last_seen']
);
```

**Option C: firstOrCreate with Locking (Mixed)**
```php
// Less safe but simpler
DB::transaction(function() {
    $ip = IpAddress::firstOrCreate(
        ['subnet_id' => $mappedSubnet->id, 'ip_addr' => (string) $dec],
        ['mac_address' => $mac, 'hostname' => $hostname, ...]
    );
    
    // Update only if MAC changed (idempotent)
    if ($ip->mac_address !== $mac || $ip->hostname !== $hostname) {
        $ip->update(['mac_address' => $mac, 'hostname' => $hostname, 'last_seen' => now()]);
    }
});
```

---

## VULNERABILITY #2: MISSING MAC ADDRESS NORMALIZATION

### Location
Lines 45 (DHCP), 108 (ARP) in `IngestMikrotikArpAction.php`

### Current Code (VULNERABLE)
```php
$mac = $lease['mac-address'] ?? $lease['mac'] ?? null;
// ...later...
$ip->mac_address = $mac;  // ← Stored as-is! Could be:
                           // "AA:BB:CC:DD:EE:FF" or
                           // "aa-bb-cc-dd-ee-ff" or
                           // "aabbccddeeff"
```

### Vulnerability Analysis

**Scenario: Device Reports Different MAC Format**
```
Scan 1 (from Mikrotik):
  Payload: {"mac-address": "AA:BB:CC:DD:EE:FF"}
  Stored: mac_address = "AA:BB:CC:DD:EE:FF"

Scan 2 (from same device, cached data):
  Payload: {"mac-address": "aa:bb:cc:dd:ee:ff"}
  Stored: mac_address = "aa:bb:cc:dd:ee:ff"  ← Different value!

Topology Query:
  Port.where('mac_address', 'AA:BB:CC:DD:EE:FF') → finds Port#1
  Port.where('mac_address', 'aa:bb:cc:dd:ee:ff') → NO MATCH (case mismatch!)
  Result: Topology thinks two different ports exist
```

**Result:** Same physical MAC stored as multiple entries, breaking:
- Port MAC lookups (Lines 152, 181 in IngestMikrotikTopologyAction)
- Device identification in Bridge Hosts processing
- Cross-device topology linking

**Real-World Example:**
```
Switch A Port 1 MAC in DB: "00:1A:2B:3C:4D:5E" (colon-separated uppercase)
Switch B reports neighbor:  {"mac-address": "001a2b3c4d5e"}  (no separators, lowercase)
Topology Link:  NOT CREATED (no MAC match)
Visibility:     Two disconnected switch ports appear unlinked when they're actually connected
```

---

### Root Cause

No standardization before database insert. Mikrotik, Ubiquiti, Cisco all format MAC addresses differently.

---

### Fix Required

**Normalization Function (Create in `IpConversionService` or new `MacAddressService`):**
```php
// app/Services/MacAddressService.php
class MacAddressService
{
    /**
     * Normalize MAC address to lowercase colon-separated format: xx:xx:xx:xx:xx:xx
     * Handles various input formats: uppercase, lowercase, colons, dashes, no separators
     */
    public static function normalize(?string $mac): ?string
    {
        if (!$mac) return null;
        
        // Remove all separators (: - .) and convert to lowercase
        $clean = strtolower(preg_replace('/[:.\-]/', '', trim($mac)));
        
        // Validate: must be 12 hex characters
        if (!preg_match('/^[a-f0-9]{12}$/', $clean)) {
            return null;  // Invalid MAC
        }
        
        // Format as xx:xx:xx:xx:xx:xx
        return implode(':', str_split($clean, 2));
    }
}
```

**Apply in IngestMikrotikArpAction:**
```php
use App\Services\MacAddressService;

// In DHCP handling (line ~45):
$mac = MacAddressService::normalize($lease['mac-address'] ?? $lease['mac'] ?? null);
if (!$mac) continue;  // Skip invalid MACs

// In ARP handling (line ~108):
$mac = MacAddressService::normalize($r['mac-address'] ?? $r['mac'] ?? null);
if (!$mac) continue;

// Before all IpAddress::create() calls
$ip = IpAddress::create([
    ...
    'mac_address' => $mac,  // Now guaranteed normalized
]);
```

**Apply in IngestMikrotikTopologyAction:**
```php
// Line ~136 (Bridge Hosts)
$mac = MacAddressService::normalize($h['mac-address'] ?? $h['mac'] ?? null);
if (!$mac) continue;

// Line ~183 (IP Neighbors)
$mac = MacAddressService::normalize($n['mac-address'] ?? $n['mac'] ?? null);
if (!$mac || !$iface) continue;
```

**Also Add Database Constraint:**
```php
// In migration or new migration
Schema::table('ip_addresses', function (Blueprint $table) {
    // Ensure MAC is always normalized (MySQL function or Laravel mutator)
    // Note: MAC uniqueness per device might not be desired (VMs share MACs)
});

Schema::table('ports', function (Blueprint $table) {
    // Same normalization for port MACs
});
```

---

## VULNERABILITY #3: UNIDIRECTIONAL TOPOLOGY LINKS

### Location
Lines 152-158 (Bridge Hosts), 183-190 (IP Neighbors) in `IngestMikrotikTopologyAction.php`

### Current Code (VULNERABLE)
```php
// Line 152: Bridge Host processing
if ($localPort && $remotePort) {
    $localPort->connected_to_port_id = $remotePort->id;
    $localPort->save();
    // ← MISSING: remotePort->connected_to_port_id = $localPort->id
}

// Line 183: Same issue with IP Neighbors
if ($localPort && $remotePort) {
    $localPort->connected_to_port_id = $remotePort->id;
    $localPort->save();
    // ← Only ONE direction recorded!
}
```

### Vulnerability Analysis

**Scenario: Switch A to Switch B Direct Link**

```
Scan Switch A (Device A):
  Bridge Host: MAC 00:11:22:33:44:55 (Switch B's Port 1)
  Local Port: Port "ether1"
  
  Code execution:
    localPort (A:ether1) → connected_to_port_id = remotePort (B:Port1).id
    Stored: A.ether1.connected_to_port_id = B.Port1.id ✓

Scan Switch B (Device B):
  Bridge Host: MAC aa:bb:cc:dd:ee:ff (Switch A's Port ether1)
  Local Port: Port "Port1"
  
  Code execution:
    localPort (B:Port1) → connected_to_port_id = remotePort (A:ether1).id
    Stored: B.Port1.connected_to_port_id = A.ether1.id ✓

Topology Result:
  ✓ A.ether1 → B.Port1
  ✓ B.Port1 → A.ether1
  ← Appears OK in this case!

BUT what if only ONE agent scans?
```

```
Scan Switch A only:
  Code execution:
    localPort (A:ether1) → connected_to_port_id = remotePort (B:Port1).id ✓

Scan Switch B NEVER happens (offline, permission denied, no agent):
  B.Port1.connected_to_port_id remains NULL ✗

Topology View:
  - A.ether1 knows it connects to B.Port1
  - B.Port1 does NOT know it connects back to A.ether1
  
  Vulnerability: If UI/API traversal uses:
    for each connected port, find bidirectional connection...
    Result: INCOMPLETE topology (missing B→A direction)
```

---

### Root Cause

**Assumption of Bidirectional Scanning:** Code assumes both sides will be scanned and will record links. If one device is offline/unmonitored:
- Link only recorded from active device
- Reverse path missing
- Topology becomes directional instead of undirected

**Bidirectional Recording Not Automatic:** Unlike some IPAM systems, this code requires **explicit reverse link creation**.

---

### Impact

1. **Incomplete Topology Views:** Clients only see links from monitored devices, not reverse paths
2. **Reporting Bias:** "Device A sees 10 connections, Device B sees 3" even if they're 1:1 links
3. **Capacity Planning Errors:** Topology calculations might ignore half the links
4. **Path Finding Errors:** Routing algorithms expecting undirected graphs fail

---

### Fix Required

**Option A: Always Create Reverse Links (Recommended)**
```php
// Inside IngestMikrotikTopologyAction.php

// Bridge Hosts (lines 152-158)
if ($iface && $localPort && $remotePort) {
    // Forward: local → remote
    $localPort->connected_to_port_id = $remotePort->id;
    $localPort->save();
    
    // REVERSE: remote → local (ensure bidirectionality)
    if ($remotePort->connected_to_port_id === null || 
        $remotePort->connected_to_port_id !== $localPort->id) {
        $remotePort->connected_to_port_id = $localPort->id;
        $remotePort->save();
    }
}

// IP Neighbors (lines 183-190)
if ($localPort && $remotePort) {
    $localPort->connected_to_port_id = $remotePort->id;
    $localPort->save();
    
    // REVERSE link
    if ($remotePort->connected_to_port_id !== $localPort->id) {
        $remotePort->connected_to_port_id = $localPort->id;
        $remotePort->save();
    }
}
```

**Option B: Create Link Record Instead of Port Column (Better Design)**

If `Port.connected_to_port_id` is just a denormalization, create a proper `PortLink` or `NetworkLink` table:

```php
// In migration
Schema::create('port_links', function (Blueprint $table) {
    $table->id();
    $table->foreignId('port_from_id')->constrained('ports')->onDelete('cascade');
    $table->foreignId('port_to_id')->constrained('ports')->onDelete('cascade');
    $table->string('link_type')->default('direct'); // direct, vlan, tunnel
    $table->timestamps();
    $table->unique(['port_from_id', 'port_to_id']);
});

// In action:
PortLink::firstOrCreate(
    ['port_from_id' => $localPort->id, 'port_to_id' => $remotePort->id],
    ['link_type' => 'direct']
);

// Bidirectional automatic via query scope:
$links = PortLink::query()
    ->where(function($q) use ($portId) {
        $q->where('port_from_id', $portId)
          ->orWhere('port_to_id', $portId);
    })
    ->get();  // Gets both directions
```

**Option C: Idempotent Link Update (Safe)**
```php
// Create or update, ensuring consistency
DB::transaction(function() use ($localPort, $remotePort) {
    // Update in order: smaller ID first to prevent deadlocks
    $ids = [$localPort->id, $remotePort->id];
    sort($ids);
    
    [$p1, $p2] = [$ids[0] === $localPort->id ? $localPort : $remotePort,
                   $ids[0] === $localPort->id ? $remotePort : $localPort];
    
    // Forward
    $p1->connected_to_port_id = $p2->id;
    $p1->save();
    
    // Reverse (always)
    $p2->connected_to_port_id = $p1->id;
    $p2->save();
});
```

---

## VULNERABILITY #4: MISSING TRANSACTION IN IngestMikrotikInventoryAction.php

### Location
Lines 45-65 (Device Save)

### Current Code (VULNERABLE)
```php
try {
    // Multiple updates scattered across function
    if (! empty($identity['name'])) {
        $device->hostname = $identity['name'];
    }
    
    if (! empty($resource['serial-number'])) {
        $device->serial_number = $resource['serial-number'];
    }
    
    // ... more updates ...
    
    $device->auth_extra = $extra;
    $device->save();  // ← Single save at end, but if it fails mid-operation?
    
} catch (\Throwable $e) {
    Log::warning('Failed to ingest inventory: ' . $e->getMessage());
}
```

### Vulnerability Analysis

**Scenario: Model Validation Fails After Partial Updates**
```
Device state BEFORE:
  hostname: 'old-hostname'
  serial_number: null
  device_model_id: 5
  auth_extra: {}

Processing inventory:
  1. Update hostname = 'new-hostname' ✓
  2. Update serial_number = 'ABC123' ✓
  3. Update device_model_id = 10 ✓
  4. Update auth_extra = {os_version: '6.49', uptime: '30d'} ✓
  5. save() called... ✗ FAILS due to validation error
     (e.g., new device_model_id points to non-existent model)

Result:
  Device state UNCHANGED (good!)
  But: No indication that SOME fields were attempted to be updated
  Problem: If error is transient and retry happens, assumption of
           partial state could cause issues
```

**More Critical Scenario: Mass Assignment**
```php
// If code later uses mass update:
$device->update([
    'hostname' => 'new',
    'serial_number' => 'ABC',
    'device_model_id' => 10,
    'auth_extra' => $extra
]);
// ← If 3 of 4 updates succeed before constraint violation
//   Partial data persisted with no rollback
```

**Database Constraint Violation:**
```
device_model_id foreign key constraint exists
DeviceModel where id=10 doesn't exist
→ save() fails
→ Partial updates in same transaction not rolled back
```

---

### Root Cause

No explicit `DB::transaction()` wrapper. While Laravel saves are atomic at DB level, the approach of multiple attribute assignments followed by single save creates:
1. Uncertainty about partial state
2. No protection if `->save()` fails after validation but before commit

---

### Fix Required

**Add Explicit Transaction Wrapper:**
```php
use Illuminate\Support\Facades\DB;

public function execute(?Device $device, array $data): void
{
    Log::info('IngestMikrotikInventoryAction: ingesting inventory for device ' . ($device->id ?? 'null'));
    
    if (! $device) return;
    
    $resource = $data['resource'] ?? $data['system_resource'] ?? $data['result']['resource'] ?? [];
    $identity = $data['identity'] ?? $data['system_identity'] ?? $data['result']['identity'] ?? [];
    
    try {
        DB::transaction(function() use ($device, $resource, $identity) {
            // All updates within transaction
            if (! empty($identity['name'])) {
                $device->hostname = $identity['name'];
            }
            
            if (! empty($resource['serial-number'])) {
                $device->serial_number = $resource['serial-number'];
            } elseif (! empty($resource['serial'])) {
                $device->serial_number = $resource['serial'];
            }
            
            $modelName = $resource['board-name'] ?? $resource['platform'] ?? $resource['model'] ?? null;
            if ($modelName) {
                $dm = \App\Models\DeviceModel::firstOrCreate(
                    ['model' => (string) $modelName],
                    ['brand' => 'Mikrotik', 'type' => 'switch']
                );
                $device->device_model_id = $dm->id;
            }
            
            $extra = is_array($device->auth_extra) ? $device->auth_extra : [];
            if (! empty($resource['version'])) {
                $extra['os_version'] = $resource['version'];
            } elseif (! empty($resource['firmware'])) {
                $extra['os_version'] = $resource['firmware'];
            }
            if (! empty($resource['uptime'])) {
                $extra['uptime'] = $resource['uptime'];
            }
            
            $device->auth_extra = $extra;
            $device->save();  // ← Atomic within transaction
            
        }, attempts: 3);  // Retry on deadlock
        
    } catch (\Throwable $e) {
        Log::warning('Failed to ingest inventory: ' . $e->getMessage());
        // Optionally: Mark device auth_status as problematic
        if ($device && isset($device->id)) {
            $device->update(['auth_status' => 'inventory_failed']);
        }
    }
}
```

---

## SUMMARY TABLE: Required Fixes

| File | Issue | Severity | Fix Type | Effort |
|---|---|---|---|---|
| `IngestMikrotikArpAction.php` | Race condition (concurrent IP updates) | CRITICAL | Add `DB::transaction()`, `->lockForUpdate()`, or `->upsert()` | 2h |
| `IngestMikrotikArpAction.php` | Missing MAC normalization | HIGH | Create `MacAddressService`, apply to all MAC reads | 1h |
| `IngestMikrotikTopologyAction.php` | Unidirectional links | HIGH | Add reverse link creation or create `PortLink` table | 2h |
| `IngestMikrotikInventoryAction.php` | Missing transaction wrapper | MEDIUM | Wrap save in `DB::transaction()` | 0.5h |

**Total Estimated Effort:** 5.5 hours

---

## DEPLOYMENT CHECKLIST

- [ ] **Before Merge:**
  1. Add `MacAddressService` class
  2. Update `IngestMikrotikArpAction` with transactions + MAC normalization
  3. Update `IngestMikrotikTopologyAction` with reverse link creation
  4. Update `IngestMikrotikInventoryAction` with transaction wrapper
  5. Add database constraint for MAC uniqueness (optional, depends on multi-tenant model)

- [ ] **Testing:**
  1. Unit test: `MacAddressService` with various formats (AA:BB:CC, aa-bb-cc, aabbcc)
  2. Feature test: Concurrent IP updates (simulate race condition)
  3. Feature test: MAC format variations in ARP data
  4. Feature test: Bidirectional link creation

- [ ] **Deployment:**
  1. Deploy to staging, run full discovery scan
  2. Verify: No duplicate MAC entries
  3. Verify: All topology links are bidirectional
  4. Monitor: Device inventory updates complete successfully

---

## RECOMMENDATIONS FOR NEXT PHASE

1. **Add Uniqueness Constraint on MAC + Device:**
   ```php
   // In migration for ports table
   $table->unique(['device_id', 'mac_address']);  // Assuming one MAC per device port
   ```

2. **Add Validation in Device/Port Models:**
   ```php
   // In Port model
   protected function casts(): array
   {
       return [
           'mac_address' => MacAddressCast::class,  // Auto-normalize on save
       ];
   }
   ```

3. **Create Integration Test Suite:**
   - Full discovery flow with concurrent scans
   - MAC address format variations
   - Topology bidirectionality verification

4. **Monitoring Alerts:**
   - Alert if MAC address changes for same IP/port combo (possible spoofing)
   - Alert if topology links are unidirectional (indicates missing scans)
   - Alert if inventory ingest fails repeatedly (auth issue)

---

**Document Generated:** 2025-12-16  
**Audit Status:** COMPREHENSIVE VULNERABILITIES IDENTIFIED & FIXES PROVIDED  
**Next Action:** Implement fixes per DEPLOYMENT CHECKLIST before Phase 4 go-live
