"""
Discovery and MikroTik integration admin orchestration.

Contains:
- discovery overview page and extra admin URL wiring
- ModelAdmin classes for targets, devices, discovery runs, and sync runs
- read-only operational views for imported MikroTik VLAN/Subnet proxies

Does not contain:
- discovery/sync execution logic (tasks own that behavior)
- core IPAM subnet/IP canonical admin views

Notes for maintainers:
- this module orchestrates admin UX, not integration business rules
- preserve extra URL chaining so other apps can still extend admin URLs
"""

from urllib.parse import urlencode

from django.contrib import admin, messages
from django.shortcuts import redirect
from django.template.response import TemplateResponse
from django.urls import path, reverse
from django.utils import timezone
from django.utils.html import format_html
from django.utils.text import Truncator
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.datasets import BaseDataset
from unfold.decorators import action, display

from ..forms import MikroTikIntegrationSettingsForm
from ..models import (
    MikroTikDevice,
    MikroTikDiscoveryResult,
    MikroTikDiscoveryRun,
    MikroTikIntegrationSettings,
    MikroTikSubnet,
    MikroTikSyncRun,
    MikroTikTarget,
    MikroTikVLAN,
    SourceKind,
)
from .base import _safe_exists, _safe_first
from .formatters import _admin_datetime, format_sync_counters
from .links import _admin_change_link, _admin_url


def _build_failed_sync_run_entries():
    """Return a compact list of recent failed sync runs for overview cards."""
    failed_sync_runs = list(
        MikroTikSyncRun.objects.filter(status=MikroTikSyncRun.Status.FAILED)
        .select_related('device')
        .order_by('-scheduled_for', '-pk')[:5]
    )
    return [
        {
            'id': run.pk,
            'device_label': (
                (run.device.name or run.device.host)
                if run.device_id
                else _('Deleted device')
            ),
            'scheduled_for': _admin_datetime(run.scheduled_for),
            'error_message': Truncator(run.error_message).chars(140) or '-',
            'change_url': _admin_url('admin:ipam_mikrotiksyncrun_change', args=(run.pk,)),
        }
        for run in failed_sync_runs
    ]


def _discovery_conflicts_filter():
    """Centralize filters for identity conflicts used across links/cards."""
    return {
        'status__exact': MikroTikDiscoveryResult.Status.BLOCKED,
        'reason_code__exact': MikroTikDiscoveryResult.ReasonCode.IDENTITY_CONFLICT,
    }


def _build_discovery_quick_links():
    """Build navigation shortcuts shown in the discovery overview page."""
    quick_link_specs = (
        ('Targets', 'Manage onboarding endpoints.', 'admin:ipam_mikrotiktarget_changelist'),
        ('Devices', 'Tune sync behavior for onboarded devices.', 'admin:ipam_mikrotikdevice_changelist'),
        (
            'Discovery runs',
            'Review onboarding batches and statuses.',
            'admin:ipam_mikrotikdiscoveryrun_changelist',
        ),
        ('Sync runs', 'Inspect synchronization history and errors.', 'admin:ipam_mikrotiksyncrun_changelist'),
        (
            'MikroTik settings',
            'Update integration credentials and retention.',
            'admin:ipam_mikrotikintegrationsettings_changelist',
        ),
        ('Review queue', 'Review conflicts, new IPs, and failures.', 'admin:ipam_reviewitem_changelist'),
    )
    return [
        {
            'title': _(title),
            'description': _(description),
            'url': _admin_url(url_name),
        }
        for title, description, url_name in quick_link_specs
    ]


def _build_discovery_overview_cards(conflicts_filter):
    """Build headline KPI cards for discovery overview."""
    return [
        {
            'title': _('Total MikroTik targets'),
            'headline': str(MikroTikTarget.objects.count()),
            'footer': _('Configured discovery endpoints'),
        },
        {
            'title': _('Linked/onboarded devices'),
            'headline': str(
                MikroTikTarget.objects.exclude(linked_device__isnull=True).count()
            ),
            'footer': _('Targets already linked to a device'),
        },
        {
            'title': _('Active discovery runs'),
            'headline': str(
                MikroTikDiscoveryRun.objects.filter(
                    status__in=(
                        MikroTikDiscoveryRun.Status.SCHEDULED,
                        MikroTikDiscoveryRun.Status.RUNNING,
                    )
                ).count()
            ),
            'footer': _('Runs currently scheduled or running'),
        },
        {
            'title': _('Blocked discovery conflicts'),
            'headline': str(
                MikroTikDiscoveryResult.objects.filter(**conflicts_filter).count()
            ),
            'footer': _('Identity conflicts requiring manual resolution'),
        },
    ]


def discovery_overview_view(request):
    """Render the server-side discovery operations overview page."""
    conflicts_filter = _discovery_conflicts_filter()

    context = {
        **admin.site.each_context(request),
        'title': _('Discovery overview'),
        'subtitle': None,
        'overview_cards': _build_discovery_overview_cards(conflicts_filter),
        'latest_failed_sync_runs': _build_failed_sync_run_entries(),
        'latest_failed_sync_runs_url': _admin_url(
            'admin:ipam_mikrotiksyncrun_changelist',
            query={'status__exact': MikroTikSyncRun.Status.FAILED},
        ),
        'blocked_conflicts_url': _admin_url(
            'admin:ipam_mikrotikdiscoveryresult_changelist',
            query=conflicts_filter,
        ),
        'quick_links': _build_discovery_quick_links(),
    }
    return TemplateResponse(request, 'admin/ipam/discovery_overview.html', context)


_original_admin_extra_urls = getattr(admin.site, 'extra_urls', None)


def _netvibe_admin_extra_urls():
    """Prepend NetVibe discovery overview while preserving existing hooks."""
    # Preserve extra admin URLs from other modules and prepend NetVibe overview.
    existing_urls = _original_admin_extra_urls() if callable(_original_admin_extra_urls) else []
    return [
        path(
            'discovery-overview/',
            admin.site.admin_view(discovery_overview_view),
            name='ipam_discovery_overview',
        ),
        *existing_urls,
    ]


admin.site.extra_urls = _netvibe_admin_extra_urls


@admin.register(MikroTikIntegrationSettings)
class MikroTikIntegrationSettingsAdmin(ModelAdmin):
    """Singleton admin for shared MikroTik credentials and retention policy."""

    form = MikroTikIntegrationSettingsForm
    fieldsets = (
        (
            _('Credentials'),
            {
                'fields': ('username', 'password'),
            },
        ),
        (
            _('Housekeeping'),
            {
                'fields': ('run_retention_days',),
            },
        ),
    )

    def has_add_permission(self, request):
        return not _safe_exists(MikroTikIntegrationSettings)

    def has_delete_permission(self, request, obj=None):
        return False

    def changelist_view(self, request, extra_context=None):
        instance = _safe_first(MikroTikIntegrationSettings)
        if instance is not None:
            return redirect('admin:ipam_mikrotikintegrationsettings_change', instance.pk)
        return super().changelist_view(request, extra_context=extra_context)

    def changeform_view(self, request, object_id=None, form_url='', extra_context=None):
        extra_context = extra_context or {}
        extra_context['subtitle'] = None
        return super().changeform_view(
            request,
            object_id=object_id,
            form_url=form_url,
            extra_context=extra_context,
        )


@admin.register(MikroTikTarget)
class MikroTikTargetAdmin(ModelAdmin):
    """Onboarding endpoint admin used to schedule discovery batches."""

    list_display = (
        'host',
        'scheme',
        'port',
        'verify_tls',
        'last_discovery_status',
        'last_reason_code',
        'last_seen_identity',
        'linked_device_link',
        'last_discovered_at',
    )
    list_filter = (
        'scheme',
        'verify_tls',
        'last_discovery_status',
        ('linked_device', admin.EmptyFieldListFilter),
    )
    search_fields = ('=host', 'last_seen_identity')
    search_help_text = _(
        '1) Configure settings. 2) Add targets. 3) Select targets. 4) Run discovery.'
    )
    list_fullwidth = False
    list_horizontal_scrollbar_top = True
    actions = ('run_discovery_for_selected_targets',)
    fieldsets = (
        (
            _('Endpoint'),
            {
                'fields': ('host', 'scheme', 'port', 'verify_tls'),
                'description': _(
                    'Targets are the onboarding source of truth for endpoint settings.'
                ),
            },
        ),
        (
            _('Discovery state'),
            {
                'fields': (
                    'linked_device',
                    'last_discovered_at',
                    'last_discovery_status',
                    'last_reason_code',
                    'last_message',
                    'last_seen_identity',
                    'last_seen_version',
                    'last_seen_board',
                    'last_seen_platform',
                ),
            },
        ),
    )
    readonly_fields = (
        'linked_device',
        'last_discovered_at',
        'last_discovery_status',
        'last_reason_code',
        'last_message',
        'last_seen_identity',
        'last_seen_version',
        'last_seen_board',
        'last_seen_platform',
    )
    actions_detail = ('run_discovery_now',)

    @admin.display(description=_('Linked device'))
    def linked_device_link(self, obj):
        if not obj.linked_device_id:
            return '-'
        label = obj.linked_device.name or obj.linked_device.host
        return _admin_change_link(self, MikroTikDevice, obj.linked_device_id, label)

    @admin.action(description=_('Run discovery for selected targets'))
    def run_discovery_for_selected_targets(self, request, queryset):
        """Schedule one discovery batch and redirect to the created run."""
        from ..tasks import schedule_mikrotik_discovery_for_targets

        run, error_message = schedule_mikrotik_discovery_for_targets(
            queryset,
            created_by=request.user,
        )
        if run is None:
            self.message_user(request, error_message, level=messages.WARNING)
            return None

        self.message_user(
            request,
            _(
                'Discovery run #%(run_id)s scheduled for %(count)s target(s).'
            )
            % {'run_id': run.pk, 'count': run.total_targets},
            level=messages.INFO,
        )
        return redirect(
            reverse(
                'admin:ipam_mikrotikdiscoveryrun_change',
                args=(run.pk,),
                current_app=self.admin_site.name,
            )
        )

    @action(description=_('Run discovery now'), icon='play_arrow')
    def run_discovery_now(self, request, object_id):
        target_qs = MikroTikTarget.objects.filter(pk=object_id)
        return self.run_discovery_for_selected_targets(request, target_qs)


class MikroTikDiscoveryResultDatasetAdmin(ModelAdmin):
    """Embedded discovery result table scoped to one discovery run."""

    list_display = (
        'target',
        'status_badge',
        'reason_code',
        'action_taken',
        'linked_device',
        'conflicting_device',
        'detail_message_summary',
        'finished_at',
    )
    list_display_links = ('target',)
    ordering = ('pk',)

    @display(
        description=_('Status'),
        label={
            MikroTikDiscoveryResult.Status.SUCCESS: 'success',
            MikroTikDiscoveryResult.Status.FAILED: 'danger',
            MikroTikDiscoveryResult.Status.BLOCKED: 'warning',
            MikroTikDiscoveryResult.Status.RUNNING: 'info',
            MikroTikDiscoveryResult.Status.SCHEDULED: 'info',
        },
    )
    def status_badge(self, obj):
        return (obj.status, obj.get_status_display())

    @admin.display(description=_('Detail'))
    def detail_message_summary(self, obj):
        return Truncator(obj.detail_message or '').chars(120) or '-'

    def get_queryset(self, request):
        queryset = super().get_queryset(request).select_related(
            'target',
            'linked_device',
            'conflicting_device',
        )
        run_id = getattr(self, 'extra_context', {}).get('object')
        if run_id is None:
            return queryset.none()
        return queryset.filter(run_id=run_id)


class MikroTikDiscoveryResultDataset(BaseDataset):
    """Dataset descriptor for run-scoped discovery results."""

    model = MikroTikDiscoveryResult
    model_admin = MikroTikDiscoveryResultDatasetAdmin
    tab = False


@admin.register(MikroTikDiscoveryRun)
class MikroTikDiscoveryRunAdmin(ModelAdmin):
    """Read-oriented admin for discovery batch lifecycle and counters."""

    list_display = (
        'id',
        'status',
        'created_at',
        'started_at',
        'finished_at',
        'created_by',
        'total_targets',
        'success_count',
        'failed_count',
        'blocked_count',
    )
    list_filter = ('status', 'created_by')
    search_fields = ('=id', 'created_by__username', 'task_ref')
    ordering = ('-created_at',)
    change_form_datasets = (MikroTikDiscoveryResultDataset,)
    change_form_outer_before_template = (
        'admin/ipam/mikrotik_discovery_run/change_form_outer_before.html'
    )
    actions_detail = ('open_filtered_results',)
    readonly_fields = (
        'status',
        'created_at',
        'started_at',
        'finished_at',
        'created_by',
        'total_targets',
        'success_count',
        'failed_count',
        'blocked_count',
        'created_count',
        'updated_count',
        'reused_count',
        'task_ref',
        'results_changelist_link',
    )
    fieldsets = (
        (
            _('Run'),
            {
                'fields': (
                    'status',
                    'task_ref',
                    'created_by',
                    'results_changelist_link',
                ),
            },
        ),
        (
            _('Timing'),
            {
                'fields': ('created_at', 'started_at', 'finished_at'),
            },
        ),
        (
            _('Counters'),
            {
                'fields': (
                    'total_targets',
                    'success_count',
                    'failed_count',
                    'blocked_count',
                    'created_count',
                    'updated_count',
                    'reused_count',
                ),
            },
        ),
    )

    def has_add_permission(self, request):
        return False

    def has_delete_permission(self, request, obj=None):
        return False

    def get_changeform_datasets(self, request):
        """Hide related dataset on add view where run id is unavailable."""
        object_id = getattr(getattr(request, 'resolver_match', None), 'kwargs', {}).get(
            'object_id'
        )
        if object_id is None:
            return []
        return super().get_changeform_datasets(request)

    def changeform_view(self, request, object_id=None, form_url='', extra_context=None):
        extra_context = extra_context or {}
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

    @admin.display(description=_('All results'))
    def results_changelist_link(self, obj):
        return format_html(
            '<a href="{}">{}</a>',
            self._get_filtered_results_url(obj.pk),
            _('Open filtered results list'),
        )

    def _get_filtered_results_url(self, object_id):
        changelist_url = reverse(
            'admin:ipam_mikrotikdiscoveryresult_changelist',
            current_app=self.admin_site.name,
        )
        return f'{changelist_url}?{urlencode({"run__id__exact": object_id})}'

    @action(description=_('Open filtered results list'), icon='table_view')
    def open_filtered_results(self, request, object_id):
        return redirect(self._get_filtered_results_url(object_id))


@admin.register(MikroTikDiscoveryResult)
class MikroTikDiscoveryResultAdmin(ModelAdmin):
    """Read-only per-target discovery outcomes with linked object shortcuts."""

    list_display = (
        'run',
        'target_link',
        'status',
        'reason_code',
        'action_taken',
        'linked_device_link',
        'conflicting_device_link',
        'finished_at',
    )
    list_filter = ('run', 'status', 'reason_code')
    search_fields = ('target__host', 'seen_identity')
    ordering = ('-created_at',)
    readonly_fields = (
        'run',
        'target_link',
        'status',
        'reason_code',
        'detail_message',
        'seen_identity',
        'seen_version',
        'seen_board',
        'seen_platform',
        'action_taken',
        'linked_device_link',
        'conflicting_device_link',
        'created_at',
        'started_at',
        'finished_at',
    )
    fieldsets = (
        (
            _('Result'),
            {
                'fields': (
                    'run',
                    'target_link',
                    'status',
                    'reason_code',
                    'action_taken',
                    'detail_message',
                ),
            },
        ),
        (
            _('Detected'),
            {
                'fields': (
                    'seen_identity',
                    'seen_version',
                    'seen_board',
                    'seen_platform',
                ),
            },
        ),
        (
            _('Links'),
            {
                'fields': ('linked_device_link', 'conflicting_device_link'),
            },
        ),
        (
            _('Timing'),
            {
                'fields': ('created_at', 'started_at', 'finished_at'),
            },
        ),
    )

    def has_add_permission(self, request):
        return False

    def has_delete_permission(self, request, obj=None):
        return False

    @admin.display(description=_('Target'))
    def target_link(self, obj):
        if not obj.target_id:
            return '-'
        return _admin_change_link(self, MikroTikTarget, obj.target_id, obj.target.host)

    @admin.display(description=_('Linked device'))
    def linked_device_link(self, obj):
        if not obj.linked_device_id:
            return '-'
        label = obj.linked_device.name or obj.linked_device.host
        return _admin_change_link(self, MikroTikDevice, obj.linked_device_id, label)

    @admin.display(description=_('Conflicting device'))
    def conflicting_device_link(self, obj):
        if not obj.conflicting_device_id:
            return '-'
        label = obj.conflicting_device.name or obj.conflicting_device.host
        return _admin_change_link(self, MikroTikDevice, obj.conflicting_device_id, label)


@admin.register(MikroTikDevice)
class MikroTikDeviceAdmin(ModelAdmin):
    """Post-onboarding device admin for sync policy and diagnostics."""

    list_display = (
        'name_or_host',
        'host',
        'sync_enabled',
        'sync_interval_minutes',
        'last_seen_version',
        'last_successful_sync_at',
    )
    list_filter = ('enabled', 'sync_enabled')
    search_fields = ('=host', 'name', 'last_seen_identity')
    search_help_text = _('Search by host, name, or last seen identity.')
    ordering = ('host',)
    readonly_fields = (
        'host',
        'scheme',
        'port',
        'verify_tls',
        'linked_target_link',
        'last_successful_sync_at',
        'last_error',
        'last_seen_identity',
        'last_seen_version',
        'last_seen_board',
        'last_seen_platform',
        'sync_summary',
    )
    fieldsets = (
        (
            _('Identity'),
            {
                'fields': ('name',),
            },
        ),
        (
            _('Endpoint (managed from target)'),
            {
                'fields': (
                    'host',
                    'scheme',
                    'port',
                    'verify_tls',
                    'linked_target_link',
                ),
                'description': _(
                    'Endpoint configuration is managed in MikroTik targets. '
                    'Rerunning discovery overwrites these endpoint values.'
                ),
            },
        ),
        (
            _('Synchronization'),
            {
                'fields': (
                    'enabled',
                    'sync_enabled',
                    'sync_interval_minutes',
                    'sync_summary',
                ),
            },
        ),
        (
            _('Import policy'),
            {
                'fields': (
                    'import_vlans',
                    'import_subnets',
                    'import_dhcp_leases',
                    'import_arp',
                    'import_neighbors',
                    'ignore_dynamic_bridge_vlans',
                ),
            },
        ),
        (
            _('Authority'),
            {
                'fields': (
                    'is_authoritative_for_l3',
                    'is_authoritative_for_dhcp',
                ),
            },
        ),
        (
            _('Status'),
            {
                'fields': (
                    'last_successful_sync_at',
                    'last_error',
                    'last_seen_identity',
                    'last_seen_version',
                    'last_seen_board',
                    'last_seen_platform',
                ),
            },
        ),
    )
    actions_detail = ('sync_now_action',)

    def has_add_permission(self, request):
        return False

    @admin.display(description=_('Name'))
    def name_or_host(self, obj):
        return obj.name or obj.host

    @admin.display(description=_('Linked target'))
    def linked_target_link(self, obj):
        if not hasattr(obj, 'linked_target') or obj.linked_target is None:
            return '-'
        return _admin_change_link(
            self,
            MikroTikTarget,
            obj.linked_target.pk,
            obj.linked_target.host,
        )

    @admin.display(description=_('Last synchronization run'))
    def sync_summary(self, obj):
        """Summarize the most recent synchronization run status for a device."""
        latest_sync = (
            obj.sync_runs.order_by('-scheduled_for', '-pk').first()
        )
        if latest_sync is None:
            return _('Never run')
        when = latest_sync.finished_at or latest_sync.started_at or latest_sync.scheduled_for
        parts = [
            latest_sync.get_status_display(),
            timezone.localtime(when).strftime('%Y-%m-%d %H:%M:%S'),
        ]
        if latest_sync.status == MikroTikSyncRun.Status.FAILED and latest_sync.error_message:
            parts.append(
                _('error: %(message)s')
                % {'message': Truncator(latest_sync.error_message).chars(180)}
            )
        return ' | '.join(parts)

    @action(description=_('Sync now'), icon='sync')
    def sync_now_action(self, request, object_id):
        """Schedule immediate sync and redirect back to device detail."""
        from ..tasks import schedule_mikrotik_sync_now

        device = MikroTikDevice.objects.get(pk=object_id)
        sync_run = schedule_mikrotik_sync_now(device)
        if sync_run is None:
            messages.warning(
                request,
                _(
                    'Synchronization was not scheduled because this device is disabled.'
                ),
            )
        else:
            messages.info(
                request,
                _(
                    'Synchronization scheduled in background. '
                    'Refresh this page to see the result.'
                ),
            )
        return redirect(
            reverse(
                'admin:ipam_mikrotikdevice_change',
                args=(object_id,),
                current_app=self.admin_site.name,
            )
        )


@admin.register(MikroTikSyncRun)
class MikroTikSyncRunAdmin(ModelAdmin):
    """Read-only history of synchronization runs and diagnostics."""

    list_display = (
        'device',
        'status',
        'scheduled_for',
        'started_at',
        'finished_at',
        'sync_counters',
    )
    list_filter = ('status', 'device')
    ordering = ('-scheduled_for',)
    search_fields = ('device__name', 'device__host', 'error_message', 'trace_id')
    readonly_fields = (
        'device',
        'status',
        'scheduled_for',
        'started_at',
        'finished_at',
        'trace_id',
        'job_id',
        'vlans_created',
        'vlans_updated',
        'subnets_created',
        'subnets_updated',
        'ips_created',
        'ips_updated',
        'warning_summary',
        'observed_summary',
        'error_message',
        'task_ref',
    )
    fieldsets = (
        (
            _('Run'),
            {
                'fields': (
                    'device',
                    'status',
                    'task_ref',
                    'trace_id',
                    'job_id',
                ),
            },
        ),
        (
            _('Timing'),
            {
                'fields': ('scheduled_for', 'started_at', 'finished_at'),
            },
        ),
        (
            _('Counters'),
            {
                'fields': (
                    'vlans_created',
                    'vlans_updated',
                    'subnets_created',
                    'subnets_updated',
                    'ips_created',
                    'ips_updated',
                ),
            },
        ),
        (
            _('Diagnostics'),
            {
                'fields': ('warning_summary', 'observed_summary', 'error_message'),
            },
        ),
    )

    def has_add_permission(self, request):
        return False

    def has_delete_permission(self, request, obj=None):
        return False

    @admin.display(description=_('Counters'))
    def sync_counters(self, obj):
        return format_sync_counters(obj)

    @admin.display(description=_('Device'), ordering='device__host')
    def device(self, obj):
        if obj.device_id is None:
            return _('Deleted device')
        return obj.device


@admin.register(MikroTikVLAN)
class MikroTikVLANAdmin(ModelAdmin):
    """Operational view over VLANs imported from MikroTik sources only."""

    list_display = (
        'number',
        'name',
        'source_device',
        'source_upstream_disabled',
        'integration_sync_enabled',
        'last_synced_at',
    )
    list_filter = (
        'source_device',
        'source_upstream_disabled',
        'integration_sync_enabled',
    )
    search_fields = ('=number', 'name', 'source_device__name', 'source_device__host')
    search_help_text = _('Search by VLAN number, name, or source device.')
    list_editable = ('integration_sync_enabled',)
    list_fullwidth = True

    fieldsets = (
        (
            _('VLAN'),
            {'fields': ('number', 'name')},
        ),
        (
            _('Integration'),
            {
                'fields': (
                    'integration_sync_enabled',
                    'source_upstream_disabled',
                    'source_bridge_name',
                    'source_device',
                    'last_synced_at',
                    'sync_state',
                ),
            },
        ),
    )
    readonly_fields = (
        'source_upstream_disabled',
        'source_bridge_name',
        'source_device',
        'last_synced_at',
        'sync_state',
    )

    def get_queryset(self, request):
        return (
            super()
            .get_queryset(request)
            .filter(source_kind=SourceKind.MIKROTIK)
            .select_related('source_device')
        )

    def has_add_permission(self, request):
        return False


@admin.register(MikroTikSubnet)
class MikroTikSubnetAdmin(ModelAdmin):
    """Operational view over subnets imported from MikroTik sources only."""

    list_display = (
        'cidr',
        'vlan',
        'source_device',
        'source_interface_name',
        'source_upstream_disabled',
        'source_upstream_invalid',
        'integration_sync_enabled',
        'scan_enabled',
        'last_synced_at',
    )
    list_filter = (
        'source_device',
        'vlan',
        'source_upstream_disabled',
        'source_upstream_invalid',
        'integration_sync_enabled',
        'scan_enabled',
    )
    search_fields = ('=cidr', 'source_device__name', 'source_device__host')
    search_help_text = _('Search by CIDR or source device.')
    list_editable = ('integration_sync_enabled', 'scan_enabled')
    list_fullwidth = True

    fieldsets = (
        (
            _('Subnet'),
            {'fields': ('cidr', 'vlan', 'scan_enabled', 'discover_subnet')},
        ),
        (
            _('Integration'),
            {
                'fields': (
                    'integration_sync_enabled',
                    'source_upstream_disabled',
                    'source_upstream_invalid',
                    'source_interface_name',
                    'source_device',
                    'last_synced_at',
                    'sync_state',
                ),
            },
        ),
    )
    readonly_fields = (
        'source_upstream_disabled',
        'source_upstream_invalid',
        'source_interface_name',
        'source_device',
        'last_synced_at',
        'sync_state',
    )

    def get_queryset(self, request):
        return (
            super()
            .get_queryset(request)
            .filter(source_kind=SourceKind.MIKROTIK)
            .select_related('vlan', 'source_device')
        )

    def has_add_permission(self, request):
        return False
