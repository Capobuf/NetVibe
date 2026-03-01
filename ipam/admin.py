from django.contrib import admin
from django.db.models import Prefetch
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
    show_change_link = True
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


@admin.register(IPAddress)
class IPAddressAdmin(ModelAdmin):
    fieldsets = (
        (
            _('Basics'),
            {
                'classes': ('tab',),
                'fields': ('address', 'subnet', 'state'),
            },
        ),
        (
            _('Identity'),
            {
                'classes': ('tab',),
                'fields': ('hostname', 'mac_address', 'owner'),
            },
        ),
        (
            _('Notes/Discovery'),
            {
                'classes': ('tab',),
                'fields': ('description', 'note', 'last_seen', 'exclude_ping'),
            },
        ),
    )
    list_display = (
        'address',
        'state',
        'hostname',
        'description',
        'mac_address',
        'owner',
        'subnet',
        'subnet_vlan',
        'last_seen',
        'exclude_ping',
    )
    list_filter = (
        'state',
        'exclude_ping',
        'subnet',
        'subnet__section',
        'subnet__vlan',
    )
    search_fields = (
        '=address',
        'hostname',
        'description',
        'note',
        'mac_address',
        'owner',
    )

    @admin.display(description=_('Subnet VLAN'), ordering='subnet__vlan__number')
    def subnet_vlan(self, obj):
        return obj.subnet.vlan
