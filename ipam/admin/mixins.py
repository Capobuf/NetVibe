"""
Reusable admin mixins for compact shared table behaviors.

Contains:
- IP address table columns and reachability badge logic
- tag list rendering shared by VLAN/Subnet/IP admin views

Does not contain:
- model registration
- cross-model navigation or heavy context builders

Notes for maintainers:
- keep mixins focused and small to avoid deep inheritance trees
- extract only behavior reused by multiple ModelAdmin classes
"""

from django.contrib import admin
from django.utils import timezone
from django.utils.html import strip_tags
from django.utils.text import Truncator
from django.utils.translation import gettext_lazy as _

from unfold.decorators import display

from .formatters import _format_admin_last_seen, format_tag_list


class IPAddressTableMixin:
    """Shared list columns and status logic for IP address tables."""

    list_display = (
        'status_badge',
        'address_display',
        'hostname_display',
        'mac_address_display',
        'last_seen_display',
        'notes_summary',
    )
    list_display_links = ('address_display',)
    list_per_page = 100
    ordering = ('address',)
    search_fields = ('=address', 'hostname', 'mac_address')

    def get_queryset(self, request):
        return super().get_queryset(request).select_related('subnet').prefetch_related('tags')

    @display(
        description=_('Status'),
        header=False,
        label={
            'online': 'success',
            'offline': 'danger',
            'unknown': 'info',
        },
        ordering='last_seen',
    )
    def status_badge(self, obj):
        status = self._get_reachability_status(obj)
        labels = {
            'online': _('Online'),
            'offline': _('Offline'),
            'unknown': _('Unknown'),
        }
        return (status, labels[status])

    @display(description=_('IP address'), header=True, ordering='address')
    def address_display(self, obj):
        return (obj.address, '')

    @display(description=_('Hostname'), header=True, ordering='hostname')
    def hostname_display(self, obj):
        return (obj.hostname or '-', '')

    @display(description=_('MAC'), header=True)
    def mac_address_display(self, obj):
        return (
            obj.mac_address or '-',
            '',
        )

    @display(description=_('Notes'), header=True)
    def notes_summary(self, obj):
        note = strip_tags(obj.note or '').strip()
        return (Truncator(note).chars(80) if note else '-', '')

    @display(description=_('Last seen'), header=True, ordering='last_seen')
    def last_seen_display(self, obj):
        return (_format_admin_last_seen(obj.last_seen), '')

    def _get_reachability_status(self, obj):
        """Map reachability window age to an admin-facing status code."""
        if obj.last_seen is None:
            return 'unknown'

        warning_after, offline_after = obj.reachability_window
        age = timezone.now() - obj.last_seen

        if age > offline_after:
            return 'offline'
        if age > warning_after:
            return 'unknown'
        return 'online'


class TagDisplayMixin:
    """Reusable compact tag rendering for objects with ``tags`` relation."""

    @staticmethod
    def _format_tag_list(obj):
        return format_tag_list(obj)

    @admin.display(description=_('Tags'))
    def tag_list(self, obj):
        return self._format_tag_list(obj)
