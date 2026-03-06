"""Internal contract DTOs for worker ↔ central communication.

These are stable, versioned Pydantic models that define the data shape
for ICMP scan results, MikroTik snapshot results, and MikroTik sync
apply results.  No HTTP API implementation is needed in this milestone;
these contracts exist so the internal boundary is explicit and tested.

Message types follow a dotted versioned convention:
  - ``icmp.scan.result.v1``
  - ``mikrotik.snapshot.result.v1``
  - ``mikrotik.sync.apply.result.v1``
"""

from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import Any
from uuid import uuid4

from pydantic import BaseModel, Field


# ---------------------------------------------------------------------------
# Envelope
# ---------------------------------------------------------------------------

class MessageType(str, Enum):
    ICMP_SCAN_RESULT_V1 = 'icmp.scan.result.v1'
    MIKROTIK_SNAPSHOT_RESULT_V1 = 'mikrotik.snapshot.result.v1'
    MIKROTIK_SYNC_APPLY_RESULT_V1 = 'mikrotik.sync.apply.result.v1'


class Envelope(BaseModel):
    """Common wrapper shipped with every internal message."""

    contract_version: str = '1'
    message_id: str = Field(default_factory=lambda: str(uuid4()))
    message_type: MessageType
    occurred_at: datetime
    trace_id: str = ''
    job_id: str = ''
    producer: str = ''
    subject: str = ''
    data: dict[str, Any] = Field(default_factory=dict)


# ---------------------------------------------------------------------------
# icmp.scan.result.v1
# ---------------------------------------------------------------------------

class ICMPHostResult(BaseModel):
    address: str
    alive: bool
    mac_address: str = ''
    hostname: str = ''


class ICMPScanResultV1(BaseModel):
    """Payload for ``icmp.scan.result.v1``."""

    subnet_cidr: str
    hosts_total: int = 0
    hosts_pinged: int = 0
    hosts_alive: int = 0
    ip_records_created: int = 0
    ip_records_updated: int = 0
    results: list[ICMPHostResult] = Field(default_factory=list)


# ---------------------------------------------------------------------------
# mikrotik.snapshot.result.v1
# ---------------------------------------------------------------------------

class MikroTikIdentity(BaseModel):
    name: str = ''


class MikroTikSystemResource(BaseModel):
    board_name: str = ''
    platform: str = ''
    version: str = ''


class MikroTikBridgeVLAN(BaseModel):
    """One row from ``/interface/bridge/vlan``."""
    vlan_ids: str = ''  # comma-separated or single
    bridge: str = ''
    tagged: str = ''
    untagged: str = ''
    dynamic: bool = False
    disabled: bool = False
    comment: str = ''


class MikroTikIPAddress(BaseModel):
    """One row from ``/ip/address``."""
    address: str = ''
    network: str = ''
    interface: str = ''
    actual_interface: str = ''
    dynamic: bool = False
    disabled: bool = False
    invalid: bool = False
    dot_id: str = ''  # RouterOS ``.id`` field


class MikroTikDHCPServer(BaseModel):
    name: str = ''
    interface: str = ''
    disabled: bool = False


class MikroTikDHCPLease(BaseModel):
    address: str = ''
    mac_address: str = ''
    host_name: str = ''
    server: str = ''
    status: str = ''
    dynamic: bool = False
    disabled: bool = False
    comment: str = ''
    active_address: str = ''
    active_mac_address: str = ''
    active_host_name: str = ''
    dot_id: str = ''


class MikroTikARPEntry(BaseModel):
    address: str = ''
    mac_address: str = ''
    interface: str = ''
    dynamic: bool = False
    complete: bool = True


class MikroTikNeighbor(BaseModel):
    address: str = ''
    identity: str = ''
    platform: str = ''
    version: str = ''
    interface: str = ''
    interface_name: str = ''
    mac_address: str = ''


class MikroTikSnapshotResultV1(BaseModel):
    """Payload for ``mikrotik.snapshot.result.v1``.

    Contains the normalized facts collected from one RouterOS device
    during a single snapshot collection.
    """

    device_host: str = ''
    identity: MikroTikIdentity = Field(default_factory=MikroTikIdentity)
    system_resource: MikroTikSystemResource = Field(default_factory=MikroTikSystemResource)
    bridge_vlans: list[MikroTikBridgeVLAN] = Field(default_factory=list)
    ip_addresses: list[MikroTikIPAddress] = Field(default_factory=list)
    dhcp_servers: list[MikroTikDHCPServer] = Field(default_factory=list)
    dhcp_leases: list[MikroTikDHCPLease] = Field(default_factory=list)
    arp_entries: list[MikroTikARPEntry] = Field(default_factory=list)
    neighbors: list[MikroTikNeighbor] = Field(default_factory=list)


# ---------------------------------------------------------------------------
# mikrotik.sync.apply.result.v1
# ---------------------------------------------------------------------------

class SyncApplyCounters(BaseModel):
    vlans_created: int = 0
    vlans_updated: int = 0
    subnets_created: int = 0
    subnets_updated: int = 0
    ips_created: int = 0
    ips_updated: int = 0


class MikroTikSyncApplyResultV1(BaseModel):
    """Payload for ``mikrotik.sync.apply.result.v1``."""

    device_host: str = ''
    counters: SyncApplyCounters = Field(default_factory=SyncApplyCounters)
    warnings: list[str] = Field(default_factory=list)
    skipped_reasons: list[str] = Field(default_factory=list)
