"""
IP address admin views and dataset wiring for subnet detail pages.

Contains:
- ModelAdmin for ``IPAddress`` with overview/edit mode orchestration
- embedded dataset classes used by subnet change forms
- latest-observation read helpers for canonical-vs-observed presentation

Does not contain:
- subnet-wide overview cards
- discovery/sync run administration

Notes for maintainers:
- preserve canonical/observed separation in UI helpers
- keep navigation helpers delegated to ``links.py`` for consistency
"""

from urllib.parse import urlencode

from django.contrib import admin
from django.db import models as django_models
from django.shortcuts import redirect
from django.utils import timezone
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin
from unfold.contrib.forms.widgets import WysiwygWidget
from unfold.datasets import BaseDataset
from unfold.decorators import action, display

from ..models import IPAddress, IPAddressObservation, MikroTikDevice, Subnet
from .formatters import _format_admin_last_seen, build_ip_overview_context
from .links import _admin_change_link, model_change_url, model_changelist_url, model_history_url
from .mixins import IPAddressTableMixin, TagDisplayMixin


class IPAddressDatasetAdmin(IPAddressTableMixin, ModelAdmin):
    """Dataset admin used inside subnet detail pages."""

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

        next_url = model_change_url(self, Subnet, subnet_id)
        return f'{url}?{urlencode({"next": next_url})}'


class IPAddressDataset(BaseDataset):
    """Unfold dataset descriptor for subnet-scoped IP rows."""

    model = IPAddress
    model_admin = IPAddressDatasetAdmin
    tab = False


@admin.register(IPAddress)
class IPAddressAdmin(TagDisplayMixin, IPAddressTableMixin, ModelAdmin):
    """IP admin with read-first overview and explicit edit mode."""

    change_form_outer_before_template = (
        'admin/ipam/ipaddress/change_form_outer_before.html'
    )
    actions_detail = (
        'edit_ip_settings',
        'back_to_subnet',
        'open_subnet_ip_list',
        'open_history',
        'back_to_ip_overview',
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
            _('Identità'),
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
            _('Note'),
            {
                'fields': (
                    'note',
                    'tags',
                ),
            },
        ),
        (
            _('Ultimi dati osservati'),
            {
                'fields': (
                    (
                        'latest_observed_hostname',
                        'latest_observed_mac_address',
                    ),
                    (
                        'latest_observed_lease',
                        'latest_observed_lease_comment',
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
        'Hostname e MAC sono dati canonici IPAM. '
        'Le colonne osservate mostrano gli ultimi dati DHCP/ARP.'
    )
    readonly_fields = (
        'latest_observed_hostname',
        'latest_observed_mac_address',
        'latest_observed_lease',
        'latest_observed_lease_comment',
        'latest_observed_at',
        'latest_observed_device',
    )

    def get_queryset(self, request):
        return super().get_queryset(request).select_related('subnet__vlan')

    def get_fieldsets(self, request, obj=None):
        """Hide editable fieldsets in default overview mode."""
        if obj is not None and self._is_overview_mode(request):
            return ()
        return super().get_fieldsets(request, obj=obj)

    def get_readonly_fields(self, request, obj=None):
        """Freeze model fields in overview mode while exposing derived fields."""
        if obj is not None and self._is_overview_mode(request):
            return (
                tuple(field.name for field in self.model._meta.fields)
                + (
                    'last_seen_display_readonly',
                    'latest_observed_hostname',
                    'latest_observed_mac_address',
                    'latest_observed_lease',
                    'latest_observed_lease_comment',
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
        """Swap primary action between overview and edit entrypoints."""
        action_names = [
            (
                'back_to_ip_overview'
                if not self._is_overview_mode(request)
                else 'edit_ip_settings'
            ),
            'back_to_subnet',
            'open_subnet_ip_list',
            'open_history',
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
        """Build template context for canonical and observed overview cards."""
        reachability_status = self._get_reachability_status(obj)
        latest_observation = self._get_latest_observation(obj)
        lease_kind = latest_observation.lease_kind if latest_observation else None

        return build_ip_overview_context(
            obj,
            subnet_change_url=model_change_url(self, Subnet, obj.subnet_id),
            reachability_status=reachability_status,
            lease_kind=lease_kind,
            latest_observed_hostname=self.latest_observed_hostname(obj),
            latest_observed_mac_address=self.latest_observed_mac_address(obj),
            latest_observed_lease=self.latest_observed_lease(obj),
            latest_observed_lease_comment=self.latest_observed_lease_comment(obj),
            latest_observed_at=self.latest_observed_at(obj),
            latest_observed_device=self.latest_observed_device(obj),
            tag_list=self._format_tag_list(obj),
        )

    def _is_overview_mode(self, request):
        """Default to overview mode unless ``mode=edit`` is requested."""
        return request.GET.get('mode') != 'edit'

    def _get_change_url(self, object_id):
        return model_change_url(self, self.model, object_id)

    def _get_subnet_change_url(self, subnet_id):
        return model_change_url(self, Subnet, subnet_id)

    def _get_subnet_ip_list_url(self, subnet_id):
        changelist_url = model_changelist_url(self, IPAddress)
        return f'{changelist_url}?{urlencode({"subnet__id__exact": subnet_id})}'

    def _get_history_url(self, object_id):
        return model_history_url(self, self.model, object_id)

    @action(description=_('Modifica IP'), icon='edit')
    def edit_ip_settings(self, request, object_id):
        return redirect(f'{self._get_change_url(object_id)}?mode=edit')

    @action(description=_('Panoramica IP'), icon='visibility')
    def back_to_ip_overview(self, request, object_id):
        return redirect(self._get_change_url(object_id))

    @action(description=_('Apri sottorete'), icon='account_tree')
    def back_to_subnet(self, request, object_id):
        next_url = request.GET.get('next')
        if next_url:
            return redirect(next_url)

        subnet_id = IPAddress.objects.filter(pk=object_id).values_list(
            'subnet_id',
            flat=True,
        ).first()
        if subnet_id is None:
            changelist_url = model_changelist_url(self, IPAddress)
            return redirect(changelist_url)
        return redirect(self._get_subnet_change_url(subnet_id))

    @action(description=_('Lista IP sottorete'), icon='table_view')
    def open_subnet_ip_list(self, request, object_id):
        subnet_id = IPAddress.objects.filter(pk=object_id).values_list(
            'subnet_id',
            flat=True,
        ).first()
        if subnet_id is None:
            changelist_url = model_changelist_url(self, IPAddress)
            return redirect(changelist_url)
        return redirect(self._get_subnet_ip_list_url(subnet_id))

    @action(description=_('Storia'), icon='history')
    def open_history(self, request, object_id):
        return redirect(self._get_history_url(object_id))

    @admin.display(description=_('Ultima rilevazione'))
    def last_seen_display_readonly(self, obj):
        return _format_admin_last_seen(obj.last_seen)

    def _get_latest_observation(self, obj):
        """Return and memoize latest observation for this object render cycle."""
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
        # Rendering references observed fields multiple times; cache one query.
        obj._latest_observation_cache = observation
        return observation

    @admin.display(description=_('Hostname osservato'))
    def latest_observed_hostname(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        return observation.observed_hostname or '-'

    @admin.display(description=_('MAC osservato'))
    def latest_observed_mac_address(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        return observation.observed_mac_address or '-'

    @admin.display(description=_('Osservato via'))
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

    @admin.display(description=_('Modalità lease'))
    def latest_observed_lease(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        lease_mode_labels = {
            IPAddress.LeaseKind.NONE: _('Statico'),
            IPAddress.LeaseKind.DHCP_DYNAMIC: _('Dinamico'),
            IPAddress.LeaseKind.DHCP_STATIC: _('DHCP lease statico'),
        }
        return lease_mode_labels.get(observation.lease_kind, '-')

    @admin.display(description=_('Commento lease'))
    def latest_observed_lease_comment(self, obj):
        """Expose lease comment from observation payload without mutating note."""
        observation = self._get_latest_observation(obj)
        if observation is None:
            return '-'
        payload = observation.payload_json if isinstance(observation.payload_json, dict) else {}
        comment = str(payload.get('lease_comment') or payload.get('comment') or '').strip()
        return comment or '-'

    @admin.display(description=_('Osservato il'))
    def latest_observed_at(self, obj):
        observation = self._get_latest_observation(obj)
        if observation is None or observation.last_observed_at is None:
            return '-'
        return timezone.localtime(observation.last_observed_at).strftime(
            '%Y-%m-%d %H:%M:%S'
        )

    @admin.display(description=_('Osservato da'))
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
