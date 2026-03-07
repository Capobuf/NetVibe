# NetVibe worklog (current state)

## Admin IA (Prompt 1)

- Sidebar is IPAM-first:
  - Navigation: Dashboard
  - Networks: VLANs, Subnets, IP addresses, IPAM settings
  - Discovery: Discovery overview, MikroTik targets, MikroTik devices, Discovery runs, Review queue
  - System: Job runs, Task results, MikroTik settings
- Discovery operations are centered on a single server-rendered **Discovery overview** admin page.
- MikroTik proxy views (`MikroTikVLAN`, `MikroTikSubnet`) remain registered but are not top-level sidebar entries.

## Canonical vs observed separation (Prompt 2)

- Added `IPAddressObservation` model for latest observed facts per source-device-per-IP.
- Added `ReviewItem` model as a persistent operational review queue with dedupe by `dedupe_key`.
- DHCP/ARP sync now always upserts observations.
- Canonical `IPAddress` records keep MSP-owned editorial fields protected:
  - sync updates operational fields (timestamps, lease metadata, sync provenance/state)
  - sync does not silently overwrite canonical `hostname`, `mac_address`, `status`, `note`
- New observed IPs create:
  - minimal canonical `IPAddress` (blank canonical hostname/MAC)
  - one `IPAddressObservation`
  - one deduped `ReviewItem(kind=new_ip_observed)`
- Imported MikroTik subnets now default to:
  - `scan_enabled=False`
  - `discover_subnet=False`
- Failed MikroTik sync runs now create/update `ReviewItem(kind=sync_failure)` deduped by device + error category.
- Discovery identity conflicts now create/update `ReviewItem(kind=discovery_conflict)`.

## Explicit stale-claim removals

The following previously documented claims were removed because they no longer match code:

- DHCP/ARP synchronizations directly and silently overwriting canonical hostname/MAC.
- Newly imported MikroTik subnets auto-enabling scans/discovery by default.
- Sidebar architecture that treated all MikroTik operational lists as top-level primary entries.
