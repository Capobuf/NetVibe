from datetime import timedelta
from urllib.parse import urlencode

from django.contrib import admin, messages
from django.contrib.admin.sites import NotRegistered
from django.contrib.auth.models import Group, User
from django.db import models as django_models
from django.db.utils import OperationalError, ProgrammingError
from django.shortcuts import redirect
from django.template.response import TemplateResponse
from django.urls import path, reverse
from django.utils.html import format_html, strip_tags
from django.utils.text import Truncator
from django.utils import timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.contrib.forms.widgets import WysiwygWidget
from unfold.datasets import BaseDataset
from unfold.decorators import action, display
from unfold.sections import TableSection

from .forms import MikroTikIntegrationSettingsForm
from .models import (
    IPAMSettings, IPAddress, IPAddressObservation, JobRun, MikroTikDevice,
    MikroTikDiscoveryResult, MikroTikDiscoveryRun, MikroTikIntegrationSettings,
    MikroTikSubnet, MikroTikSyncRun, MikroTikTarget, MikroTikVLAN, ReviewItem,
    SourceKind, Subnet, Tag, VLAN,
)


# Hide built-in auth models until dedicated admin workflows are defined.
for model in (User, Group):
    try:
        admin.site.unregister(model)
    except NotRegistered:
        pass


def _safe_exists(model):
    try:
        return model.objects.exists()
    except (OperationalError, ProgrammingError):
        return False


def _safe_first(model):
    try:
        return model.objects.order_by('pk').first()
    except (OperationalError, ProgrammingError):
        return None


def _format_vlan_read_label(vlan, *, empty_label='-'):
    if vlan is None:
        return empty_label
    return vlan.name or (_('VLAN %(number)s') % {'number': vlan.number})


def _format_admin_last_seen(value):
    if value is None:
        return '-'

    local_value = timezone.localtime(value) if timezone.is_aware(value) else value
    local_date = local_value.date()
    today = timezone.localdate()
    clock = local_value.strftime('%H:%M')

    if local_date == today:
        return f'Oggi - {clock}'
    if local_date == today - timedelta(days=1):
        return f'Ieri - {clock}'
    if local_date.year == today.year:
        return f'{local_value:%d/%m} - {clock}'
    return f'{local_value:%d/%m/%y} - {clock}'


class IPAddressTableMixin:
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
        return [
            (vlan.pk, _format_vlan_read_label(vlan))
            for vlan in VLAN.objects.order_by('number')
        ]

    def queryset(self, request, queryset):
        if self.value():
            return queryset.filter(subnet__vlan_id=self.value())
        return queryset


class SubnetVLANFilter(admin.SimpleListFilter):
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


class TagDisplayMixin:
    @staticmethod
    def _format_tag_list(obj):
        tag_names = [tag.name for tag in obj.tags.all()]
        if not tag_names:
            return '-'
        visible_names = tag_names[:3]
        hidden_count = len(tag_names) - len(visible_names)
        if hidden_count > 0:
            return f"{', '.join(visible_names)} +{hidden_count}"
        return ', '.join(visible_names)

    @admin.display(description=_('Tags'))
    def tag_list(self, obj):
        return self._format_tag_list(obj)


@admin.register(Tag)
class TagAdmin(ModelAdmin):
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


@admin.register(IPAddress)
class IPAddressAdmin(TagDisplayMixin, IPAddressTableMixin, ModelAdmin):
    change_form_outer_before_template = (
        'admin/ipam/ipaddress/change_form_outer_before.html'
    )
    actions_detail = (
        'edit_ip_settings',
        'back_to_ip_overview',
        'back_to_subnet',
    )
    list_display = (
        'status_badge',
        'address_display',
        'hostname_display',
        'mac_address_display',
        'latest_observed_hostname',
        'latest_observed_mac_address',
        'latest_observed_via',
        'last_seen_display',
        'notes_summary',
    )
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
                'fields': (
                    'note',
                    'tags',
                ),
            },
        ),
        (
            _('Latest observed data'),
            {
                'fields': (
                    (
                        'latest_observed_hostname',
                        'latest_observed_mac_address',
                    ),
                    (
                        'latest_observed_lease',
                        'latest_observed_at',
                    ),
                    'latest_observed_device',
                ),
            },
        ),
    )
    formfield_overrides = {
        django_models.TextField: {'widget': WysiwygWidget},
    }
    list_filter = ('subnet', 'status', 'exclude_ping')
    search_help_text = _(
        'Hostname and MAC are canonical IPAM values. '
        'Observed columns show latest DHCP/ARP data.'
    )
    readonly_fields = (
        'latest_observed_hostname',
        'latest_observed_mac_address',
        'latest_observed_lease',
        'latest_observed_at',
        'latest_observed_device',
    )

    def get_queryset(self, request):
        return super().get_queryset(request).select_related('subnet__vlan')

    def get_fieldsets(self, request, obj=None):
        if obj is not None and self._is_overview_mode(request):
            return ()
        return super().get_fieldsets(request, obj=obj)

    def get_readonly_fields(self, request, obj=None):
        if obj is not None and self._is_overview_mode(request):
            return (
                tuple(field.name for field in self.model._meta.fields)
                + (
                    'last_seen_display_readonly',
                    'latest_observed_hostname',
                    'latest_observed_mac_address',
                    'latest_observed_lease',
                    'latest_observed_at',
                    'latest_observed_device',
                )
            )
        return super().get_readonly_fields(request, obj=obj)

    def changeform_view(self, request, object_id=None, form_url='', extra_context=None):
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
            'back_to_ip_overview' if not self._is_overview_mode(request) else 'edit_ip_settings',
            'back_to_subnet',
        ]
        actions = [self.get_unfold_action(name) for name in action_names]
        return self._filter_unfold_actions_by_permissions(request, actions, object_id)

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
            context.update(self._get_overview_context(obj))

        return super().render_change_form(
            request,
            context,
            add=add,
            change=change,
            form_url=form_url,
            obj=obj,
        )

    def _get_overview_context(self, obj):
        reachability_labels = {
            'online': _('Online'),
            'offline': _('Offline'),
            'unknown': _('Unknown'),
        }
        subnet_change_url = reverse(
            f'admin:{Subnet._meta.app_label}_{Subnet._meta.model_name}_change',
            args=(obj.subnet_id,),
            current_app=self.admin_site.name,
        )
        vlan_label = _format_vlan_read_label(
            obj.subnet.vlan if obj.subnet.vlan_id else None,
            empty_label=str(_('No VLAN assigned')),
        )
        note = strip_tags(obj.note or '').strip()

        return {
            'ip_overview_cards': [
                {
                    'title': _('Network'),
                    'headline': obj.subnet.cidr,
                    'details': [
                        {
                            'label': _('Subnet'),
                            'value': format_html(
                                '<a href="{}">{}</a>',
                                subnet_change_url,
                                obj.subnet.cidr,
                            ),
                        },
                        {'label': _('VLAN'), 'value': vlan_label},
                    ],
                    'footer': _('Subnet and VLAN context'),
                },
                {
                    'title': _('Reachability'),
                    'headline': reachability_labels[self._get_reachability_status(obj)],
                    'details': [
                        {'label': _('Last seen'), 'value': _format_admin_last_seen(obj.last_seen)},
                        {
                            'label': _('Excluded from ping'),
                            'value': _('Yes') if obj.exclude_ping else _('No'),
                        },
                        {'label': _('Status'), 'value': obj.get_status_display()},
                    ],
                    'footer': _('Operational reachability summary'),
                },
                {
                    'title': _('Canonical data'),
                    'headline': obj.address,
                    'details': [
                        {'label': _('Hostname'), 'value': obj.hostname or '-'},
                        {'label': _('MAC'), 'value': obj.mac_address or '-'},
                        {
                            'label': _('Notes'),
                            'value': Truncator(note).chars(80) if note else '-',
                        },
                    ],
                    'footer': _('Manually managed IPAM fields'),
                },
            ],
        }

    def _is_overview_mode(self, request):
        return request.GET.get('mode') != 'edit'

    def _get_change_url(self, object_id):
        return reverse(
            f'admin:{self.model._meta.app_label}_{self.model._meta.model_name}_change',
            args=(object_id,),
            current_app=self.admin_site.name,
        )

    def _get_subnet_change_url(self, subnet_id):
        return reverse(
            f'admin:{Subnet._meta.app_label}_{Subnet._meta.model_name}_change',
            args=(subnet_id,),
            current_app=self.admin_site.name,
        )

    @action(description=_('Edit IP settings'), icon='edit')
    def edit_ip_settings(self, request, object_id):
        return redirect(f'{self._get_change_url(object_id)}?mode=edit')

    @action(description=_('Back to overview'), icon='visibility')
    def back_to_ip_overview(self, request, object_id):
        return redirect(self._get_change_url(object_id))

    @action(description=_('Back to subnet'), icon='account_tree')
    def back_to_subnet(self, request, object_id):
        next_url = request.GET.get('next')
        if next_url:
            return redirect(next_url)

        subnet_id = IPAddress.objects.filter(pk=object_id).values_list(
            'subnet_id',
            flat=True,
        ).first()
        if subnet_id is None:
            changelist_url = reverse(
                f'admin:{IPAddress._meta.app_label}_{IPAddress._meta.model_name}_changelist',
                current_app=self.admin_site.name,
            )
            return redirect(changelist_url)
        return redirect(self._get_subnet_change_url(subnet_id))

    @admin.display(description=_('Last seen'))
    def last_seen_display_readonly(self, obj):
        return _format_admin_last_seen(obj.last_seen)

    def _get_latest_observation(self, obj):
        if obj is None:
            return None
        if hasattr(obj, '_latest_observation_cache'):
            return obj._latest_observation_cache

        observation = (
            IPAddressObservation.objects.filter(subnet=obj.subnet, address=obj.address)
            .select_related('mikrotik_device')
            .order_by('-last_observed_at', '-pk')
            .first()
        )
        # Cache once per request-object render to avoid repeated lookups.
        obj._latest_observation_cache = observation
        return observation

    @admin.display(description=_('Observed hostname'))
    def latest_observed_hostname(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        return observation.observed_hostname or '-'

    @admin.display(description=_('Observed MAC'))
    def latest_observed_mac_address(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        return observation.observed_mac_address or '-'

    @admin.display(description=_('Observed via'))
    def latest_observed_via(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'

        observed_via = []
        if observation.observed_via_dhcp:
            observed_via.append('DHCP')
        if observation.observed_via_arp:
            observed_via.append('ARP')
        return ' + '.join(observed_via) if observed_via else '-'

    @admin.display(description=_('Observed lease'))
    def latest_observed_lease(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        if observation.lease_kind == IPAddress.LeaseKind.NONE:
            return '-'
        parts = [observation.get_lease_kind_display()]
        if observation.lease_status:
            parts.append(observation.lease_status)
        if observation.lease_server_name:
            parts.append(observation.lease_server_name)
        return ' | '.join(parts)

    @admin.display(description=_('Observed at'))
    def latest_observed_at(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None or observation.last_observed_at is None:
            return '-'
        return timezone.localtime(observation.last_observed_at).strftime(
            '%Y-%m-%d %H:%M:%S'
        )

    @admin.display(description=_('Observed from'))
    def latest_observed_device(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None or observation.mikrotik_device_id is None:
            return '-'
        return _admin_change_link(
            self,
            MikroTikDevice,
            observation.mikrotik_device_id,
            observation.mikrotik_device.name or observation.mikrotik_device.host,
        )


@admin.register(ReviewItem)
class ReviewItemAdmin(ModelAdmin):
    list_display = (
        'kind',
        'severity',
        'status',
        'title',
        'subnet',
        'ip_address',
        'mikrotik_device',
        'first_seen_at',
        'last_seen_at',
    )
    list_filter = ('kind', 'severity', 'status', 'mikrotik_device')
    ordering = ('-last_seen_at', '-pk')
    search_fields = (
        'title',
        'message',
        'dedupe_key',
        'subnet__cidr',
        'ip_address__address',
        'mikrotik_device__host',
        'mikrotik_device__name',
    )
    actions = ('mark_reviewed', 'mark_ignored', 'reopen')
    readonly_fields = (
        'kind',
        'severity',
        'title',
        'message',
        'subnet',
        'ip_address',
        'mikrotik_device',
        'dedupe_key',
        'first_seen_at',
        'last_seen_at',
        'payload_json',
    )
    fieldsets = (
        (
            _('Review item'),
            {
                'fields': (
                    'kind',
                    'severity',
                    'status',
                    'title',
                    'message',
                ),
            },
        ),
        (
            _('Related objects'),
            {
                'fields': (
                    'subnet',
                    'ip_address',
                    'mikrotik_device',
                ),
            },
        ),
        (
            _('Tracking'),
            {
                'fields': (
                    'dedupe_key',
                    'first_seen_at',
                    'last_seen_at',
                    'payload_json',
                ),
            },
        ),
    )

    def has_add_permission(self, request):
        return False

    @admin.action(description=_('Mark reviewed'))
    def mark_reviewed(self, request, queryset):
        queryset.update(status=ReviewItem.Status.REVIEWED)

    @admin.action(description=_('Ignore'))
    def mark_ignored(self, request, queryset):
        queryset.update(status=ReviewItem.Status.IGNORED)

    @admin.action(description=_('Reopen'))
    def reopen(self, request, queryset):
        queryset.update(status=ReviewItem.Status.OPEN)


@admin.register(IPAMSettings)
class IPAMSettingsAdmin(ModelAdmin):
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
            vlan_title = _format_vlan_read_label(
                obj.vlan,
                empty_label=str(_('No VLAN assigned')),
            )
            vlan_details.append(
                {
                    'label': _('VLAN ID'),
                    'value': str(obj.vlan.number),
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

        ip_record_details = [
            {
                'label': _('State'),
                'value': (
                    _('Empty (no tracked IPs yet)')
                    if not ip_addresses
                    else _('Tracked IPs present')
                ),
            },
            *[
                {
                    'label': status_labels[status],
                    'value': str(status_counts[status]),
                }
                for status in status_labels
            ],
        ]

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
                'details': ip_record_details,
                'footer': (
                    _('No tracked IP records yet. This can be normal right after import.')
                    if not ip_addresses
                    else _('Total records in this subnet')
                ),
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


def _admin_change_link(admin_obj, model, object_id, label):
    if not object_id:
        return '-'

    url = reverse(
        f'admin:{model._meta.app_label}_{model._meta.model_name}_change',
        args=(object_id,),
        current_app=admin_obj.admin_site.name,
    )
    return format_html('<a href="{}">{}</a>', url, label)


def _admin_datetime(value):
    if value is None:
        return '-'
    if timezone.is_naive(value):
        return value.strftime('%Y-%m-%d %H:%M:%S')
    return timezone.localtime(value).strftime('%Y-%m-%d %H:%M:%S')


def _admin_url(view_name, *, args=None, query=None):
    url = reverse(view_name, args=args or (), current_app=admin.site.name)
    if not query:
        return url
    return f'{url}?{urlencode(query)}'


def _build_failed_sync_run_entries():
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
    return {
        'status__exact': MikroTikDiscoveryResult.Status.BLOCKED,
        'reason_code__exact': MikroTikDiscoveryResult.ReasonCode.IDENTITY_CONFLICT,
    }


def _build_discovery_quick_links():
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
        from .tasks import schedule_mikrotik_discovery_for_targets

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
    model = MikroTikDiscoveryResult
    model_admin = MikroTikDiscoveryResultDatasetAdmin
    tab = False


@admin.register(MikroTikDiscoveryRun)
class MikroTikDiscoveryRunAdmin(ModelAdmin):
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
        from .tasks import schedule_mikrotik_sync_now

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
        parts = []
        if obj.vlans_created or obj.vlans_updated:
            parts.append(f'VLANs +{obj.vlans_created}/~{obj.vlans_updated}')
        if obj.subnets_created or obj.subnets_updated:
            parts.append(f'Subnets +{obj.subnets_created}/~{obj.subnets_updated}')
        if obj.ips_created or obj.ips_updated:
            parts.append(f'IPs +{obj.ips_created}/~{obj.ips_updated}')
        return ' | '.join(parts) if parts else '-'

    @admin.display(description=_('Device'), ordering='device__host')
    def device(self, obj):
        if obj.device_id is None:
            return _('Deleted device')
        return obj.device


@admin.register(MikroTikVLAN)
class MikroTikVLANAdmin(ModelAdmin):
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
