from django.contrib import admin
from django.db.models import Prefetch
from django.utils import formats, timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin, TabularInline
from unfold.sections import TableSection

from .models import IPAddress, Section, Subnet, VLAN


class ChildSubnetSection(TableSection):
    related_name = 'children'
    verbose_name = _('Child subnets')
    fields = ('cidr', 'vlan', 'ping_subnet', 'discover_subnet')


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
        'ping_subnet',
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


@admin.register(Section)
class SectionAdmin(ModelAdmin):
    list_display = ('name', 'strict_mode')
    search_fields = ('name',)


@admin.register(VLAN)
class VLANAdmin(ModelAdmin):
    list_display = ('number', 'name')
    search_fields = ('=number', 'name')


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
                'fields': ('ping_subnet', 'discover_subnet'),
            },
        ),
    )
    inlines = (ChildSubnetInline, SubnetIPAddressInline)
    list_display = (
        'cidr',
        'section',
        'vlan',
        'parent',
        'ping_subnet',
        'discover_subnet',
        'used_count',
    )
    list_filter = ('section', 'vlan', 'ping_subnet', 'discover_subnet')
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

        table = {
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
                    self._format_last_seen(ip_address.last_seen),
                    _('Yes') if ip_address.exclude_ping else _('No'),
                ]
                for ip_address in ip_addresses
            ],
        }

        return {
            'subnet_overview_cards': summary_cards,
            'subnet_ip_table': table,
            'subnet_ip_total': len(ip_addresses),
        }

    def _format_last_seen(self, value):
        if value is None:
            return '-'

        if timezone.is_naive(value):
            return formats.date_format(value, 'SHORT_DATETIME_FORMAT')

        return formats.date_format(
            timezone.localtime(value),
            'SHORT_DATETIME_FORMAT',
        )
