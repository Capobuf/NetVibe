"""MikroTik → IPAM synchronization engine.

Orchestrates: collect facts → normalize → upsert IPAM objects.

Entry points
~~~~~~~~~~~~
- ``run_device_test(device)``  — connection test + metadata population.
- ``run_device_sync(device, sync_run)`` — full sync to IPAM.
"""

from __future__ import annotations

import logging
from ipaddress import ip_address, ip_network
from typing import TYPE_CHECKING

from django.db import models, transaction
from django.utils import timezone

from ipam.contracts import MikroTikSnapshotResultV1
from ipam.crypto import decrypt_password
from ipam.integrations.mikrotik_client import (
    MikroTikClient,
    MikroTikClientError,
)
from ipam.integrations.mikrotik_mapper import build_snapshot
from ipam.models import (
    VLAN,
    IPAddress,
    MikroTikDevice,
    MikroTikSyncRun,
    SourceKind,
    Subnet,
    SyncState,
)

if TYPE_CHECKING:
    pass

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Client factory
# ---------------------------------------------------------------------------

def _make_client(device: MikroTikDevice) -> MikroTikClient:
    password = decrypt_password(device.password_encrypted)
    return MikroTikClient(
        base_url=device.base_url,
        username=device.username,
        password=password,
        verify_tls=device.verify_tls,
    )


# ---------------------------------------------------------------------------
# Snapshot collection
# ---------------------------------------------------------------------------

def _collect_snapshot(client: MikroTikClient, device: MikroTikDevice) -> MikroTikSnapshotResultV1:
    """Fetch all relevant facts from the device and return a DTO."""
    identity_raw = client.get_identity()
    resource_raw = client.get_system_resource()

    # Optional data sources; only fetch when the device's import policy allows.
    bridge_vlans_raw = client.get_bridge_vlans() if device.import_vlans else []
    ip_addresses_raw = client.get_ip_addresses() if device.import_subnets else []
    dhcp_servers_raw = client.get_dhcp_servers() if device.import_dhcp_leases else []
    dhcp_leases_raw = client.get_dhcp_leases() if device.import_dhcp_leases else []
    arp_raw = client.get_arp() if device.import_arp else []
    neighbors_raw = client.get_neighbors() if device.import_neighbors else []

    return build_snapshot(
        device_host=device.host,
        identity_raw=identity_raw,
        resource_raw=resource_raw,
        bridge_vlans_raw=bridge_vlans_raw,
        ip_addresses_raw=ip_addresses_raw,
        dhcp_servers_raw=dhcp_servers_raw,
        dhcp_leases_raw=dhcp_leases_raw,
        arp_raw=arp_raw,
        neighbors_raw=neighbors_raw,
    )


# ---------------------------------------------------------------------------
# Device test (Save and test)
# ---------------------------------------------------------------------------

def run_device_test(
    device: MikroTikDevice,
    sync_run: MikroTikSyncRun | None = None,
) -> MikroTikSyncRun:
    """Connection test: populate metadata fields and record a test run."""
    now = timezone.now()
    if sync_run is None:
        sync_run = MikroTikSyncRun.objects.create(
            device=device,
            mode=MikroTikSyncRun.Mode.TEST,
            status=MikroTikSyncRun.Status.RUNNING,
            scheduled_for=now,
            started_at=now,
        )
    elif sync_run.status != MikroTikSyncRun.Status.RUNNING:
        sync_run.status = MikroTikSyncRun.Status.RUNNING
        sync_run.started_at = sync_run.started_at or now
        sync_run.error_message = ''
        sync_run.save(update_fields=['status', 'started_at', 'error_message'])

    try:
        client = _make_client(device)
        identity = client.get_identity()
        resource = client.get_system_resource()

        # Populate device metadata — do not overwrite a user-edited name.
        if not device.name:
            device.name = identity.get('name', '') or device.host

        device.last_seen_identity = identity.get('name', '')
        device.last_seen_version = resource.get('version', '')
        device.last_seen_board = resource.get('board-name', '')
        device.last_seen_platform = resource.get('platform', '')
        device.last_error = ''
        device.last_successful_sync_at = now
        device.save(update_fields=[
            'name', 'last_seen_identity', 'last_seen_version',
            'last_seen_board', 'last_seen_platform',
            'last_error', 'last_successful_sync_at',
        ])

        sync_run.status = MikroTikSyncRun.Status.SUCCESS
        sync_run.finished_at = timezone.now()
        sync_run.save(update_fields=['status', 'finished_at'])

    except MikroTikClientError as exc:
        device.last_error = str(exc)[:2000]
        device.save(update_fields=['last_error'])
        sync_run.status = MikroTikSyncRun.Status.FAILED
        sync_run.error_message = str(exc)[:2000]
        sync_run.finished_at = timezone.now()
        sync_run.save(update_fields=['status', 'error_message', 'finished_at'])
    except Exception as exc:
        device.last_error = f'{exc.__class__.__name__}: {exc}'[:2000]
        device.save(update_fields=['last_error'])
        sync_run.status = MikroTikSyncRun.Status.FAILED
        sync_run.error_message = f'{exc.__class__.__name__}: {exc}'[:2000]
        sync_run.finished_at = timezone.now()
        sync_run.save(update_fields=['status', 'error_message', 'finished_at'])

    return sync_run


# ---------------------------------------------------------------------------
# Full sync
# ---------------------------------------------------------------------------

def run_device_sync(device: MikroTikDevice, sync_run: MikroTikSyncRun) -> MikroTikSyncRun:
    """Full sync: collect facts and upsert IPAM objects."""
    sync_run.status = MikroTikSyncRun.Status.RUNNING
    sync_run.started_at = timezone.now()
    sync_run.save(update_fields=['status', 'started_at'])

    try:
        client = _make_client(device)
        snapshot = _collect_snapshot(client, device)

        # Update device metadata from snapshot.
        device.last_seen_identity = snapshot.identity.name
        device.last_seen_version = snapshot.system_resource.version
        device.last_seen_board = snapshot.system_resource.board_name
        device.last_seen_platform = snapshot.system_resource.platform
        if not device.name:
            device.name = snapshot.identity.name or device.host

        warnings: list[str] = []
        counters = _apply_snapshot(device, snapshot, sync_run, warnings)

        device.last_error = ''
        device.last_successful_sync_at = timezone.now()
        device.save(update_fields=[
            'name', 'last_seen_identity', 'last_seen_version',
            'last_seen_board', 'last_seen_platform',
            'last_error', 'last_successful_sync_at',
        ])

        sync_run.vlans_created = counters['vlans_created']
        sync_run.vlans_updated = counters['vlans_updated']
        sync_run.subnets_created = counters['subnets_created']
        sync_run.subnets_updated = counters['subnets_updated']
        sync_run.ips_created = counters['ips_created']
        sync_run.ips_updated = counters['ips_updated']
        sync_run.warning_summary = warnings
        sync_run.observed_summary = _build_observed_summary(snapshot)
        sync_run.status = MikroTikSyncRun.Status.SUCCESS
        sync_run.finished_at = timezone.now()
        sync_run.save()

    except MikroTikClientError as exc:
        _fail_sync_run(device, sync_run, str(exc))
    except Exception as exc:
        _fail_sync_run(device, sync_run, f'{exc.__class__.__name__}: {exc}')
        raise

    return sync_run


def _fail_sync_run(device: MikroTikDevice, sync_run: MikroTikSyncRun, error: str) -> None:
    device.last_error = error[:2000]
    device.save(update_fields=['last_error'])
    sync_run.status = MikroTikSyncRun.Status.FAILED
    sync_run.error_message = error[:2000]
    sync_run.finished_at = timezone.now()
    sync_run.save(update_fields=['status', 'error_message', 'finished_at'])


# ---------------------------------------------------------------------------
# Apply snapshot to IPAM
# ---------------------------------------------------------------------------

def _apply_snapshot(
    device: MikroTikDevice,
    snapshot: MikroTikSnapshotResultV1,
    sync_run: MikroTikSyncRun,
    warnings: list[str],
) -> dict:
    counters = {
        'vlans_created': 0,
        'vlans_updated': 0,
        'subnets_created': 0,
        'subnets_updated': 0,
        'ips_created': 0,
        'ips_updated': 0,
    }

    now = timezone.now()
    trace_id = sync_run.trace_id
    job_id = str(sync_run.pk)

    # --- 1. VLANs ---
    if device.import_vlans:
        counters.update(_sync_vlans(device, snapshot, now, trace_id, job_id, warnings))

    # --- 2. Subnets ---
    if device.import_subnets and device.is_authoritative_for_l3:
        counters.update(_sync_subnets(device, snapshot, now, trace_id, job_id, warnings))

    # --- 3. IP records (DHCP leases → ARP enrichment) ---
    if device.import_dhcp_leases and device.is_authoritative_for_dhcp:
        counters.update(_sync_dhcp_leases(device, snapshot, now, trace_id, job_id, warnings))

    if device.import_arp:
        ip_counts = _sync_arp(device, snapshot, now, trace_id, job_id, warnings)
        counters['ips_created'] += ip_counts.get('ips_created', 0)
        counters['ips_updated'] += ip_counts.get('ips_updated', 0)

    return counters


# ---------------------------------------------------------------------------
# VLAN sync
# ---------------------------------------------------------------------------

def _sync_vlans(device, snapshot, now, trace_id, job_id, warnings) -> dict:
    created = 0
    updated = 0

    for bv in snapshot.bridge_vlans:
        # Ignore dynamic bridge VLAN rows per PO decision.
        if bv.dynamic and device.ignore_dynamic_bridge_vlans:
            continue

        # vlan_ids can be a single number or a comma-separated range.
        # RouterOS uses formats like "10" or "10,20" or "10-20".
        for vlan_number in _parse_vlan_ids(bv.vlan_ids):
            # Build a stable remote ID so we can match on re-sync.
            remote_id = f'bridge-vlan:{bv.bridge}:{vlan_number}'

            with transaction.atomic():
                existing = VLAN.objects.filter(number=vlan_number).first()

                if existing is not None:
                    # If this is a manually created VLAN, do not overwrite.
                    if existing.source_kind == SourceKind.MANUAL and existing.source_device_id is None:
                        if existing.source_kind != SourceKind.MIKROTIK:
                            warnings.append(
                                f'VLAN {vlan_number}: already exists as manual entry, '
                                f'skipped import from {device.host}.'
                            )
                        continue

                    # If sync is disabled for this VLAN, skip.
                    if not existing.integration_sync_enabled:
                        continue

                    existing.source_upstream_disabled = bv.disabled
                    existing.source_bridge_name = bv.bridge
                    existing.last_synced_at = now
                    existing.sync_state = SyncState.LIVE
                    existing.source_job_id = job_id
                    existing.source_trace_id = trace_id
                    existing.save(update_fields=[
                        'source_upstream_disabled', 'source_bridge_name',
                        'last_synced_at', 'sync_state',
                        'source_job_id', 'source_trace_id',
                    ])
                    updated += 1
                else:
                    VLAN.objects.create(
                        number=vlan_number,
                        name=f'VLAN {vlan_number}',
                        source_kind=SourceKind.MIKROTIK,
                        source_device=device,
                        source_remote_id=remote_id,
                        source_job_id=job_id,
                        source_trace_id=trace_id,
                        last_synced_at=now,
                        sync_state=SyncState.LIVE,
                        integration_sync_enabled=True,
                        source_upstream_disabled=bv.disabled,
                        source_bridge_name=bv.bridge,
                    )
                    created += 1

    return {'vlans_created': created, 'vlans_updated': updated}


def _parse_vlan_ids(raw: str) -> list[int]:
    """Parse RouterOS vlan-ids like ``"10"``, ``"10,20"``, ``"10-15"``."""
    result = []
    for part in raw.split(','):
        part = part.strip()
        if not part:
            continue
        if '-' in part:
            try:
                start, end = part.split('-', 1)
                for n in range(int(start), int(end) + 1):
                    if 1 <= n <= 4094:
                        result.append(n)
            except (ValueError, TypeError):
                continue
        else:
            try:
                n = int(part)
                if 1 <= n <= 4094:
                    result.append(n)
            except (ValueError, TypeError):
                continue
    return result


# ---------------------------------------------------------------------------
# Subnet sync
# ---------------------------------------------------------------------------

def _sync_subnets(device, snapshot, now, trace_id, job_id, warnings) -> dict:
    """Create/update subnets from ``/ip/address`` rows."""
    created = 0
    updated = 0

    # Build a set of VLAN objects by interface for potential linking.
    # We cannot link subnets to VLANs automatically without heuristic
    # interface-name matching, so we leave vlan=None for now and let
    # the operator assign VLANs manually or in a future milestone.

    for ip_addr in snapshot.ip_addresses:
        if not ip_addr.address or '/' not in ip_addr.address:
            # /32 host addresses or malformed entries → skip subnet creation
            # but record a reason.
            if ip_addr.address and ip_addr.address.endswith('/32'):
                warnings.append(
                    f'Skipped /32 host address {ip_addr.address} on '
                    f'{ip_addr.interface} — not a subnet.'
                )
            continue

        try:
            net = ip_network(ip_addr.address, strict=False)
        except (ValueError, TypeError):
            warnings.append(
                f'Invalid CIDR "{ip_addr.address}" on {ip_addr.interface}, skipped.'
            )
            continue

        # Skip /32 host routes — they are not subnets.
        if net.prefixlen == 32:
            warnings.append(
                f'Skipped /32 host address {ip_addr.address} on '
                f'{ip_addr.interface} — not a subnet.'
            )
            continue

        cidr = str(net)
        remote_id = ip_addr.dot_id or f'ip-address:{ip_addr.interface}:{cidr}'

        with transaction.atomic():
            existing = Subnet.objects.filter(cidr=cidr).first()

            if existing is not None:
                # Manual subnet — do not overwrite.
                if existing.source_kind == SourceKind.MANUAL and existing.source_device_id is None:
                    warnings.append(
                        f'Subnet {cidr}: already exists as manual entry, '
                        f'skipped import from {device.host}.'
                    )
                    continue

                if not existing.integration_sync_enabled:
                    continue

                # Check if linked VLAN has sync disabled.
                if (existing.vlan_id and existing.vlan
                        and not existing.vlan.integration_sync_enabled):
                    continue

                existing.source_upstream_disabled = ip_addr.disabled
                existing.source_upstream_invalid = ip_addr.invalid
                existing.source_interface_name = ip_addr.actual_interface or ip_addr.interface
                existing.last_synced_at = now
                existing.sync_state = SyncState.LIVE
                existing.source_job_id = job_id
                existing.source_trace_id = trace_id
                existing.save(update_fields=[
                    'source_upstream_disabled', 'source_upstream_invalid',
                    'source_interface_name', 'last_synced_at', 'sync_state',
                    'source_job_id', 'source_trace_id',
                ])
                updated += 1
            else:
                Subnet.objects.create(
                    cidr=cidr,
                    scan_enabled=True,
                    discover_subnet=True,
                    source_kind=SourceKind.MIKROTIK,
                    source_device=device,
                    source_remote_id=remote_id,
                    source_job_id=job_id,
                    source_trace_id=trace_id,
                    last_synced_at=now,
                    sync_state=SyncState.LIVE,
                    integration_sync_enabled=True,
                    source_upstream_disabled=ip_addr.disabled,
                    source_upstream_invalid=ip_addr.invalid,
                    source_interface_name=ip_addr.actual_interface or ip_addr.interface,
                )
                created += 1

    return {'subnets_created': created, 'subnets_updated': updated}


# ---------------------------------------------------------------------------
# DHCP lease sync
# ---------------------------------------------------------------------------

def _sync_dhcp_leases(device, snapshot, now, trace_id, job_id, warnings) -> dict:
    """Create/update IP records from DHCP leases inside managed subnets."""
    created = 0
    updated = 0

    # Pre-load managed subnets for membership checks.
    managed_subnets = _load_managed_subnets(device)

    for lease in snapshot.dhcp_leases:
        addr_str = lease.active_address or lease.address
        if not addr_str:
            continue

        try:
            addr = ip_address(addr_str)
        except (ValueError, TypeError):
            continue

        subnet = _find_containing_subnet(addr, managed_subnets)
        if subnet is None:
            continue

        # Skip if subnet has sync disabled.
        if not subnet.integration_sync_enabled:
            continue

        # Determine lease kind from the ``dynamic`` field.
        if lease.dynamic:
            lease_kind = IPAddress.LeaseKind.DHCP_DYNAMIC
        else:
            lease_kind = IPAddress.LeaseKind.DHCP_STATIC

        mac = (lease.active_mac_address or lease.mac_address).lower()
        hostname = lease.active_host_name or lease.host_name
        remote_id = lease.dot_id or f'dhcp-lease:{addr_str}'

        with transaction.atomic():
            existing = IPAddress.objects.filter(
                subnet=subnet, address=addr_str,
            ).first()

            if existing is not None:
                existing.mac_address = mac or existing.mac_address
                existing.hostname = hostname or existing.hostname
                existing.lease_kind = lease_kind
                existing.lease_status = lease.status
                existing.lease_server_name = lease.server
                existing.source_observed_at = now
                existing.last_synced_at = now
                existing.sync_state = SyncState.LIVE
                existing.source_job_id = job_id
                existing.source_trace_id = trace_id
                existing.last_seen = now
                existing.save(update_fields=[
                    'mac_address', 'hostname',
                    'lease_kind', 'lease_status', 'lease_server_name',
                    'source_observed_at', 'last_synced_at', 'sync_state',
                    'source_job_id', 'source_trace_id', 'last_seen',
                ])
                updated += 1
            else:
                IPAddress.objects.create(
                    subnet=subnet,
                    address=addr_str,
                    mac_address=mac,
                    hostname=hostname,
                    source_kind=SourceKind.MIKROTIK,
                    source_device=device,
                    source_remote_id=remote_id,
                    source_job_id=job_id,
                    source_trace_id=trace_id,
                    source_observed_at=now,
                    last_synced_at=now,
                    sync_state=SyncState.LIVE,
                    lease_kind=lease_kind,
                    lease_status=lease.status,
                    lease_server_name=lease.server,
                    last_seen=now,
                )
                created += 1

    return {'ips_created': created, 'ips_updated': updated}


# ---------------------------------------------------------------------------
# ARP enrichment
# ---------------------------------------------------------------------------

def _sync_arp(device, snapshot, now, trace_id, job_id, warnings) -> dict:
    """Enrich existing IPs or create observed records from ARP inside managed subnets.

    ARP is **enrichment only** — it never creates subnets and only
    touches IPs within already-known managed subnets.  If duplicate ARP
    entries exist across interfaces, the first match in subnet context
    wins.
    """
    created = 0
    updated = 0

    managed_subnets = _load_managed_subnets(device)
    # Track which IPs we already touched to handle duplicates.
    seen_addresses: set[str] = set()

    for arp in snapshot.arp_entries:
        if not arp.address or not arp.complete:
            continue

        if arp.address in seen_addresses:
            continue

        try:
            addr = ip_address(arp.address)
        except (ValueError, TypeError):
            continue

        subnet = _find_containing_subnet(addr, managed_subnets)
        if subnet is None:
            # ARP outside managed subnets → ignore.
            continue

        if not subnet.integration_sync_enabled:
            continue

        seen_addresses.add(arp.address)
        mac = arp.mac_address.lower() if arp.mac_address else ''

        with transaction.atomic():
            existing = IPAddress.objects.filter(
                subnet=subnet, address=arp.address,
            ).first()

            if existing is not None:
                # Only enrich MAC if we have one and the existing is empty.
                changed = False
                if mac and not existing.mac_address:
                    existing.mac_address = mac
                    changed = True
                if changed:
                    existing.last_synced_at = now
                    existing.source_job_id = job_id
                    existing.source_trace_id = trace_id
                    existing.save(update_fields=[
                        'mac_address', 'last_synced_at',
                        'source_job_id', 'source_trace_id',
                    ])
                    updated += 1
            else:
                # Create an observed-only IP record — not a static truth.
                IPAddress.objects.create(
                    subnet=subnet,
                    address=arp.address,
                    mac_address=mac,
                    source_kind=SourceKind.MIKROTIK,
                    source_device=device,
                    source_remote_id=f'arp:{arp.interface}:{arp.address}',
                    source_job_id=job_id,
                    source_trace_id=trace_id,
                    source_observed_at=now,
                    last_synced_at=now,
                    sync_state=SyncState.LIVE,
                    # Explicitly not DHCP — we only know it from ARP.
                    lease_kind=IPAddress.LeaseKind.NONE,
                    last_seen=now,
                )
                created += 1

    return {'ips_created': created, 'ips_updated': updated}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _load_managed_subnets(device: MikroTikDevice) -> list[Subnet]:
    """Return subnets that this device is allowed to modify.

    Includes both MikroTik-imported subnets owned by this device
    and any manually created subnets (which may receive DHCP/ARP data).
    """
    return list(
        Subnet.objects.filter(
            models.Q(source_device=device) | models.Q(source_kind=SourceKind.MANUAL),
        ).select_related('vlan')
    )


def _find_containing_subnet(addr, subnets: list[Subnet]):
    """Find the most-specific subnet that contains *addr*."""
    best = None
    best_prefix = -1
    for subnet in subnets:
        try:
            net = ip_network(subnet.cidr, strict=False)
        except (ValueError, TypeError):
            continue
        if addr in net and net.prefixlen > best_prefix:
            best = subnet
            best_prefix = net.prefixlen
    return best


def _build_observed_summary(snapshot: MikroTikSnapshotResultV1) -> dict:
    """Build a compact diagnostic summary of neighbors and ARP."""
    # De-duplicate neighbors by address.
    unique_neighbors = {}
    for n in snapshot.neighbors:
        key = n.address or n.mac_address
        if key and key not in unique_neighbors:
            unique_neighbors[key] = {
                'address': n.address,
                'identity': n.identity,
                'platform': n.platform,
                'interface': n.interface_name or n.interface,
            }

    return {
        'neighbor_count': len(unique_neighbors),
        'neighbors': list(unique_neighbors.values())[:50],
        'arp_entry_count': len(snapshot.arp_entries),
    }
