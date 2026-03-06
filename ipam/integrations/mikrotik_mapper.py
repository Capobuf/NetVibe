"""Map raw RouterOS REST responses into contract DTOs.

Each ``map_*`` function takes the raw JSON returned by
:class:`~ipam.integrations.mikrotik_client.MikroTikClient` and
returns the corresponding Pydantic DTO from ``ipam.contracts``.
"""

from __future__ import annotations

from ipam.contracts import (
    MikroTikARPEntry,
    MikroTikBridgeVLAN,
    MikroTikDHCPLease,
    MikroTikDHCPServer,
    MikroTikIdentity,
    MikroTikIPAddress,
    MikroTikNeighbor,
    MikroTikSnapshotResultV1,
    MikroTikSystemResource,
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _bool(value) -> bool:
    """RouterOS REST returns booleans as ``"true"``/``"false"`` strings
    or native Python bools depending on the endpoint and firmware.
    Normalise both."""
    if isinstance(value, bool):
        return value
    return str(value).lower() == 'true'


# ---------------------------------------------------------------------------
# Individual mappers
# ---------------------------------------------------------------------------

def map_identity(raw: dict) -> MikroTikIdentity:
    return MikroTikIdentity(name=raw.get('name', ''))


def map_system_resource(raw: dict) -> MikroTikSystemResource:
    return MikroTikSystemResource(
        board_name=raw.get('board-name', ''),
        platform=raw.get('platform', ''),
        version=raw.get('version', ''),
    )


def map_bridge_vlans(raw_list: list[dict]) -> list[MikroTikBridgeVLAN]:
    result: list[MikroTikBridgeVLAN] = []
    for row in raw_list:
        result.append(MikroTikBridgeVLAN(
            vlan_ids=str(row.get('vlan-ids', '')),
            bridge=row.get('bridge', ''),
            tagged=row.get('tagged', ''),
            untagged=row.get('untagged', ''),
            dynamic=_bool(row.get('dynamic', False)),
            disabled=_bool(row.get('disabled', False)),
            comment=row.get('comment', ''),
        ))
    return result


def map_ip_addresses(raw_list: list[dict]) -> list[MikroTikIPAddress]:
    result: list[MikroTikIPAddress] = []
    for row in raw_list:
        result.append(MikroTikIPAddress(
            address=row.get('address', ''),
            network=row.get('network', ''),
            interface=row.get('interface', ''),
            actual_interface=row.get('actual-interface', ''),
            dynamic=_bool(row.get('dynamic', False)),
            disabled=_bool(row.get('disabled', False)),
            invalid=_bool(row.get('invalid', False)),
            dot_id=row.get('.id', ''),
        ))
    return result


def map_dhcp_servers(raw_list: list[dict]) -> list[MikroTikDHCPServer]:
    return [
        MikroTikDHCPServer(
            name=row.get('name', ''),
            interface=row.get('interface', ''),
            disabled=_bool(row.get('disabled', False)),
        )
        for row in raw_list
    ]


def map_dhcp_leases(raw_list: list[dict]) -> list[MikroTikDHCPLease]:
    return [
        MikroTikDHCPLease(
            address=row.get('address', ''),
            mac_address=row.get('mac-address', ''),
            host_name=row.get('host-name', ''),
            server=row.get('server', ''),
            status=row.get('status', ''),
            dynamic=_bool(row.get('dynamic', False)),
            disabled=_bool(row.get('disabled', False)),
            comment=row.get('comment', ''),
            active_address=row.get('active-address', ''),
            active_mac_address=row.get('active-mac-address', ''),
            active_host_name=row.get('active-host-name', ''),
            dot_id=row.get('.id', ''),
        )
        for row in raw_list
    ]


def map_arp_entries(raw_list: list[dict]) -> list[MikroTikARPEntry]:
    return [
        MikroTikARPEntry(
            address=row.get('address', ''),
            mac_address=row.get('mac-address', ''),
            interface=row.get('interface', ''),
            dynamic=_bool(row.get('dynamic', False)),
            complete=_bool(row.get('complete', True)),
        )
        for row in raw_list
    ]


def map_neighbors(raw_list: list[dict]) -> list[MikroTikNeighbor]:
    return [
        MikroTikNeighbor(
            address=row.get('address', ''),
            identity=row.get('identity', ''),
            platform=row.get('platform', ''),
            version=row.get('version', ''),
            interface=row.get('interface', ''),
            interface_name=row.get('interface-name', ''),
            mac_address=row.get('mac-address', ''),
        )
        for row in raw_list
    ]


# ---------------------------------------------------------------------------
# Full snapshot builder
# ---------------------------------------------------------------------------

def build_snapshot(
    device_host: str,
    *,
    identity_raw: dict,
    resource_raw: dict,
    bridge_vlans_raw: list[dict],
    ip_addresses_raw: list[dict],
    dhcp_servers_raw: list[dict],
    dhcp_leases_raw: list[dict],
    arp_raw: list[dict],
    neighbors_raw: list[dict],
) -> MikroTikSnapshotResultV1:
    """Build a full snapshot DTO from raw RouterOS REST responses."""
    return MikroTikSnapshotResultV1(
        device_host=device_host,
        identity=map_identity(identity_raw),
        system_resource=map_system_resource(resource_raw),
        bridge_vlans=map_bridge_vlans(bridge_vlans_raw),
        ip_addresses=map_ip_addresses(ip_addresses_raw),
        dhcp_servers=map_dhcp_servers(dhcp_servers_raw),
        dhcp_leases=map_dhcp_leases(dhcp_leases_raw),
        arp_entries=map_arp_entries(arp_raw),
        neighbors=map_neighbors(neighbors_raw),
    )
