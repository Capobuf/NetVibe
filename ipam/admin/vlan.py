"""
VLAN and tag admin views, including VLAN-scoped list filters.

Contains:
- ModelAdmin classes for ``Tag`` and ``VLAN``
- VLAN-related list filters reused by subnet/job admin pages
- expandable VLAN section rendering linked subnets

Does not contain:
- subnet overview/edit workflows
- MikroTik discovery/sync admin views

Notes for maintainers:
- keep ``VLANSubnetSection`` importable as ``ipam.admin.VLANSubnetSection``
- avoid placing shared generic helpers here; use dedicated helper modules
"""

from django.contrib import admin, messages
from django.db import models as django_models
from django.urls import reverse
from django.utils.html import format_html
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.sections import TableSection

from ..models import Subnet, Tag, VLAN
from .formatters import _format_vlan_read_label
from .mixins import TagDisplayMixin


class JobRunVLANFilter(admin.SimpleListFilter):
    """Filter job runs by the VLAN assigned to their subnet."""

    title = _('VLAN')
    parameter_name = 'vlan'

    def lookups(self, request, model_admin):
        return [
            (vlan.pk, _format_vlan_read_label(vlan))
            for vlan in VLAN.objects.order_by('number')
        ]

    def queryset(self, request, queryset):
        if self.value():
            return queryset.filter(subnet__vlan_id=self.value())
        return queryset


class SubnetVLANFilter(admin.SimpleListFilter):
    """Filter subnets by VLAN with consistent label formatting."""

    title = _('VLAN')
    parameter_name = 'vlan'

    def lookups(self, request, model_admin):
        return [
            (vlan.pk, _format_vlan_read_label(vlan))
            for vlan in VLAN.objects.order_by('number')
        ]

    def queryset(self, request, queryset):
        if self.value():
            return queryset.filter(vlan_id=self.value())
        return queryset


@admin.register(Tag)
class TagAdmin(ModelAdmin):
    """Admin for user-managed and system-protected taxonomy tags."""

    fieldsets = (
        (
            _('General'),
            {
                'fields': ('name', 'slug', 'is_system'),
            },
        ),
    )
    list_display = ('name', 'slug', 'is_system')
    list_filter = ('is_system',)
    search_fields = ('name', 'slug')

    def get_readonly_fields(self, request, obj=None):
        if obj is not None and obj.is_system:
            return ('slug', 'is_system')
        return super().get_readonly_fields(request, obj=obj)

    def has_delete_permission(self, request, obj=None):
        if obj is not None and obj.is_system:
            return False
        return super().has_delete_permission(request, obj=obj)

    def delete_queryset(self, request, queryset):
        # System tags encode canonical semantics and must remain immutable.
        system_tags = queryset.filter(is_system=True)
        if system_tags.exists():
            self.message_user(
                request,
                _('System tags cannot be deleted.'),
                level=messages.WARNING,
            )
        deletable_queryset = queryset.exclude(is_system=True)
        if deletable_queryset.exists():
            super().delete_queryset(request, deletable_queryset)


@admin.register(VLAN)
class VLANAdmin(TagDisplayMixin, ModelAdmin):
    """Admin for canonical VLAN records with expandable subnet details."""

    fieldsets = (
        (
            _('General'),
            {
                'fields': (
                    'number',
                    'name',
                    'tags',
                ),
            },
        ),
    )
    list_display = ('number', 'name', 'tag_list')
    list_sections = ('ipam.admin.VLANSubnetSection',)
    list_filter = ('number', 'name')
    search_fields = ('=number', 'name')

    def get_queryset(self, request):
        subnet_queryset = Subnet.objects.only(
            'id',
            'vlan_id',
            'cidr',
            'description',
            'sync_state',
            'scan_enabled',
        ).order_by('cidr')
        # Prefetch related subnets once so expanded rows do not issue per-row queries.
        return super().get_queryset(request).prefetch_related(
            'tags',
            django_models.Prefetch('subnet_set', queryset=subnet_queryset),
        )


class VLANSubnetSection(TableSection):
    """Inline expandable table showing subnets belonging to a VLAN row."""

    related_name = 'subnet_set'
    verbose_name = _('Subnets')
    fields = ('cidr_link', 'description_value', 'sync_state', 'scan_enabled')

    @admin.display(description=_('CIDR'))
    def cidr_link(self, obj):
        url = reverse(
            f'admin:{obj._meta.app_label}_{obj._meta.model_name}_change',
            args=(obj.pk,),
            current_app=admin.site.name,
        )
        return format_html('<a href="{}">{}</a>', url, obj.cidr)

    @admin.display(description=_('Description'))
    def description_value(self, obj):
        return obj.description or '-'
