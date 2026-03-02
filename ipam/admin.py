from django.contrib import admin
from django.db.models import Prefetch
from django.shortcuts import redirect
from django.utils import formats, timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin, TabularInline
from unfold.sections import TableSection

from .models import IPAMSettings, IPAddress, JobRun, Section, Subnet, VLAN


class ChildSubnetSection(TableSection):
    related_name = 'children'
    verbose_name = _('Child subnets')
    fields = ('cidr', 'vlan', 'scan_enabled', 'discover_subnet')


class SubnetIPAddressSection(TableSection):
    related_name = 'ipaddress_set'
    verbose_name = _('IP addresses')
    fields = (
        'address',
        'state',
        'hostname',
        'mac_address',
        'owner',
        'last_seen',
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
        'state',
        'hostname',
        'mac_address',
        'owner',
        'last_seen',
        'exclude_ping',
        'description',
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
    list_display = ('number', 'name')
    search_fields = ('=number', 'name')


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
    change_form_outer_after_template = (
        'admin/ipam/subnet/change_form_outer_after.html'
    )
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
    inlines = (ChildSubnetInline, SubnetIPAddressInline)
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
                    'state',
                    'hostname',
                    'mac_address',
                    'owner',
                    'last_seen',
                    'exclude_ping',
                )
            )
            job_runs = list(
                JobRun.objects.filter(subnet_id=obj.pk)
                .order_by('-scheduled_for')[:5]
            )
            context.update(self._get_overview_context(obj, ip_addresses, job_runs))

        return super().render_change_form(
            request,
            context,
            add=add,
            change=change,
            form_url=form_url,
            obj=obj,
        )

    def _get_overview_context(self, obj, ip_addresses, job_runs):
        state_labels = dict(IPAddress.State.choices)
        state_counts = {state: 0 for state in state_labels}

        for ip_address in ip_addresses:
            if ip_address.state in state_counts:
                state_counts[ip_address.state] += 1

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
                        'label': state_labels[state],
                        'value': str(state_counts[state]),
                    }
                    for state in state_labels
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

        ip_table = {
            'headers': [
                _('Address'),
                _('State'),
                _('Hostname'),
                _('MAC'),
                _('Owner'),
                _('Last seen'),
                _('Exclude ping'),
            ],
            'rows': [
                [
                    ip_address.address,
                    ip_address.get_state_display(),
                    ip_address.hostname or '-',
                    ip_address.mac_address or '-',
                    ip_address.owner or '-',
                    self._format_datetime(ip_address.last_seen),
                    _('Yes') if ip_address.exclude_ping else _('No'),
                ]
                for ip_address in ip_addresses
            ],
        }

        job_table = {
            'headers': [
                _('Status'),
                _('Scheduled for'),
                _('Duration'),
                _('Hosts alive'),
                _('Created'),
                _('Updated'),
            ],
            'rows': [
                [
                    job_run.get_status_display(),
                    self._format_datetime(job_run.scheduled_for),
                    self._format_duration(job_run.duration),
                    str(job_run.hosts_alive),
                    str(job_run.ip_records_created),
                    str(job_run.ip_records_updated),
                ]
                for job_run in job_runs
            ],
        }

        return {
            'subnet_overview_cards': summary_cards,
            'subnet_ip_table': ip_table,
            'subnet_ip_total': len(ip_addresses),
            'subnet_job_table': job_table,
            'subnet_job_total': len(job_runs),
        }

    def _format_datetime(self, value):
        if value is None:
            return '-'

        if timezone.is_naive(value):
            return formats.date_format(value, 'SHORT_DATETIME_FORMAT')

        return formats.date_format(
            timezone.localtime(value),
            'SHORT_DATETIME_FORMAT',
        )

    def _format_duration(self, value):
        if value is None:
            return '-'

        total_seconds = int(value.total_seconds())
        minutes, seconds = divmod(total_seconds, 60)
        hours, minutes = divmod(minutes, 60)

        if hours:
            return f'{hours:d}:{minutes:02d}:{seconds:02d}'
        return f'{minutes:d}:{seconds:02d}'


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
