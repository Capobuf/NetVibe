"""Thin REST client for MikroTik RouterOS v7."""

from __future__ import annotations

import ssl
from typing import Any
from urllib.parse import urlsplit

import httpx

DEFAULT_CONNECT_TIMEOUT = 15.0
DEFAULT_READ_TIMEOUT = 30.0
DISCOVERY_CONNECT_TIMEOUT = 5.0
DISCOVERY_READ_TIMEOUT = 10.0


class MikroTikClientError(Exception):
    """Base error for all client failures."""


class MikroTikAuthError(MikroTikClientError):
    """Authentication to the device was rejected."""


class MikroTikForbiddenError(MikroTikClientError):
    """The user authenticated but lacks required RouterOS permissions."""


class MikroTikConnectionError(MikroTikClientError):
    """Could not reach the device endpoint."""


class MikroTikTimeoutError(MikroTikConnectionError):
    """The device did not respond within configured timeout bounds."""


class MikroTikTLSError(MikroTikConnectionError):
    """TLS negotiation or certificate validation failed."""


class MikroTikHTTPError(MikroTikClientError):
    """The device returned a non-auth HTTP error."""

    def __init__(self, status_code: int, message: str):
        self.status_code = status_code
        super().__init__(message)


class MikroTikClient:
    """REST client for one MikroTik RouterOS v7 device."""

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

    def _get(self, path: str) -> Any:
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
        except httpx.TimeoutException as exc:
            raise MikroTikTimeoutError(
                f'Timeout while connecting to {self._base_url}.'
            ) from exc
        except httpx.ConnectError as exc:
            if self._looks_like_tls_error(exc):
                raise MikroTikTLSError(
                    f'TLS handshake failed for {self._base_url}. '
                    'Check scheme, port, and certificate settings.'
                ) from exc

            message = str(exc)
            if 'refused' in message.lower():
                raise MikroTikConnectionError(
                    f'Connection refused by {self._base_url}.'
                ) from exc

            raise MikroTikConnectionError(
                f'Cannot connect to {self._base_url}: {message}'
            ) from exc
        except httpx.HTTPError as exc:
            raise MikroTikClientError(str(exc)) from exc

        if response.status_code == 401:
            raise MikroTikAuthError(
                f'Authentication failed for {self._base_url} (HTTP 401).'
            )
        if response.status_code == 403:
            raise MikroTikForbiddenError(
                f'Forbidden for {self._base_url} (HTTP 403). '
                'Verify RouterOS REST API permissions.'
            )
        if response.status_code >= 400:
            body_preview = (response.text or '')[:300]
            raise MikroTikHTTPError(
                response.status_code,
                (
                    f'RouterOS returned HTTP {response.status_code} for {path}. '
                    f'{body_preview}'
                ).strip(),
            )

        return response.json()

    def _looks_like_tls_error(self, exc: httpx.ConnectError) -> bool:
        cause = exc.__cause__
        if isinstance(cause, ssl.SSLError):
            return True
        message = str(exc).lower()
        return 'tls' in message or 'ssl' in message or 'certificate' in message

    def _protocol_hint(self) -> str:
        parts = urlsplit(self._base_url)
        scheme = (parts.scheme or '').lower()
        port = parts.port
        if scheme == 'http' and port == 443:
            return ' (configured as HTTP on port 443; verify scheme/port).' 
        if scheme == 'https' and port == 80:
            return ' (configured as HTTPS on port 80; verify scheme/port).'
        return ''

    def get_identity(self) -> dict:
        return self._get('/system/identity')

    def get_system_resource(self) -> dict:
        return self._get('/system/resource')

    def get_bridge_vlans(self) -> list[dict]:
        return self._get('/interface/bridge/vlan')

    def get_ip_addresses(self) -> list[dict]:
        return self._get('/ip/address')

    def get_dhcp_servers(self) -> list[dict]:
        return self._get('/ip/dhcp-server')

    def get_dhcp_leases(self) -> list[dict]:
        return self._get('/ip/dhcp-server/lease')

    def get_arp(self) -> list[dict]:
        return self._get('/ip/arp')

    def get_neighbors(self) -> list[dict]:
        return self._get('/ip/neighbor')
