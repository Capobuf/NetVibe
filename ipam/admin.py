from urllib.parse import urlencode

from django.contrib import admin
from django.contrib.admin.sites import NotRegistered
from django.contrib.auth.models import Group, User
from django.db import models as django_models
from django.db.models import Prefetch
from django.shortcuts import redirect
from django.urls import reverse
from django.utils.html import strip_tags
from django.utils.text import Truncator
from django.utils import timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin, TabularInline
from unfold.contrib.forms.widgets import WysiwygWidget
from unfold.datasets import BaseDataset
from unfold.decorators import action, display
from unfold.sections import TableSection

from .models import IPAMSettings, IPAddress, JobRun, Section, Subnet, VLAN


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

        warning_after, offline_after = obj.subnet.get_effective_thresholds()
        age_seconds = (timezone.now() - obj.last_seen).total_seconds()

        if age_seconds > offline_after:
            return 'offline'
        if age_seconds > warning_after:
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


class ChildSubnetSection(TableSection):
    related_name = 'children'
    verbose_name = _('Child subnets')
    fields = ('cidr', 'vlan', 'scan_enabled', 'discover_subnet')


class SubnetIPAddressSection(TableSection):
    related_name = 'ipaddress_set'
    verbose_name = _('IP addresses')
    fields = (
        'address',
        'status',
        'hostname',
        'mac_address',
        'exclude_ping',
    )


class ChildSubnetInline(TabularInline):
    model = Subnet
    fk_name = 'parent'
    extra = 0
    show_change_link = True
    tab = True
    verbose_name = _('child subnet')
    verbose_name_plural = _('child subnets')
    fields = (
        'section',
        'cidr',
        'vlan',
        'scan_enabled',
        'discover_subnet',
        'description',
    )


class SubnetIPAddressInline(TabularInline):
    model = IPAddress
    fk_name = 'subnet'
    extra = 0
    show_change_link = False
    tab = True
    verbose_name = _('IP address')
    verbose_name_plural = _('IP addresses')
    fields = (
        'address',
        'status',
        'hostname',
        'mac_address',
        'exclude_ping',
    )


class JobRunSectionFilter(admin.SimpleListFilter):
    title = _('section')
    parameter_name = 'section'

    def lookups(self, request, model_admin):
        return [(section.pk, str(section)) for section in Section.objects.order_by('name')]

    def queryset(self, request, queryset):
        if self.value():
            return queryset.filter(subnet__section_id=self.value())
        return queryset


class JobRunVLANFilter(admin.SimpleListFilter):
    title = _('VLAN')
    parameter_name = 'vlan'

    def lookups(self, request, model_admin):
        return [(vlan.pk, str(vlan)) for vlan in VLAN.objects.order_by('number')]

    def queryset(self, request, queryset):
        if self.value():
            return queryset.filter(subnet__vlan_id=self.value())
        return queryset


@admin.register(Section)
class SectionAdmin(ModelAdmin):
    list_display = ('name', 'strict_mode')
    search_fields = ('name',)


@admin.register(VLAN)
class VLANAdmin(ModelAdmin):
    fieldsets = (
        (
            _('General'),
            {
                'classes': ('tab',),
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
                    'warning_after_seconds',
                    'offline_after_seconds',
                ),
            },
        ),
        (
            _('Scan defaults'),
            {
                'fields': ('default_scan_interval_seconds',),
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
        'warning_after_seconds',
        'offline_after_seconds',
        'default_scan_interval_seconds',
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


@admin.register(Subnet)
class SubnetAdmin(ModelAdmin):
    change_form_outer_before_template = (
        'admin/ipam/subnet/change_form_outer_before.html'
    )
    change_form_outer_after_template = None
    actions_detail = (
        'edit_subnet_settings',
        'back_to_overview',
        'open_ip_list',
    )
    change_form_datasets = (IPAddressDataset,)
    fieldsets = (
        (
            _('Basics'),
            {
                'classes': ('tab',),
                'fields': (
                    'section',
                    'cidr',
                    'parent',
                    'description',
                ),
            },
        ),
        (
            _('Routing'),
            {
                'classes': ('tab',),
                'fields': ('vlan',),
            },
        ),
        (
            _('Discovery'),
            {
                'classes': ('tab',),
                'fields': ('discover_subnet',),
            },
        ),
        (
            _('Scheduling'),
            {
                'classes': ('tab',),
                'fields': (
                    'scan_enabled',
                    'scan_interval_seconds',
                    'warning_after_seconds_override',
                    'offline_after_seconds_override',
                ),
            },
        ),
    )
    list_display = (
        'cidr',
        'section',
        'vlan',
        'parent',
        'scan_enabled',
        'discover_subnet',
        'used_count',
    )
    list_filter = ('section', 'vlan', 'scan_enabled', 'discover_subnet')
    list_sections = (ChildSubnetSection, SubnetIPAddressSection)
    list_sections_classes = 'lg:grid-cols-2'
    search_fields = ('=cidr', 'description')

    def get_changeform_datasets(self, request):
        object_id = getattr(getattr(request, 'resolver_match', None), 'kwargs', {}).get(
            'object_id'
        )
        if object_id is None:
            return []
        return super().get_changeform_datasets(request)

    def get_fieldsets(self, request, obj=None):
        if obj is not None and self._is_overview_mode(request):
            return ()
        return super().get_fieldsets(request, obj)

    def get_readonly_fields(self, request, obj=None):
        if obj is not None and self._is_overview_mode(request):
            return tuple(field.name for field in self.model._meta.fields)
        return super().get_readonly_fields(request, obj)

    def get_inlines(self, request, obj):
        return ()

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
            .select_related('section', 'vlan', 'parent')
            .prefetch_related(
                Prefetch(
                    'children',
                    queryset=Subnet.objects.select_related('vlan'),
                ),
                Prefetch('ipaddress_set', queryset=IPAddress.objects.all()),
            )
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
            ip_addresses = list(
                IPAddress.objects.filter(subnet_id=obj.pk)
                .order_by('address')
                .only(
                    'address',
                    'status',
                    'hostname',
                    'mac_address',
                )
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
                'title': _('Hierarchy'),
                'headline': obj.parent.cidr if obj.parent_id else _('No parent subnet'),
                'details': [
                    {
                        'label': _('Section'),
                        'value': str(obj.section),
                    },
                ],
                'footer': _('Parent subnet relationship'),
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
    list_filter = ('status', JobRunSectionFilter, JobRunVLANFilter)
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
        return super().get_queryset(request).select_related(
            'subnet',
            'subnet__section',
            'subnet__vlan',
        )

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
