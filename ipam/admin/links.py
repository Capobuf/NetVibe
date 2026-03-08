"""
Admin URL and link helpers shared by IPAM admin modules.

Contains:
- utilities for admin change/history/changelist URL generation
- safe HTML link helpers for admin changelist and detail views

Does not contain:
- ModelAdmin logic or permission checks
- template context assembly

Notes for maintainers:
- keep helper outputs stable because tests and templates rely on them
- prefer these helpers over inline reverse/urlencode duplication
"""

from urllib.parse import urlencode

from django.contrib import admin
from django.urls import reverse
from django.utils.html import format_html


def _admin_change_link(admin_obj, model, object_id, label):
    """Render an admin change link when the related object still exists."""
    if not object_id:
        return '-'

    url = reverse(
        f'admin:{model._meta.app_label}_{model._meta.model_name}_change',
        args=(object_id,),
        current_app=admin_obj.admin_site.name,
    )
    return format_html('<a href="{}">{}</a>', url, label)


def _admin_url(view_name, *, args=None, query=None):
    """Build an admin URL with optional query parameters."""
    url = reverse(view_name, args=args or (), current_app=admin.site.name)
    if not query:
        return url
    return f'{url}?{urlencode(query)}'


def model_change_url(admin_obj, model, object_id):
    """Return the change URL for ``model`` and ``object_id``."""
    return reverse(
        f'admin:{model._meta.app_label}_{model._meta.model_name}_change',
        args=(object_id,),
        current_app=admin_obj.admin_site.name,
    )


def model_history_url(admin_obj, model, object_id):
    """Return the history URL for ``model`` and ``object_id``."""
    return reverse(
        f'admin:{model._meta.app_label}_{model._meta.model_name}_history',
        args=(object_id,),
        current_app=admin_obj.admin_site.name,
    )


def model_changelist_url(admin_obj, model):
    """Return the changelist URL for ``model``."""
    return reverse(
        f'admin:{model._meta.app_label}_{model._meta.model_name}_changelist',
        current_app=admin_obj.admin_site.name,
    )
