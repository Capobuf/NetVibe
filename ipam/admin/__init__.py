"""
IPAM admin package bootstrap and registration entrypoint.

Contains:
- imports that trigger Django admin registration side effects
- exported symbols referenced by dotted paths in admin configuration

Does not contain:
- ModelAdmin implementations
- admin business rules or query logic

Notes for maintainers:
- keep imports explicit so Django discovers all ModelAdmin classes
- preserve exported names used in string references (for example
  ``ipam.admin.VLANSubnetSection``)
"""

from ..models import IPAMSettings, MikroTikIntegrationSettings
from .vlan import VLANSubnetSection

# Import admin modules so Django registers ModelAdmin classes.
from . import base  # noqa: F401
from . import discovery  # noqa: F401
from . import ipaddress  # noqa: F401
from . import review  # noqa: F401
from . import subnet  # noqa: F401
from . import vlan  # noqa: F401

__all__ = [
    'IPAMSettings',
    'MikroTikIntegrationSettings',
    'VLANSubnetSection',
]
