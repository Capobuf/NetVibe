# phpIPAM UI/UX Mapping Document

> **Date:** 2025-11-25  
> **Purpose:** Reverse engineering della UI/UX di phpIPAM per replicare fedelmente l'esperienza utente in Modern IPAM (Laravel + Filament).

---

## 1. NAVIGATION STRUCTURE (Top Navbar)

### 1.1 Main Navigation Menu

phpIPAM utilizza una **navbar orizzontale collapsabile** con menu dinamici generati in base al contesto:

#### Modalità Menu:
- **Static Menu** (opzionale utente) - tutte le sezioni visibili contemporaneamente
- **Dynamic Menu** (default) - si adatta al contesto (Dashboard, Tools, Administration, Section-specific)

#### Menu Contexts:

**A) Dashboard / Tools Context** (`menu-tools.php`)
```
Dashboard | Tools | Administration
```

**B) Subnets/Sections Context** (`menu-sections.php`)
```
Dashboard | Section 1 | Section 2 | ... | Tools | Administration
```

**C) Administration Context** (`menu-administration.php`)
```
Dashboard | Tools |administration (mostra solo le sezioni Admin)
```

---

## 2. TOOLS MENU (Sidebar Sx - quando in /tools/X)

### 2.1 Menu "Tools" (icona: fa-wrench)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| Documentation | fa-book | tools/documentation | Read phpIPAM documentation |
| Search | fa-search | tools/search | Search database Addresses, subnets and VLANs |
| IP calculator | fa-calculator | tools/ip-calculator | IPv4v6 calculator for subnet calculations |
| Bandwidth calculator | fa-calculator | tools/ip-calculator/bw-calculator | Bandwidth calculator |
| Changelog | fa-clock-o | tools/changelog | Changelog for all network objects *(solo se enableChangelog=1)* |
| Log files | fa-list | tools/logs | Browse phpipam log files |
| IP requests | fa-plus | tools/requests | Manage IP requests *(solo se enableIPrequests=1)* |
| Instructions | fa-info | tools/instructions | Instructions for managing IP addresses |
| PowerDNS | fa-database | tools/powerDNS | PowerDNS *(se enablePowerDNS=1)* |
| DHCP | fa-database | tools/dhcp | DHCP information *(se enableDHCP=1)* |
| PSTN prefixes | fa-phone | tools/pstn-prefixes | PSTN prefixes *(se enablePSTN=1)* |
| MAC lookup | fa-sitemap | tools/mac-lookup | Lookup MAC address vendor |
| Vaults | fa-key | tools/vaults | Secure information storing *(se enableVaults=1)* |

### 2.2 Menu "Subnets" (icona: fa-sitemap)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| Customers | fa-users | tools/customers | Customers *(se enableCustomers=1)* |
| Favourite networks | fa-star | tools/favourites | Favourite networks |
| Subnets | fa-sitemap | tools/subnets | All subnets |
| VLAN | fa-cloud | tools/vlan | VLANs and belonging subnets |
| VRF | fa-cloud | tools/vrf | VRFs and belonging networks *(se enableVRF=1)* |
| NAT | fa-exchange | tools/nat | NAT translations *(se enableNAT=1)* |
| Routing | fa-exchange | tools/routing | Routing information *(se enableRouting=1)* |
| Multicast networks | fa-map-o | tools/multicast-networks | Multicast subnets and mapping *(se enableMulticast=1)* |
| Firewall Zones | fa-fire | tools/firewall-zones | Display firewall zone to device mappings *(se enableFirewallZones=1)* |
| **Scanned networks** | fa-eye | tools/scanned-networks | List of subnets to be scanned for online hosts and detect new hosts |
| Subnet masks | fa-th-large | tools/subnet-masks | Table of all subnet masks with different representations |
| Temporary shares | fa-share-alt | tools/temp-shares | List of temporary shared objects *(se tempShare=1)* |
| Inactive Hosts | fa-thumbs-down | tools/inactive-hosts | List of inactive hosts |
| Duplicates | fa-files-o | tools/duplicates | List of duplicate subnets and addresses |
| Threshold | fa-bullhorn | tools/threshold | List of thresholded subnets *(se enableThreshold==1)* |

### 2.3 Menu "Devices" (icona: fa-desktop)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| Devices | fa-desktop | tools/devices | All configured devices |
| Racks | fa-bars | tools/racks | Rack information *(se enableRACK=1)* |
| Circuits | fa-random | tools/circuits | Circuit information *(se enableCircuits=1)* |
| Locations | fa-map | tools/locations | Locations *(se enableLocations=1)* |

### 2.4 Menu "User Menu" (icona: fa-user)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| My account | fa-user | tools/user-menu | Manage your account |

---

## 3. ADMINISTRATION MENU (Sidebar Sx - quando in /administration/X)

### 3.1 Menu "Server management" (icona: fa-cogs)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| phpIPAM settings | fa-cogs | administration/settings | phpIPAM server settings |
| Users | fa-user | administration/users | User management |
| Groups | fa-users | administration/groups | User group management |
| Authentication methods | fa-server | administration/authentication-methods | Manage user authentication methods and servers |
| 2FA | fa-shield | administration/2fa | Two-factor authentication with TOTP provider |
| Password policy | fa-unlock | administration/password-policy | Set user password policy |
| Mail settings | fa-envelope-o | administration/mail | Set mail parameters and mail server settings |
| API | fa-cogs | administration/api | API settings |
| PowerDNS | fa-database | administration/powerDNS | PowerDNS settings *(se enablePowerDNS=1)* |
| DHCP | fa-database | administration/dhcp | DHCP settings *(se enableDHCP=1)* |
| **Scan agents** | fa-user-secret | administration/scan-agents | phpipam Scan agents |
| Languages | fa-language | administration/languages | Manage languages |
| Widgets | fa-tachometer | administration/widgets | Manage widget settings |
| Tags | fa-tag | administration/tags | Manage tags |
| PSTN prefixes | fa-phone | administration/pstn-prefixes | PSTN prefixes *(se enablePSTN=1)* |
| Edit instructions | fa-info | administration/instructions | Set phpipam instructions for end users |

### 3.2 Menu "IP related management" (icona: fa-sitemap)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| Customers | fa-users | administration/customers | Customer management *(se enableCustomers=1)* |
| Sections | fa-server | administration/sections | Section management |
| Subnets | fa-sitemap | administration/subnets | Subnet management |
| VLAN | fa-cloud | administration/vlans | VLAN management |
| VRF | fa-cloud | administration/vrf | VRF management *(se enableVRF=1)* |
| NAT | fa-exchange | administration/nat | NAT settings *(se enableNAT=1)* |
| Routing | fa-exchange | administration/routing | Routing management *(se enableRouting=1)* |
| Nameservers | fa-cloud | administration/nameservers | Recursive nameserver sets for subnets |
| Firewall Zones | fa-fire | administration/firewall-zones | Firewall zone management *(se enableFirewallZones=1)* |
| Import / Export | fa-upload | administration/import-export | Import/Export IP related data (VRF, VLAN, Subnets, IP, Devices) |
| RIPE import | fa-cloud-download | administration/ripe-import | Import subnets from RIPE |
| IP requests | fa-plus | administration/requests | Manage IP requests *(se enableIPrequests=1)* |
| Filter IP fields | fa-filter | administration/filter-fields | Select which default address fields to display |
| Required IP fields | fa-filter | administration/required-fields | Select which address fields are required to be filled when creating address |
| Custom fields | fa-magic | administration/custom-fields | Manage custom fields |

### 3.3 Menu "Device management" (icona: fa-desktop)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| Devices | fa-desktop | administration/devices | Device management |
| Racks | fa-bars | administration/racks | Rack management *(se enableRACK=1)* |
| Circuits | fa-random | administration/circuits | Circuits management *(se enableCircuits=1)* |
| SNMP | fa-cogs | administration/snmp | SNMP management *(se enableSNMP=1)* |
| Locations | fa-map | administration/locations | Locations *(se enableLocations=1)* |

### 3.4 Menu "Tools" (icona: fa-wrench)
| Name | Icon | Route | Description |
|------|----|-------|-------------|
| Version check | fa-check | administration/version-check | Check for latest version of phpipam |
| Verify database | fa-magic | administration/verify-database | Verify that database files are installed ok |
| Replace fields | fa-search-plus | administration/replace-fields | Search and replace content in database |

---

## 4. SUBNET MENU (Sidebar Sx - quando in /subnets/sectionId/subnetId)

### 4.1 Struttura del menu Subnets
Quando si visualizza una sezione, il **menu laterale sinistro** mostra:

1. **"Belonging subsections"** (se esistono sottosezioni)
   - Lista delle sottosezioni con link di navigazione

2. **"Available subnets"** (se `showSubnet=1`)
   - Lista gerarchica (tree) delle subnets della sezione
   - Espandibili/collassabili (icona fa-expand/fa-compress)
   - Contatore indirizzi utilizzati per ciascuna subnet

3. **"Associated VLANs"** (se `showVLAN=1`)
   - Lista delle VLAN associate

4. **"Associated VRFs"** (se `showVRF=1`)
   - Lista dei VRF associati

### 4.2 Pulsanti di azione (in basso al menu)
Se `section_permission == 3` (RWA):

| Icona | Funzione | Script |
|-------|----------|--------|
| fa-plus | Add new subnet | (modale inline) |
| fa-search | Search for free subnets in section | app/admin/subnets/find_free_section_subnets.php |
| fa-cogs | Search for subnets through SNMP | (se enableSNMP=1) |
| fa-folder | Add new folder | (modale inline) |

### 4.3 Subnet Details Page - Tab Navigation

Quando viene aperta una subnet (`/subnets/sectionId/subnetId`), vengono visualizzati questi **tab orizzontali**:

| Tab | Route | Description |
|-----|-------|-------------|
| Subnet details | /subnets/{sectionId}/{subnetId} | Default view with address table |
| Space map | /subnets/{sectionId}/{subnetId}/map | Visual subnet space allocation |
| Mask search | /subnets/{sectionId}/{subnetId}/mapsearch | Search by subnet mask |
| Permissions | /subnets/{sectionId}/{subnetId}/permissions | Permissions (solo admin) |
| NAT | /subnets/{sectionId}/{subnetId}/nat | NAT translations *(se enableNAT=1)* |
| Location | /subnets/{sectionId}/{subnetId}/location | Location map *(se enableLocations=1)* |
| Changelog | /subnets/{sectionId}/{subnetId}/changelog | Change history |

---

## 5. IP ADDRESS TABLE (print-address-table.php)

### 5.1 Colonne di Default (Always Visible)
| Nome Colonna | Campo DB | Descrizione |
|--------------|---------|-------------|
| **IP address** | `ip_addr` | Indirizzo IP con status icon (online/offline/warning) + tag tipo (Reserved/Offline/DHCP) |
| **Hostname** | `hostname` | Hostname con DNS resolution status + PowerDNS records |
| **Description** | `description` | Descrizione dell'IP |

### 5.2 Colonne Condizionali/Optional

Le colonne seguenti vengono mostrate **SOLO SE**:
1. Sono presenti in `User->settings->IPfilter` (campo semicolon-separated)
2. Almeno un indirizzo ha quel campo compilato (auto-hide se tutti vuoti)

| Nome Colonna | Campo DB | Condizione di Visibilità | CSS Class |
|--------------|---------|---------------------------|-----------|
| **FW object** | `firewallAddressObject` | `enableFirewallZones=1` + `IPfilter` contiene 'firewallAddressObject' | - |
| **MAC** | `mac` | `IPfilter` contiene 'mac' + almeno un MAC presente | `narrow` |
| **Note** (icona) | `note` | `IPfilter` contiene 'note' + note esistenti | `narrow` (icona fa-comment-o con tooltip) |
| **Device** | `switch` | `IPfilter` contiene 'switch' + device permissions≥R | `hidden-xs hidden-sm hidden-md` |
| **Port** | `port` | `IPfilter` contiene 'port' + almeno un port compilato | `hidden-xs hidden-sm hidden-md` |
| **Location** | `location` | `IPfilter` contiene 'location' + location permissions≥R | `hidden-xs hidden-sm hidden-md` |
| **Owner** | `owner` | `IPfilter` contiene 'owner' + owner compilato | `hidden-xs hidden-sm` |
| **Customer** | `customer_id` | `enableCustomers=1` + `IPfilter` + customer permissions≥R | `hidden-xs hidden-sm` |
| **Custom Fields** | `custom_*` | In base a custom fields definiti (filtra nascosti e vuoti) | `customField hidden-xs hidden-sm hidden-md` |

### 5.3 Colonna Actions (Always Last)

**Action Buttons (se subnet_permission > 1):**

| Icona | Azione | Descrizione |
|-------|--------|-------------|
| fa-pencil | Edit | Edit IP address details |
| fa-cogs | Ping | Check availability |
| fa-search | Search | Search same hostnames in db (disabilitato se hostname vuoto) |
| fa-envelope-o | Mail | Send mail notification |
| fa-repeat | FW Regen | Generate/regenerate firewall address object *(se zone attiva)* |
| fa-times | Delete | Delete IP address |

**Per compressed ranges:** solo Edit e Delete sono attivi.

### 5.4 Special Rows

**Row Types:**

1. **Free/Unused ranges:** Mostra gap tra IP allocati (se `User->hideFreeRange != 1`)
   ```html
   <tr class="th"><td colspan="X" class="unused">192.168.1.10 - 192.168.1.50 (41 hosts)</td></tr>
   ```

2. **DHCP/Reserved ranges:** Visualizzazione compressa per indirizzi consecutivi dello stesso tipo
   ```html
   <tr class="dhcp"><td>192.168.1.100 - 192.168.1.200 (101)</td>...</tr>
   ```

3. **Gateway addresses:** Riga con classe `gateway` + icona fa-info-circle

4. **Linked/Similar addresses:** Se `User->settings->link_field` è impostato, mostra sotto ogni IP gli indirizzi correlati

### 5.5 Status Icons

| Stato | Classe CSS | Tooltip | Logica |
|-------|-----------|---------|--------|
| Online | `status-success` | Address is alive + Last seen: [timestamp] | `tDiff < pingStatus[0]` |
| Warning | `status-warning` | Address warning + Last seen: [timestamp] | `tDiff < pingStatus[1]` |
| Offline | `status-error` | Address is offline + Last seen: [timestamp] | `tDiff > pingStatus[1]` |
| Never seen | `status-neutral` | Address was never online | `lastSeen IS NULL` |
| Excluded | `status-padded` | (no tooltip) | `excludePing=1` |

---

## 6. SCAN WORKFLOW (subnet-scan.php + Scan Agents)

### 6.1 Triggering della Scansione

**Punto di accesso:** quando si visualizza una subnet ('subnets/sectionId/subnetId'), la scansione può essere avviata SOLO dalla subnet details page (non direttamente dalla lista).

**Prerequisiti:**
- `User permission` ≥ 3 (RWA)
- `scanPingType` ≠ "none"
- Solo IPv4 (IPv6 scanning not supported)

### 6.2 Modal "Scan subnet" (popup)

La modale mostra:

**A) Subnet info** (read-only)
```
Subnet: 192.168.1.0/24 (Description)
```

**B) Scan Type (dropdown)**

**Discovery scans** (optgroup):
- `scan-icmp` - Ping scan
- `scan-telnet` - Telnet scan  
  *(se selected: mostra campo "Ports" per inserire porte da testare)*
- `snmp-route-all` - SNMP nested subnets scan
- `scan-snmp-arp` - SNMP ARP scan
- `snmp-mac` - SNMP MAC address scan

**Status update scans** (optgroup):
- `update-icmp` - Ping scan (update status only)
- `update-snmp-arp` - SNMP ARP scan (update status only)

**C) Debug (checkbox)**
```
[x] Debug
```

**D) Info alert:**
```
· Discovery scans discover new hosts
· Status update scans update alive status for whole subnet
```

**E) Footer buttons:**
```
[Cancel] [Scan subnet]
```

### 6.3 Scan Agents (CRITICAL)

**phpIPAM NON esegue le scansioni direttamente dal server web.**

Si basa su **Scan Agents** configurati in `Administration → Scan agents`.

**Tabella `scanAgents` (DB):**
- `id` - ID Agent
- `name` - Nome (es. "Localhost")
- `description` - Descrizione
- `code` - Token/API key per autenticazione remota
- `type` - Tipo: sempre "localhost" per il built-in

**Default Agent:** `id=1, name="localhost"` (non eliminabile)

**Comportamento phpIPAM:**
- **NON** mostra una selezione Agent nella UI scan modal (contrariamente a quanto potrebbe sembrare dalla documentazione generica)
- Lo scan viene eseguito tramite chiamate AJAX che invocano script PHP sul server
- Gli script utilizzano comandi di sistema (fping, telnet, snmpwalk) **localmente**
- Remote Agents sarebbero configurabili centralizzate ma richiedono installazione di agent esterni

**IMPORTANT:** La UI **NON** ha una dropdown per selezionare l'agent prima dello scan nella versione base. Questa è una **feature più avanzata** che richiede agent remoti installati.

### 6.4 Scan Result Flow

1. User clicca "Scan subnet" (button con `id="subnetScanSubmit"`)
2. AJAX POST a `app/subnets/scan/subnet-scan-execute.php` con:
   - `subnetId`
   - `type` (scan type)
   - `csrf-cookie`
   - Opzionalmente: `telnetports`, `debug`
3. Server esegue lo scan (fping/telnet/snmp) e restituisce risultati
4. Risultati mostrati in `div#subnetScanResult` tramite inclusione di:
   - `subnet-scan-result.php` (ping)
   - `subnet-scan-result-scan-telnet.php` (telnet)
   - `subnet-scan-result-scan-snmp-arp.php` (snmp-arp)
   - etc.
5. Utente visualizza lista di "New addresses discovered" con checkbox per selezionare quali importare
6. **Conferma manuale richiesta:** user deve cliccare "Add discovered addresses" per salvare nel DB

### 6.5 Scan Commands (Backend - fping wrapper example)

phpIPAM usa wrapper PHP che invocano comandi di sistema:

**Esempio per ICMP scan (fping):**
```php
// functions/scan/subnet_scan_fping.php
$command = "fping -aAqg -i 10 -r 0 192.168.1.0/24 2>&1";
exec($command, $output);
// Parse output per estrarre IP online
```

---

## 7. DESIGN PATTERNS & UI CONVENTIONS

### 7.1 Bootstrap Components Used

- **Modals:** Tutti i form di Add/Edit (subnet, IP, etc.) usano modali con classi:
  - `.popup` (container)
  - `.pHeader` (titolo modale)
  - `.pContent` (form body)
  - `.pFooter` (pulsanti azione)

- **Tables:** 
  - `.table-condensed` per tabelle compatte
  - `.table-noborder` per tabelle senza bordi
  - `.sortable` per tabelle ordinabili (client-side con bootstrap-table.js)

- **Buttons:**
  - `.btn-xs` (piccoli)
  - `.btn-default` (grigio)
  - `.btn-success` (verde per azioni create)
  - `.btn-danger` (rosso per delete)

- **Panels:**
  - `.panel-default` per menu sidebar
  - `.panel-heading` + `.panel-title`
  - `.list-group` + `.list-group-item` per voci menu

### 7.2 Icons (Font Awesome 4.x)

phpIPAM usa **Font Awesome 4.7.0** (classi `.fa .fa-*`).

### 7.3 Color Coding & Status Classes

- **Online/Alive:** Verde (classe `success`)
- **Warning:** Giallo/arancione (classe `warning`)
- **Offline/Error:** Rosso (classe `error`)
- **Neutral/Unknown:** Grigio (classe `neutral`)
- **DHCP/Reserved:** Blu chiaro (classe `dhcp`)
- **Free/Unused:** Verde chiaro (classe `unused`)
- **Gateway:** Classe `gateway` (stile badge/icona speciale)

---

## 8. KEY TAKEAWAYS PER MODERN IPAM

### 8.1 Strict Replica Requirements

1. **Menu Structure:**
   - Replicare **esattamente** i 4 menu (Tools/Subnets/Devices/User Menu) con stessa gerarchia
   - Administration menu separato con sottomenu (Server/IP/Device/Tools)
   - Subnets sidebar dinamico per navigazione sezioni

2. **Scan Workflow:**
   - **NON** aggiungere pulsante "Scan" diretto sulle righe subnet
   - Scan SOLO da subnet details page
   - Modale con selezione tipo scan + opzioni
   - Risultati con preview + conferma manuale import
   - Future: gestione Scan Agents (inizialmente solo Localhost)

3. **IP Table Columns:**
   - Auto-hide colonne vuote (MAC, Port, Note, Device, etc.)
   - Rispettare filtri utente da `IPfilter` setting
   - Status icon sempre visibile se `pingSubnet=1`
   - Action buttons differenti per permission level

4. **UI Conventions:**
   - Bootstrap 3.x compatible
   - Font Awesome 4.x icons
   - Modali per tutti i form (non page separate)
   - Tree menu espandibile per subnet navigation
   - Color coding consistente per stati (green/yellow/red/blue)

### 8.2 FilamentPHP Mapping Strategy

**Filament Components da usare:**

| phpIPAM Element → | Filament Component |
|------------------|--------------------|
| Top Navbar | `NavigationGroup` + `NavigationItem` |
| Sidebar Menu (Tools/Admin) | `->cluster()` nel Resource |
| Subnet Tree Menu | Custom `ViewComponent` per tree navigation |
| IP Address Table | `Table::make()` con colonne condizionali |
| Scan Modal | `Action::make('scan')->form([...])` |
| Action Icons | `Table::column()->actions([...])` |

**Clusters Structure (proposal):**
```php
app/Filament/Clusters/
├── Tools.php              # Tools menu cluster
├── Administration.php     # Admin menu cluster
└── Subnets.php            # Subnets cluster (context-dependent)
```

**Resources:**
```php
app/Filament/Resources/
├── SubnetResource.php     # In Subnets cluster
├── IpAddressResource.php  # In Subnets cluster
├── DeviceResource.php     # In Tools/Devices
├── VlanResource.php       # In Tools/Subnets
└── ScanAgentResource.php  # In Administration/Server
```

### 8.3 Actions & Services Layer

Tutta la logica scan DEVE andare in:
```
app/Actions/Scan/
├── ExecutePingScanAction.php
├── ExecuteTelnetScanAction.php
├── ExecuteSnmpArpScanAction.php
├── ParseScanResultsAction.php
└── ImportDiscoveredAddressesAction.php

app/Services/
├── FpingWrapper.php       # Wrapper per fping command
├── TelnetWrapper.php      # Wrapper per telnet
└── SnmpWrapper.php        # Wrapper per snmpwalk
```

**NO logica nei Controller/Resource:**
```php
// WRONG ❌
public function scan(Subnet $subnet) {
    exec("fping -aAqg ...");
}

// CORRECT ✅
public function scan(Subnet $subnet) {
    return app(ExecutePingScanAction::class)->execute($subnet);
}
```

---

## 9. PHPIPAM UI SCREENSHOTS REFERENCES

> **TODO (Future):** Aggiungere screenshot per:
> - Menu Tools (sidebar)
> - Menu Administration (sidebar)
> - Subnet tree menu (espanso/collassato)
> - IP Address table (con tutte le colonne visibili)
> - Scan subnet modal (dropdown tipo scan + opzioni)
> - Scan results preview (new addresses discovered)

---

**End of Document**  
**Revision:** 1.0  
**Agent:** Antigravity (Reverse Engineering Phase 1)
