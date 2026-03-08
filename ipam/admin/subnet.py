"""
Subnet-centric admin orchestration for IPAM settings and scan history.

Contains:
- ModelAdmin classes for ``IPAMSettings``, ``Subnet``, and ``JobRun``
- subnet overview/edit mode routing and summary-card context assembly
- subnet-to-IP list navigation actions for admin workflows

Does not contain:
- IP address detail overview rendering logic
- MikroTik discovery and synchronization admin pages

Notes for maintainers:
- keep ``SubnetAdmin`` as UI orchestrator; move pure formatting elsewhere
- preserve overview-vs-edit behavior because templates depend on it
"""

from urllib.parse import urlencode

from django.contrib import admin
from django.shortcuts import redirect
from django.urls import reverse
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.decorators import action, display

from ..models import IPAMSettings, IPAddress, JobRun, Subnet
from .base import _safe_exists, _safe_first
from .formatters import _format_vlan_read_label, build_subnet_overview_context, format_job_duration
from .ipaddress import IPAddressDataset
from .mixins import TagDisplayMixin
from .vlan import JobRunVLANFilter, SubnetVLANFilter


@admin.register(IPAMSettings)
class IPAMSettingsAdmin(ModelAdmin):
    """Singleton-style admin for global reachability and worker settings."""

    fieldsets = (
        (
            _('Thresholds'),
            {
                'description': _(
                    'Create this global settings record once, then update values as needed.'
                ),
                'fields': (
                    'warning_after_minutes',
                    'offline_after_minutes',
                ),
            },
        ),
        (
            _('Scan defaults'),
            {
                'fields': ('default_scan_interval_minutes',),
            },
        ),
        (
            _('Worker'),
            {
                'fields': (
                    'worker_mode',
                    'worker_concurrency',
                    'ping_timeout_ms',
                    'ping_concurrency',
                ),
            },
        ),
    )
    list_display = (
        'warning_after_minutes',
        'offline_after_minutes',
        'default_scan_interval_minutes',
        'worker_mode',
        'worker_concurrency',
    )

    def has_add_permission(self, request):
        return not _safe_exists(IPAMSettings)

    def has_delete_permission(self, request, obj=None):
        return False

    def changelist_view(self, request, extra_context=None):
        instance = _safe_first(IPAMSettings)
        if instance is not None:
            return redirect('admin:ipam_ipamsettings_change', instance.pk)
        return super().changelist_view(request, extra_context=extra_context)

    def changeform_view(
        self,
        request,
        object_id=None,
        form_url='',
        extra_context=None,
    ):
        extra_context = extra_context or {}
        extra_context['subtitle'] = None
        return super().changeform_view(
            request,
            object_id=object_id,
            form_url=form_url,
            extra_context=extra_context,
        )


@admin.register(Subnet)
class SubnetAdmin(TagDisplayMixin, ModelAdmin):
    """Subnet admin with read-first overview and explicit edit mode."""

    change_form_outer_before_template = (
        'admin/ipam/subnet/change_form_outer_before.html'
    )
    actions_detail = (
        'edit_subnet_settings',
        'back_to_overview',
        'open_ip_list',
    )
    change_form_datasets = (IPAddressDataset,)
    fieldsets = (
        (
            _('Base'),
            {
                'fields': (
                    'vlan',
                    'cidr',
                    'parent',
                    'description',
                    'tags',
                ),
            },
        ),
        (
            _('Scanning'),
            {
                'fields': (
                    'scan_enabled',
                    'discover_subnet',
                    'scan_interval_minutes',
                    'warning_after_minutes_override',
                    'offline_after_minutes_override',
                ),
            },
        ),
    )
    list_display = (
        'cidr',
        'vlan_display',
        'tag_list',
    )
    list_filter = (SubnetVLANFilter, 'scan_enabled', 'discover_subnet')
    search_fields = ('=cidr', 'description')

    def get_changeform_datasets(self, request):
        """Hide related datasets on add view where object context is missing."""
        object_id = getattr(getattr(request, 'resolver_match', None), 'kwargs', {}).get(
            'object_id'
        )
        if object_id is None:
            return []
        return super().get_changeform_datasets(request)

    def get_form(self, request, obj=None, change=False, **kwargs):
        form = super().get_form(request, obj=obj, change=change, **kwargs)
        if 'scan_enabled' in form.base_fields:
            form.base_fields['scan_enabled'].help_text = _(
                'Run scheduled ICMP scans for this subnet. Disable it to stop all scans.'
            )
        if 'discover_subnet' in form.base_fields:
            form.base_fields['discover_subnet'].label = _('Auto-discover IPs')
            form.base_fields['discover_subnet'].help_text = _(
                'During scans, create IP records for responding hosts that are not already tracked and capture their MAC address and reverse DNS hostname when available. This only applies when scanning is enabled.'
            )
        return form

    def get_fieldsets(self, request, obj=None):
        """Render a card-only overview unless the user requests edit mode."""
        if obj is not None and self._is_overview_mode(request):
            return ()
        return super().get_fieldsets(request, obj)

    def get_readonly_fields(self, request, obj=None):
        """Lock full model fields while browsing in overview mode."""
        if obj is not None and self._is_overview_mode(request):
            return tuple(field.name for field in self.model._meta.fields)
        return super().get_readonly_fields(request, obj)

    def changeform_view(
        self,
        request,
        object_id=None,
        form_url='',
        extra_context=None,
    ):
        extra_context = extra_context or {}

        if object_id is not None and self._is_overview_mode(request):
            extra_context.update(
                {
                    'show_save': False,
                    'show_save_and_continue': False,
                    'show_save_and_add_another': False,
                    'show_save_as_new': False,
                    'show_delete': False,
                }
            )

        return super().changeform_view(
            request,
            object_id=object_id,
            form_url=form_url,
            extra_context=extra_context,
        )

    def get_actions_detail(self, request, object_id):
        """Swap detail actions to reflect current overview/edit mode."""
        action_names = [
            'back_to_overview' if not self._is_overview_mode(request) else 'edit_subnet_settings',
            'open_ip_list',
        ]
        actions = [self.get_unfold_action(name) for name in action_names]
        return self._filter_unfold_actions_by_permissions(request, actions, object_id)

    def get_queryset(self, request):
        return (
            super()
            .get_queryset(request)
            .select_related('vlan', 'parent')
            .prefetch_related('ipaddress_set', 'tags')
        )

    @admin.display(description=_('VLAN'), ordering='vlan__number')
    def vlan_display(self, obj):
        return _format_vlan_read_label(obj.vlan)

    @display(
        description=_('IP state'),
        label={
            'empty': 'warning',
            'tracked': 'success',
        },
    )
    def subnet_ip_state(self, obj):
        if not obj.ipaddress_set.exists():
            return ('empty', _('Empty (no tracked IPs yet)'))
        return ('tracked', _('Tracked IPs present'))

    @admin.display(description=_('Tracked IPs'))
    def tracked_ip_count(self, obj):
        return len(obj.ipaddress_set.all())

    def render_change_form(
        self,
        request,
        context,
        add=False,
        change=False,
        form_url='',
        obj=None,
    ):
        if change and obj is not None:
            ip_addresses = sorted(
                obj.ipaddress_set.all(),
                key=lambda ip: ip.address,
            )
            context.update(self._get_overview_context(obj, ip_addresses))

        return super().render_change_form(
            request,
            context,
            add=add,
            change=change,
            form_url=form_url,
            obj=obj,
        )

    def _get_overview_context(self, obj, ip_addresses):
        """Build context for the read-only subnet overview cards."""
        return build_subnet_overview_context(obj, ip_addresses, IPAddress.Status.choices)

    def _is_overview_mode(self, request):
        """Default to overview mode unless ``mode=edit`` is explicitly set."""
        return request.GET.get('mode') != 'edit'

    def _get_change_url(self, object_id):
        return reverse(
            f'admin:{self.model._meta.app_label}_{self.model._meta.model_name}_change',
            args=(object_id,),
            current_app=self.admin_site.name,
        )

    @action(description=_('Edit subnet settings'), icon='edit')
    def edit_subnet_settings(self, request, object_id):
        return redirect(f'{self._get_change_url(object_id)}?mode=edit')

    @action(description=_('Back to overview'), icon='visibility')
    def back_to_overview(self, request, object_id):
        return redirect(self._get_change_url(object_id))

    @action(description=_('Open IP list'), icon='table_view')
    def open_ip_list(self, request, object_id):
        changelist_url = reverse(
            f'admin:{IPAddress._meta.app_label}_{IPAddress._meta.model_name}_changelist',
            current_app=self.admin_site.name,
        )
        return redirect(
            f'{changelist_url}?{urlencode({"subnet__id__exact": object_id})}'
        )


@admin.register(JobRun)
class JobRunAdmin(ModelAdmin):
    """Read-only execution history for subnet scan runs."""

    list_display = (
        'subnet',
        'status',
        'scheduled_for',
        'started_at',
        'finished_at',
        'duration_summary',
        'counter_summary',
    )
    list_filter = ('status', JobRunVLANFilter)
    ordering = ('-scheduled_for',)
    search_fields = ('subnet__cidr', 'error_message')
    readonly_fields = (
        'job_type',
        'subnet',
        'status',
        'scheduled_for',
        'started_at',
        'finished_at',
        'hosts_total',
        'hosts_pinged',
        'hosts_alive',
        'ip_records_created',
        'ip_records_updated',
        'error_message',
        'task_ref',
    )
    fieldsets = (
        (
            _('Job'),
            {
                'fields': (
                    'job_type',
                    'subnet',
                    'status',
                    'task_ref',
                ),
            },
        ),
        (
            _('Timing'),
            {
                'fields': (
                    'scheduled_for',
                    'started_at',
                    'finished_at',
                ),
            },
        ),
        (
            _('Counters'),
            {
                'fields': (
                    'hosts_total',
                    'hosts_pinged',
                    'hosts_alive',
                    'ip_records_created',
                    'ip_records_updated',
                ),
            },
        ),
        (
            _('Error'),
            {
                'fields': ('error_message',),
            },
        ),
    )

    def get_queryset(self, request):
        return super().get_queryset(request).select_related('subnet', 'subnet__vlan')

    def has_add_permission(self, request):
        return False

    def has_delete_permission(self, request, obj=None):
        return False

    @admin.display(description=_('Duration'))
    def duration_summary(self, obj):
        return format_job_duration(obj.duration)

    @admin.display(description=_('Counters'))
    def counter_summary(self, obj):
        return _(
            'alive %(alive)s / pinged %(pinged)s / created %(created)s / updated %(updated)s'
        ) % {
            'alive': obj.hosts_alive,
            'pinged': obj.hosts_pinged,
            'created': obj.ip_records_created,
            'updated': obj.ip_records_updated,
        }
