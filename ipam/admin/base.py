"""
Shared low-level admin safeguards used across IPAM admin modules.

Contains:
- one-time unregister of built-in auth models from this admin site
- fail-safe helpers for singleton checks before migrations are ready

Does not contain:
- ModelAdmin classes
- UI formatting, links, or template context builders

Notes for maintainers:
- these helpers intentionally swallow schema-level DB errors
- keep this module dependency-light to avoid import cycles
"""

from django.contrib import admin
from django.contrib.admin.sites import NotRegistered
from django.contrib.auth.models import Group, User
from django.db.utils import OperationalError, ProgrammingError


# Hide built-in auth models until dedicated admin workflows are defined.
for model in (User, Group):
    try:
        admin.site.unregister(model)
    except NotRegistered:
        pass


def _safe_exists(model):
    """Return ``model.objects.exists()`` while tolerating missing tables."""
    try:
        return model.objects.exists()
    except (OperationalError, ProgrammingError):
        return False


def _safe_first(model):
    """Return the first row while tolerating missing tables."""
    try:
        return model.objects.order_by('pk').first()
    except (OperationalError, ProgrammingError):
        return None
