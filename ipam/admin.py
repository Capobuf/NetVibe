from urllib.parse import urlencode

from django.contrib import admin
from django.contrib.admin.sites import NotRegistered
from django.contrib.auth.models import Group, User
from django.db import models as django_models
from django.shortcuts import redirect
from django.urls import reverse
from django.utils.html import strip_tags
from django.utils.text import Truncator
from django.utils import timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.contrib.forms.widgets import WysiwygWidget
from unfold.datasets import BaseDataset
from unfold.decorators import display

from .models import IPAMSettings, IPAddress, JobRun, Subnet, VLAN


# Hide built-in auth models until dedicated admin workflows are defined.
for model in (User, Group):
    try:
        admin.site.unregister(model)
    except NotRegistered:
        pass


class IPAddressTableMixin:
    list_display = (
        'status_badge',
        'ip_with_hostname',
        'mac_address_display',
        'last_seen',
        'notes_summary',
    )
    list_display_links = ('ip_with_hostname',)
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
    def ip_with_hostname(self, obj):
        return (
            obj.address,
            obj.hostname or '',
        )

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
