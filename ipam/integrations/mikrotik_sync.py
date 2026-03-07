"""MikroTik -> IPAM synchronization engine."""

from __future__ import annotations

import logging
import re
from ipaddress import ip_address, ip_network

from django.db import models, transaction
from django.utils import timezone

from ipam.contracts import MikroTikSnapshotResultV1
from ipam.crypto import decrypt_password
from ipam.integrations.mikrotik_client import (
    DEFAULT_CONNECT_TIMEOUT,
    DEFAULT_READ_TIMEOUT,
    DISCOVERY_CONNECT_TIMEOUT,
    DISCOVERY_READ_TIMEOUT,
    MikroTikClient,
    MikroTikClientError,
)
from ipam.integrations.mikrotik_mapper import build_snapshot
from ipam.models import (
    VLAN,
    IPAddress,
    IPAddressObservation,
    IntegrationVendor,
    MikroTikDevice,
    MikroTikIntegrationSettings,
    ReviewItem,
    MikroTikSyncRun,
    SourceKind,
    Subnet,
    SyncState,
    upsert_review_item,
)

logger = logging.getLogger(__name__)


def sanitize_detail_message(value: str, *, max_len: int = 500) -> str:
    """Return a concise, storage-safe detail message."""
    sanitized = ' '.join((value or '').split())
    return sanitized[:max_len]


def load_global_credentials() -> tuple[str, str]:
    """Load and decrypt global MikroTik credentials."""
    settings_obj = MikroTikIntegrationSettings.objects.order_by('pk').first()
    if settings_obj is None:
        raise MikroTikClientError('MikroTik settings are not configured.')

    if not settings_obj.username or not settings_obj.password_encrypted:
        raise MikroTikClientError('Global MikroTik credentials are incomplete.')

    password = decrypt_password(settings_obj.password_encrypted)
    return settings_obj.username, password


def is_routeros_v7(version: str) -> bool:
    match = re.search(r'(\d+)', version or '')
    if match is None:
        return False
    return int(match.group(1)) == 7


# ---------------------------------------------------------------------------
# Client factories
# ---------------------------------------------------------------------------

def _make_client_for_endpoint(
    *,
    host: str,
    scheme: str,
    port: int,
    verify_tls: bool,
    username: str,
    password: str,
    connect_timeout: float,
    read_timeout: float,
) -> MikroTikClient:
    return MikroTikClient(
        base_url=f'{scheme}://{host}:{port}',
        username=username,
        password=password,
        verify_tls=verify_tls,
        connect_timeout=connect_timeout,
        read_timeout=read_timeout,
    )


def _make_client_for_device(device: MikroTikDevice) -> MikroTikClient:
    username, password = load_global_credentials()
    return _make_client_for_endpoint(
        host=device.host,
        scheme=device.scheme,
        port=device.port,
        verify_tls=device.verify_tls,
        username=username,
        password=password,
        connect_timeout=DEFAULT_CONNECT_TIMEOUT,
        read_timeout=DEFAULT_READ_TIMEOUT,
    )


def collect_target_metadata(
    *,
    host: str,
    scheme: str,
    port: int,
    verify_tls: bool,
    username: str,
    password: str,
    connect_timeout: float = DISCOVERY_CONNECT_TIMEOUT,
    read_timeout: float = DISCOVERY_READ_TIMEOUT,
) -> tuple[dict, dict]:
    """Fetch only identity and system resource for discovery."""
    client = _make_client_for_endpoint(
        host=host,
        scheme=scheme,
        port=port,
        verify_tls=verify_tls,
        username=username,
        password=password,
        connect_timeout=connect_timeout,
        read_timeout=read_timeout,
    )
    return client.get_identity(), client.get_system_resource()


# ---------------------------------------------------------------------------
# Snapshot collection
# ---------------------------------------------------------------------------

def _collect_snapshot(client: MikroTikClient, device: MikroTikDevice) -> MikroTikSnapshotResultV1:
    identity_raw = client.get_identity()
    resource_raw = client.get_system_resource()

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
# Full sync
# ---------------------------------------------------------------------------

def run_device_sync(device: MikroTikDevice, sync_run: MikroTikSyncRun) -> MikroTikSyncRun:
    """Full sync: collect facts and upsert IPAM objects."""
    sync_run.status = MikroTikSyncRun.Status.RUNNING
    sync_run.started_at = timezone.now()
    sync_run.error_message = ''
    sync_run.save(update_fields=['status', 'started_at', 'error_message'])

    try:
        client = _make_client_for_device(device)
        snapshot = _collect_snapshot(client, device)

        device.last_seen_identity = snapshot.identity.name
        device.last_seen_version = snapshot.system_resource.version
        device.last_seen_board = snapshot.system_resource.board_name
        device.last_seen_platform = snapshot.system_resource.platform
        if not device.name:
            device.name = snapshot.identity.name or device.host

        warnings: list[str] = []
        counters = _apply_snapshot(device, snapshot, sync_run, warnings)

        now = timezone.now()
        device.last_error = ''
        device.last_successful_sync_at = now
        device.save(update_fields=[
            'name',
            'last_seen_identity',
            'last_seen_version',
            'last_seen_board',
            'last_seen_platform',
            'last_error',
            'last_successful_sync_at',
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
        sync_run.finished_at = now
        sync_run.error_message = ''
        sync_run.save()

    except MikroTikClientError as exc:
        _fail_sync_run(
            device,
            sync_run,
            sanitize_detail_message(str(exc)),
            error_category=exc.__class__.__name__,
        )
    except Exception as exc:
        _fail_sync_run(
            device,
            sync_run,
            sanitize_detail_message(f'{exc.__class__.__name__}: {exc}'),
            error_category=exc.__class__.__name__,
        )
        raise

    return sync_run


def _fail_sync_run(
    device: MikroTikDevice,
    sync_run: MikroTikSyncRun,
    error: str,
    *,
    error_category: str,
) -> None:
    now = timezone.now()
    device.last_error = error
    device.save(update_fields=['last_error'])

    sync_run.status = MikroTikSyncRun.Status.FAILED
    sync_run.error_message = error
    sync_run.finished_at = now
    sync_run.save(update_fields=['status', 'error_message', 'finished_at'])
    _upsert_sync_failure_review(device, sync_run, error, error_category)


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

    if device.import_vlans:
        counters.update(_sync_vlans(device, snapshot, now, trace_id, job_id, warnings))

    if device.import_subnets and device.is_authoritative_for_l3:
        counters.update(_sync_subnets(device, snapshot, now, trace_id, job_id, warnings))

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

    for bridge_vlan in snapshot.bridge_vlans:
        if bridge_vlan.dynamic and device.ignore_dynamic_bridge_vlans:
            continue

        for vlan_number in _parse_vlan_ids(bridge_vlan.vlan_ids):
            remote_id = f'bridge-vlan:{bridge_vlan.bridge}:{vlan_number}'

            with transaction.atomic():
                existing = VLAN.objects.filter(number=vlan_number).first()

                if existing is not None:
                    if (
                        existing.source_kind == SourceKind.MANUAL
                        and existing.source_device_id is None
                    ):
                        warnings.append(
                            f'VLAN {vlan_number}: already exists as manual entry, '
                            f'skipped import from {device.host}.'
                        )
                        continue

                    if not existing.integration_sync_enabled:
                        continue

                    existing.source_upstream_disabled = bridge_vlan.disabled
                    existing.source_bridge_name = bridge_vlan.bridge
                    existing.last_synced_at = now
                    existing.sync_state = SyncState.LIVE
                    existing.source_job_id = job_id
                    existing.source_trace_id = trace_id
                    existing.save(update_fields=[
                        'source_upstream_disabled',
                        'source_bridge_name',
                        'last_synced_at',
                        'sync_state',
                        'source_job_id',
                        'source_trace_id',
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
                        source_upstream_disabled=bridge_vlan.disabled,
                        source_bridge_name=bridge_vlan.bridge,
                    )
                    created += 1

    return {'vlans_created': created, 'vlans_updated': updated}


def _parse_vlan_ids(raw: str) -> list[int]:
    result = []
    for part in raw.split(','):
        part = part.strip()
        if not part:
            continue
        if '-' in part:
            try:
                start, end = part.split('-', 1)
                for value in range(int(start), int(end) + 1):
                    if 1 <= value <= 4094:
                        result.append(value)
            except (ValueError, TypeError):
                continue
        else:
            try:
                value = int(part)
                if 1 <= value <= 4094:
                    result.append(value)
            except (ValueError, TypeError):
                continue
    return result


# ---------------------------------------------------------------------------
# Subnet sync
# ---------------------------------------------------------------------------

def _sync_subnets(device, snapshot, now, trace_id, job_id, warnings) -> dict:
    created = 0
    updated = 0

    for ip_addr in snapshot.ip_addresses:
        if not ip_addr.address or '/' not in ip_addr.address:
            if ip_addr.address and ip_addr.address.endswith('/32'):
                warnings.append(
                    f'Skipped /32 host address {ip_addr.address} on '
                    f'{ip_addr.interface} - not a subnet.'
                )
            continue

        try:
            net = ip_network(ip_addr.address, strict=False)
        except (ValueError, TypeError):
            warnings.append(
                f'Invalid CIDR "{ip_addr.address}" on {ip_addr.interface}, skipped.'
            )
            continue

        if net.prefixlen == 32:
            warnings.append(
                f'Skipped /32 host address {ip_addr.address} on '
                f'{ip_addr.interface} - not a subnet.'
            )
            continue

        cidr = str(net)
        remote_id = ip_addr.dot_id or f'ip-address:{ip_addr.interface}:{cidr}'

        with transaction.atomic():
            existing = Subnet.objects.filter(cidr=cidr).first()

            if existing is not None:
                if (
                    existing.source_kind == SourceKind.MANUAL
                    and existing.source_device_id is None
                ):
                    warnings.append(
                        f'Subnet {cidr}: already exists as manual entry, '
                        f'skipped import from {device.host}.'
                    )
                    continue

                if not existing.integration_sync_enabled:
                    continue

                if existing.vlan_id and existing.vlan and not existing.vlan.integration_sync_enabled:
                    continue

                existing.source_upstream_disabled = ip_addr.disabled
                existing.source_upstream_invalid = ip_addr.invalid
                existing.source_interface_name = ip_addr.actual_interface or ip_addr.interface
                existing.last_synced_at = now
                existing.sync_state = SyncState.LIVE
                existing.source_job_id = job_id
                existing.source_trace_id = trace_id
                existing.save(update_fields=[
                    'source_upstream_disabled',
                    'source_upstream_invalid',
                    'source_interface_name',
                    'last_synced_at',
                    'sync_state',
                    'source_job_id',
                    'source_trace_id',
                ])
                updated += 1
            else:
                Subnet.objects.create(
                    cidr=cidr,
                    scan_enabled=False,
                    discover_subnet=False,
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
    created = 0
    updated = 0

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

        if not subnet.integration_sync_enabled:
            continue

        lease_kind = (
            IPAddress.LeaseKind.DHCP_DYNAMIC
            if lease.dynamic
            else IPAddress.LeaseKind.DHCP_STATIC
        )

        mac = (lease.active_mac_address or lease.mac_address).lower()
        hostname = lease.active_host_name or lease.host_name
        remote_id = lease.dot_id or f'dhcp-lease:{addr_str}'

        _upsert_ip_observation(
            device=device,
            subnet=subnet,
            address=addr_str,
            now=now,
            observed_hostname=hostname,
            observed_mac_address=mac,
            lease_kind=lease_kind,
            lease_status=lease.status,
            lease_server_name=lease.server,
            observed_via_dhcp=True,
            payload_json={
                'source': 'dhcp',
                'status': lease.status,
                'server': lease.server,
            },
        )

        with transaction.atomic():
            existing = IPAddress.objects.filter(
                subnet=subnet,
                address=addr_str,
            ).first()

            if existing is not None:
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
                    'lease_kind',
                    'lease_status',
                    'lease_server_name',
                    'source_observed_at',
                    'last_synced_at',
                    'sync_state',
                    'source_job_id',
                    'source_trace_id',
                    'last_seen',
                ])
                _maybe_create_hostname_conflict_review(
                    device=device,
                    subnet=subnet,
                    canonical_ip=existing,
                    observed_hostname=hostname,
                )
                _maybe_create_mac_conflict_review(
                    device=device,
                    subnet=subnet,
                    canonical_ip=existing,
                    observed_mac_address=mac,
                )
                updated += 1
            else:
                created_ip = IPAddress.objects.create(
                    subnet=subnet,
                    address=addr_str,
                    mac_address='',
                    hostname='',
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
                _upsert_new_ip_observed_review(
                    device=device,
                    subnet=subnet,
                    canonical_ip=created_ip,
                )
                created += 1

    return {'ips_created': created, 'ips_updated': updated}


# ---------------------------------------------------------------------------
# ARP enrichment
# ---------------------------------------------------------------------------

def _sync_arp(device, snapshot, now, trace_id, job_id, warnings) -> dict:
    created = 0
    updated = 0

    managed_subnets = _load_managed_subnets(device)
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
            continue

        if not subnet.integration_sync_enabled:
            continue

        seen_addresses.add(arp.address)
        mac = arp.mac_address.lower() if arp.mac_address else ''
        remote_id = f'arp:{arp.interface}:{arp.address}'
        _upsert_ip_observation(
            device=device,
            subnet=subnet,
            address=arp.address,
            now=now,
            observed_mac_address=mac,
            observed_via_arp=True,
            payload_json={
                'source': 'arp',
                'interface': arp.interface,
            },
        )

        with transaction.atomic():
            existing = IPAddress.objects.filter(
                subnet=subnet,
                address=arp.address,
            ).first()

            if existing is not None:
                existing.source_observed_at = now
                existing.last_synced_at = now
                existing.sync_state = SyncState.LIVE
                existing.source_job_id = job_id
                existing.source_trace_id = trace_id
                existing.last_seen = now
                existing.save(update_fields=[
                    'source_observed_at',
                    'last_synced_at',
                    'sync_state',
                    'source_job_id',
                    'source_trace_id',
                    'last_seen',
                ])
                _maybe_create_mac_conflict_review(
                    device=device,
                    subnet=subnet,
                    canonical_ip=existing,
                    observed_mac_address=mac,
                )
                updated += 1
            else:
                created_ip = IPAddress.objects.create(
                    subnet=subnet,
                    address=arp.address,
                    mac_address='',
                    source_kind=SourceKind.MIKROTIK,
                    source_device=device,
                    source_remote_id=remote_id,
                    source_job_id=job_id,
                    source_trace_id=trace_id,
                    source_observed_at=now,
                    last_synced_at=now,
                    sync_state=SyncState.LIVE,
                    lease_kind=IPAddress.LeaseKind.NONE,
                    last_seen=now,
                )
                _upsert_new_ip_observed_review(
                    device=device,
                    subnet=subnet,
                    canonical_ip=created_ip,
                )
                created += 1

    return {'ips_created': created, 'ips_updated': updated}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _load_managed_subnets(device: MikroTikDevice) -> list[Subnet]:
    return list(
        Subnet.objects.filter(
            models.Q(source_device=device) | models.Q(source_kind=SourceKind.MANUAL),
        ).select_related('vlan')
    )


def _find_containing_subnet(addr, subnets: list[Subnet]):
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
    unique_neighbors = {}
    for neighbor in snapshot.neighbors:
        key = neighbor.address or neighbor.mac_address
        if key and key not in unique_neighbors:
            unique_neighbors[key] = {
                'address': neighbor.address,
                'identity': neighbor.identity,
                'platform': neighbor.platform,
                'interface': neighbor.interface_name or neighbor.interface,
            }

    return {
        'neighbor_count': len(unique_neighbors),
        'neighbors': list(unique_neighbors.values())[:50],
        'arp_entry_count': len(snapshot.arp_entries),
    }


def _upsert_ip_observation(
    *,
    device: MikroTikDevice,
    subnet: Subnet,
    address: str,
    now,
    observed_hostname: str = '',
    observed_mac_address: str = '',
    lease_kind: str | None = None,
    lease_status: str = '',
    lease_server_name: str = '',
    observed_via_dhcp: bool = False,
    observed_via_arp: bool = False,
    payload_json: dict | None = None,
) -> None:
    observation, created = IPAddressObservation.objects.get_or_create(
        integration_vendor=IntegrationVendor.MIKROTIK,
        mikrotik_device=device,
        address=address,
        defaults={
            'subnet': subnet,
            'observed_hostname': observed_hostname or '',
            'observed_mac_address': observed_mac_address or '',
            'lease_kind': lease_kind or IPAddress.LeaseKind.NONE,
            'lease_status': lease_status or '',
            'lease_server_name': lease_server_name or '',
            'observed_via_dhcp': observed_via_dhcp,
            'observed_via_arp': observed_via_arp,
            'first_observed_at': now,
            'last_observed_at': now,
            'payload_json': payload_json or {},
        },
    )
    if created:
        return

    observation.subnet = subnet
    if observed_hostname:
        observation.observed_hostname = observed_hostname
    if observed_mac_address:
        observation.observed_mac_address = observed_mac_address
    if lease_kind is not None:
        observation.lease_kind = lease_kind
    if lease_status:
        observation.lease_status = lease_status
    if lease_server_name:
        observation.lease_server_name = lease_server_name
    observation.observed_via_dhcp = observation.observed_via_dhcp or observed_via_dhcp
    observation.observed_via_arp = observation.observed_via_arp or observed_via_arp
    observation.last_observed_at = now
    if payload_json:
        observation.payload_json = payload_json
    observation.save(update_fields=[
        'subnet',
        'observed_hostname',
        'observed_mac_address',
        'lease_kind',
        'lease_status',
        'lease_server_name',
        'observed_via_dhcp',
        'observed_via_arp',
        'last_observed_at',
        'payload_json',
    ])


def _upsert_new_ip_observed_review(
    *,
    device: MikroTikDevice,
    subnet: Subnet,
    canonical_ip: IPAddress,
) -> None:
    upsert_review_item(
        kind=ReviewItem.Kind.NEW_IP_OBSERVED,
        severity=ReviewItem.Severity.INFO,
        title=f'New IP observed: {canonical_ip.address}',
        message=(
            'A new IP was discovered by synchronization. '
            'Review and enrich canonical hostname and MAC if needed.'
        ),
        dedupe_key=f'new_ip_observed:{device.pk}:{canonical_ip.address}',
        subnet=subnet,
        ip_address=canonical_ip,
        mikrotik_device=device,
        payload_json={
            'address': canonical_ip.address,
            'subnet': subnet.cidr,
            'integration_vendor': IntegrationVendor.MIKROTIK,
        },
    )


def _maybe_create_hostname_conflict_review(
    *,
    device: MikroTikDevice,
    subnet: Subnet,
    canonical_ip: IPAddress,
    observed_hostname: str,
) -> None:
    if not observed_hostname or not canonical_ip.hostname:
        return
    if canonical_ip.hostname == observed_hostname:
        return

    upsert_review_item(
        kind=ReviewItem.Kind.HOSTNAME_CONFLICT,
        severity=ReviewItem.Severity.WARNING,
        title=f'Hostname conflict on {canonical_ip.address}',
        message=(
            f'Canonical hostname "{canonical_ip.hostname}" differs from observed '
            f'hostname "{observed_hostname}".'
        ),
        dedupe_key=f'hostname_conflict:{device.pk}:{canonical_ip.address}',
        subnet=subnet,
        ip_address=canonical_ip,
        mikrotik_device=device,
        payload_json={
            'canonical_hostname': canonical_ip.hostname,
            'observed_hostname': observed_hostname,
        },
    )


def _maybe_create_mac_conflict_review(
    *,
    device: MikroTikDevice,
    subnet: Subnet,
    canonical_ip: IPAddress,
    observed_mac_address: str,
) -> None:
    canonical_mac = (canonical_ip.mac_address or '').lower()
    observed_mac = (observed_mac_address or '').lower()
    if not canonical_mac or not observed_mac:
        return
    if canonical_mac == observed_mac:
        return

    upsert_review_item(
        kind=ReviewItem.Kind.MAC_CONFLICT,
        severity=ReviewItem.Severity.WARNING,
        title=f'MAC conflict on {canonical_ip.address}',
        message=(
            f'Canonical MAC "{canonical_ip.mac_address}" differs from observed '
            f'MAC "{observed_mac}".'
        ),
        dedupe_key=f'mac_conflict:{device.pk}:{canonical_ip.address}',
        subnet=subnet,
        ip_address=canonical_ip,
        mikrotik_device=device,
        payload_json={
            'canonical_mac': canonical_ip.mac_address,
            'observed_mac': observed_mac,
        },
    )


def _upsert_sync_failure_review(
    device: MikroTikDevice,
    sync_run: MikroTikSyncRun,
    error: str,
    error_category: str,
) -> None:
    normalized_category = (error_category or 'unknown').strip().lower() or 'unknown'
    upsert_review_item(
        kind=ReviewItem.Kind.SYNC_FAILURE,
        severity=ReviewItem.Severity.CRITICAL,
        title=f'Sync failure on {device.host}',
        message=error,
        dedupe_key=f'sync_failure:{device.pk}:{normalized_category}',
        mikrotik_device=device,
        payload_json={
            'sync_run_id': sync_run.pk,
            'error_category': normalized_category,
            'error_message': error,
        },
    )
