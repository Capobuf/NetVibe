from urllib.parse import urlencode

from django.contrib import admin, messages
from django.contrib.admin.sites import NotRegistered
from django.contrib.auth.models import Group, User
from django.db import models as django_models, transaction
from django.shortcuts import redirect
from django.urls import reverse
from django.utils.html import strip_tags
from django.utils.text import Truncator
from django.utils import timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.contrib.forms.widgets import WysiwygWidget
from unfold.datasets import BaseDataset
from unfold.decorators import action, display

from .forms import MikroTikDeviceForm
from .models import (
    IPAMSettings, IPAddress, JobRun, MikroTikDevice,
    MikroTikSyncRun, SourceKind, Subnet, VLAN,
)


# Hide built-in auth models until dedicated admin workflows are defined.
for model in (User, Group):
    try:
        admin.site.unregister(model)
    except NotRegistered:
        pass


class IPAddressTableMixin:
    list_display = (
        'status_badge',
        'address_display',
        'hostname_display',
        'mac_address_display',
        'last_seen',
        'notes_summary',
    )
    list_display_links = ('address_display',)
    list_per_page = 100
    ordering = ('address',)
    search_fields = ('=address', 'hostname', 'mac_address')

    def get_queryset(self, request):
        return super().get_queryset(request).select_related('subnet')

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

    def _get_reachability_status(self, obj):
        if obj.exclude_ping or obj.last_seen is None:
            return 'unknown'

        warning_after, offline_after = obj.reachability_window
        age = timezone.now() - obj.last_seen

        if age > offline_after:
            return 'offline'
        if age > warning_after:
            return 'unknown'
        return 'online'


class IPAddressDatasetAdmin(IPAddressTableMixin, ModelAdmin):
    def get_queryset(self, request):
        queryset = super().get_queryset(request)
        subnet_id = getattr(self, 'extra_context', {}).get('object')

        if subnet_id is None:
            return queryset.none()

        return queryset.filter(subnet_id=subnet_id)

    def url_for_result(self, result):
        url = super().url_for_result(result)
        subnet_id = getattr(self, 'extra_context', {}).get('object')

        if subnet_id is None:
            return url

        next_url = reverse(
            f'admin:{Subnet._meta.app_label}_{Subnet._meta.model_name}_change',
            args=(subnet_id,),
            current_app=self.admin_site.name,
        )
        return f'{url}?{urlencode({"next": next_url})}'


class IPAddressDataset(BaseDataset):
    model = IPAddress
    model_admin = IPAddressDatasetAdmin
    tab = False


class JobRunVLANFilter(admin.SimpleListFilter):
    title = _('VLAN')
    parameter_name = 'vlan'

    def lookups(self, request, model_admin):
        return [(vlan.pk, str(vlan)) for vlan in VLAN.objects.order_by('number')]

    def queryset(self, request, queryset):
        if self.value():
            return queryset.filter(subnet__vlan_id=self.value())
        return queryset


@admin.register(VLAN)
class VLANAdmin(ModelAdmin):
    fieldsets = (
        (
            _('General'),
            {
                'fields': (
                    'number',
                    'name',
                ),
            },
        ),
    )
    list_display = ('number', 'name')
    list_filter = ('number', 'name')
    search_fields = ('=number', 'name')


@admin.register(IPAddress)
class IPAddressAdmin(IPAddressTableMixin, ModelAdmin):
    fieldsets = (
        (
            _('Identity'),
            {
                'fields': (
                    'subnet',
                    (
                        'address',
                        'mac_address',
                        'hostname',
                    ),
                    (
                        'status',
                        'exclude_ping',
                    ),
                ),
            },
        ),
        (
            _('Notes'),
            {
                'fields': ('note',),
            },
        ),
    )
    formfield_overrides = {
        django_models.TextField: {'widget': WysiwygWidget},
    }
    list_filter = ('subnet', 'status', 'exclude_ping')


@admin.register(IPAMSettings)
class IPAMSettingsAdmin(ModelAdmin):
    fieldsets = (
        (
            _('Thresholds'),
            {
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
        return not IPAMSettings.objects.exists()

    def has_delete_permission(self, request, obj=None):
        return False

    def changelist_view(self, request, extra_context=None):
        instance = IPAMSettings.objects.order_by('pk').first()
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
class SubnetAdmin(ModelAdmin):
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
        'vlan',
        'parent',
        'scan_enabled',
        'discover_subnet',
        'used_count',
    )
    list_filter = ('vlan', 'scan_enabled', 'discover_subnet')
    search_fields = ('=cidr', 'description')

    def get_changeform_datasets(self, request):
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
        if obj is not None and self._is_overview_mode(request):
            return ()
        return super().get_fieldsets(request, obj)

    def get_readonly_fields(self, request, obj=None):
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
            .prefetch_related('ipaddress_set')
        )

    @admin.display(description=_('Used IPs'))
    def used_count(self, obj):
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
        status_labels = dict(IPAddress.Status.choices)
        status_counts = {status: 0 for status in status_labels}

        for ip_address in ip_addresses:
            if ip_address.status in status_counts:
                status_counts[ip_address.status] += 1

        vlan_title = _('No VLAN assigned')
        vlan_details = [
            {
                'label': _('Subnet'),
                'value': obj.cidr,
            },
        ]

        if obj.vlan_id:
            vlan_title = _('VLAN %(number)s') % {'number': obj.vlan.number}
            vlan_details.append(
                {
                    'label': _('Name'),
                    'value': obj.vlan.name or _('Unnamed VLAN'),
                }
            )

        scanning_details = [
            {
                'label': _('Scan enabled'),
                'value': _('Yes') if obj.scan_enabled else _('No'),
            },
            {
                'label': _('Auto-discover IPs'),
                'value': _('Yes') if obj.discover_subnet else _('No'),
            },
        ]

        if obj.scan_interval_minutes:
            scanning_details.append(
                {
                    'label': _('Scan interval (minutes)'),
                    'value': str(obj.scan_interval_minutes),
                }
            )

        summary_cards = [
            {
                'title': _('VLAN'),
                'headline': vlan_title,
                'details': vlan_details,
                'footer': _('Subnet routing summary'),
            },
            {
                'title': _('IP records'),
                'headline': str(len(ip_addresses)),
                'details': [
                    {
                        'label': status_labels[status],
                        'value': str(status_counts[status]),
                    }
                    for status in status_labels
                ],
                'footer': _('Total records in this subnet'),
            },
            {
                'title': _('Scanning'),
                'headline': _('Active') if obj.scan_enabled else _('Disabled'),
                'details': scanning_details,
                'footer': (
                    _('Parent subnet: %(parent)s')
                    % {'parent': obj.parent.cidr}
                    if obj.parent_id
                    else _('No parent subnet')
                ),
            },
        ]

        return {
            'subnet_overview_cards': summary_cards,
        }

    def _is_overview_mode(self, request):
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
        if obj.duration is None:
            return '-'
        total_seconds = int(obj.duration.total_seconds())
        minutes, seconds = divmod(total_seconds, 60)
        hours, minutes = divmod(minutes, 60)
        if hours:
            return f'{hours:d}:{minutes:02d}:{seconds:02d}'
        return f'{minutes:d}:{seconds:02d}'

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


# =====================================================================
# MikroTik integration admin
# =====================================================================


@admin.register(MikroTikDevice)
class MikroTikDeviceAdmin(ModelAdmin):
    form = MikroTikDeviceForm
    list_display = (
        'name_or_host',
        'host',
        'enabled',
        'sync_enabled',
        'last_seen_version',
        'last_successful_sync_at',
    )
    list_filter = ('enabled', 'sync_enabled', 'device_role')
    search_fields = ('=host', 'name', 'last_seen_identity')
    search_help_text = _('Search by host, name, or identity.')
    ordering = ('name', 'host')

    fieldsets = (
        (
            _('Identity'),
            {
                'fields': ('name',),
            },
        ),
        (
            _('Connection'),
            {
                'fields': (
                    'host',
                    ('scheme', 'port'),
                    'username',
                    'password',
                    'verify_tls',
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
                    'device_role',
                    'is_authoritative_for_l3',
                    'is_authoritative_for_dhcp',
                ),
            },
        ),
        (
            _('Status'),
            {
                'fields': (
                    'last_seen_identity',
                    'last_seen_version',
                    'last_seen_board',
                    'last_seen_platform',
                    'last_successful_sync_at',
                    'last_error',
                ),
            },
        ),
    )
    readonly_fields = (
        'last_seen_identity',
        'last_seen_version',
        'last_seen_board',
        'last_seen_platform',
        'last_successful_sync_at',
        'last_error',
    )

    # Submitline actions — shown as buttons next to the Save button.
    actions_submit_line = ('save_and_test',)

    # Changeform object actions — shown in the detail toolbar.
    actions_detail = ('sync_now_action',)

    @admin.display(description=_('Name'))
    def name_or_host(self, obj):
        return obj.name or obj.host

    @action(description=_('Save and test'), attrs={'type': 'submit'})
    def save_and_test(self, request, obj):
        """Submit-line action: save the device and run a connection test."""
        # The form save already happened via the standard admin flow;
        # we trigger the test here as a post-save side-effect.
        from .integrations.mikrotik_sync import run_device_test

        sync_run = run_device_test(obj)
        if sync_run.status == MikroTikSyncRun.Status.SUCCESS:
            messages.success(
                request,
                _('Connection test succeeded: %(identity)s (RouterOS %(version)s).') % {
                    'identity': obj.last_seen_identity or obj.host,
                    'version': obj.last_seen_version or '?',
                },
            )
        else:
            messages.error(
                request,
                _('Connection test failed: %(error)s') % {
                    'error': sync_run.error_message[:300],
                },
            )

    @action(description=_('Sync now'), icon='sync')
    def sync_now_action(self, request, object_id):
        """Changeform object action: trigger an immediate full sync."""
        from .tasks import schedule_mikrotik_sync_now

        device = MikroTikDevice.objects.get(pk=object_id)
        schedule_mikrotik_sync_now(device)
        messages.info(request, _('Sync scheduled for %s.') % device)
        return redirect(
            reverse(
                'admin:ipam_mikrotikdevice_change',
                args=(object_id,),
                current_app=self.admin_site.name,
            )
        )


@admin.register(MikroTikSyncRun)
class MikroTikSyncRunAdmin(ModelAdmin):
    list_display = (
        'device',
        'mode',
        'status',
        'scheduled_for',
        'started_at',
        'finished_at',
        'sync_counters',
    )
    list_filter = ('status', 'mode', 'device')
    ordering = ('-scheduled_for',)
    search_fields = ('device__name', 'device__host', 'error_message')
    readonly_fields = (
        'device', 'mode', 'status',
        'scheduled_for', 'started_at', 'finished_at',
        'trace_id', 'job_id',
        'vlans_created', 'vlans_updated',
        'subnets_created', 'subnets_updated',
        'ips_created', 'ips_updated',
        'warning_summary', 'observed_summary',
        'error_message', 'task_ref',
    )
    fieldsets = (
        (
            _('Run'),
            {
                'fields': (
                    'device', 'mode', 'status', 'task_ref',
                    'trace_id', 'job_id',
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
                    'vlans_created', 'vlans_updated',
                    'subnets_created', 'subnets_updated',
                    'ips_created', 'ips_updated',
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
        parts = []
        if obj.vlans_created or obj.vlans_updated:
            parts.append(f'VLANs +{obj.vlans_created}/~{obj.vlans_updated}')
        if obj.subnets_created or obj.subnets_updated:
            parts.append(f'Subnets +{obj.subnets_created}/~{obj.subnets_updated}')
        if obj.ips_created or obj.ips_updated:
            parts.append(f'IPs +{obj.ips_created}/~{obj.ips_updated}')
        return ' | '.join(parts) if parts else '-'


# =====================================================================
# Proxy models for Integrations sidebar
# =====================================================================

class MikroTikVLAN(VLAN):
    """Proxy model to show only MikroTik-sourced VLANs in the Integrations group."""
    class Meta:
        proxy = True
        verbose_name = _('MikroTik VLAN')
        verbose_name_plural = _('MikroTik VLANs')


class MikroTikSubnet(Subnet):
    """Proxy model to show only MikroTik-sourced subnets in the Integrations group."""
    class Meta:
        proxy = True
        verbose_name = _('MikroTik subnet')
        verbose_name_plural = _('MikroTik subnets')


@admin.register(MikroTikVLAN)
class MikroTikVLANAdmin(ModelAdmin):
    """Filtered view of VLANs imported from MikroTik."""
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
    """Filtered view of subnets imported from MikroTik."""
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
