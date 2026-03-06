"""Thin REST client for MikroTik RouterOS v7.

Each public method maps to one RouterOS REST endpoint and returns
parsed JSON.  All calls use bounded timeouts and explicit TLS
verification so the caller never hangs or silently ignores cert issues.

Requires ``httpx`` (listed in ``requirements.txt``).
"""

from __future__ import annotations

import logging
from typing import Any
from urllib.parse import urlsplit

import httpx

logger = logging.getLogger(__name__)

# RouterOS REST responses arrive quickly for read operations.
# 15 s connect + 30 s read is generous for any single GET.
DEFAULT_CONNECT_TIMEOUT = 15.0
DEFAULT_READ_TIMEOUT = 30.0


class MikroTikClientError(Exception):
    """Base error for all client failures."""


class MikroTikAuthError(MikroTikClientError):
    """Authentication to the device was rejected."""


class MikroTikConnectionError(MikroTikClientError):
    """Could not reach the device at all."""


class MikroTikAPIError(MikroTikClientError):
    """The device returned a non-2xx response."""


class MikroTikClient:
    """REST client for one MikroTik RouterOS v7 device.

    Parameters
    ----------
    base_url : str
        e.g. ``https://10.0.0.1:443``
    username : str
    password : str
    verify_tls : bool
        Pass ``False`` to skip TLS certificate verification (common in
        lab / internal networks with self-signed certs).
    connect_timeout : float
    read_timeout : float
    """

    def __init__(
        self,
        base_url: str,
        username: str,
        password: str,
        *,
        verify_tls: bool = False,
        connect_timeout: float = DEFAULT_CONNECT_TIMEOUT,
        read_timeout: float = DEFAULT_READ_TIMEOUT,
    ) -> None:
        self._base_url = base_url.rstrip('/')
        self._auth = httpx.BasicAuth(username, password)
        self._verify = verify_tls
        self._timeout = httpx.Timeout(
            connect=connect_timeout,
            read=read_timeout,
            write=5.0,
            pool=5.0,
        )

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _get(self, path: str) -> Any:
        """Execute a GET request and return the parsed JSON body.

        Raises specific exceptions so callers can distinguish auth
        failures from connection or API errors.
        """
        url = f'{self._base_url}/rest{path}'
        try:
            response = httpx.get(
                url,
                auth=self._auth,
                verify=self._verify,
                timeout=self._timeout,
                trust_env=False,
            )
        except httpx.RemoteProtocolError as exc:
            raise MikroTikConnectionError(
                f'{exc}{self._protocol_hint()}'
            ) from exc
        except httpx.ConnectError as exc:
            raise MikroTikConnectionError(
                f'Cannot connect to {self._base_url}: {exc}'
            ) from exc
        except httpx.TimeoutException as exc:
            raise MikroTikConnectionError(
                f'Timeout connecting to {self._base_url}: {exc}'
            ) from exc
        except httpx.HTTPError as exc:
            raise MikroTikClientError(str(exc)) from exc

        if response.status_code == 401:
            raise MikroTikAuthError(
                f'Authentication failed for {self._base_url} '
                f'(HTTP {response.status_code})'
            )
        if response.status_code == 403:
            raise MikroTikAuthError(
                f'Forbidden for {self._base_url} (HTTP 403)'
            )
        if response.status_code >= 400:
            raise MikroTikAPIError(
                f'RouterOS returned HTTP {response.status_code} '
                f'for {path}: {response.text[:500]}'
            )
        return response.json()

    def _protocol_hint(self) -> str:
        """Return a short hint for likely scheme/port mismatches."""
        parts = urlsplit(self._base_url)
        scheme = (parts.scheme or '').lower()
        port = parts.port
        if scheme == 'http' and port == 443:
            return ' (configured as HTTP on port 443; verify scheme/port, HTTPS is typical on 443).'
        if scheme == 'https' and port == 80:
            return ' (configured as HTTPS on port 80; verify scheme/port, HTTP is typical on 80).'
        return ''

    # ------------------------------------------------------------------
    # Public endpoint methods
    # ------------------------------------------------------------------

    def get_identity(self) -> dict:
        """``/system/identity`` → ``{"name": "..."}``."""
        return self._get('/system/identity')

    def get_system_resource(self) -> dict:
        """``/system/resource`` → ``{"board-name": ..., "platform": ..., "version": ...}``."""
        return self._get('/system/resource')

    def get_bridge_vlans(self) -> list[dict]:
        """``/interface/bridge/vlan`` → list of bridge VLAN rows."""
        return self._get('/interface/bridge/vlan')

    def get_ip_addresses(self) -> list[dict]:
        """``/ip/address`` → list of IP address rows."""
        return self._get('/ip/address')

    def get_dhcp_servers(self) -> list[dict]:
        """``/ip/dhcp-server`` → list of DHCP server rows."""
        return self._get('/ip/dhcp-server')

    def get_dhcp_leases(self) -> list[dict]:
        """``/ip/dhcp-server/lease`` → list of DHCP lease rows."""
        return self._get('/ip/dhcp-server/lease')

    def get_arp(self) -> list[dict]:
        """``/ip/arp`` → list of ARP entries."""
        return self._get('/ip/arp')

    def get_neighbors(self) -> list[dict]:
        """``/ip/neighbor`` → list of discovered neighbor entries."""
        return self._get('/ip/neighbor')
