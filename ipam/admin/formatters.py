"""
Pure formatting and context-construction helpers for IPAM admin UI.

Contains:
- display formatters for timestamps, VLAN labels, and counter summaries
- context builders for subnet and IP overview templates
- shared UI mapping dictionaries kept out of ModelAdmin classes

Does not contain:
- model registration or admin permission logic
- URL reversing/navigation helpers

Notes for maintainers:
- keep helpers side-effect free and deterministic
- avoid DB access here; query orchestration belongs to admin modules
"""

from datetime import timedelta

from django.utils import timezone
from django.utils.html import format_html, strip_tags
from django.utils.text import Truncator
from django.utils.translation import gettext_lazy as _


def _format_vlan_read_label(vlan, *, empty_label='-'):
    """Format VLAN labels with name-first fallback to numeric identifier."""
    if vlan is None:
        return empty_label
    return vlan.name or (_('VLAN %(number)s') % {'number': vlan.number})


def _format_admin_last_seen(value):
    """Render compact operational datetime labels used in admin tables."""
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


def format_tag_list(obj):
    """Render a compact tag list with overflow indicator."""
    tag_names = [tag.name for tag in obj.tags.all()]
    if not tag_names:
        return '-'
    visible_names = tag_names[:3]
    hidden_count = len(tag_names) - len(visible_names)
    if hidden_count > 0:
        return f"{', '.join(visible_names)} +{hidden_count}"
    return ', '.join(visible_names)


def build_ip_overview_context(
    obj,
    *,
    subnet_change_url,
    reachability_status,
    lease_kind,
    latest_observed_hostname,
    latest_observed_mac_address,
    latest_observed_lease,
    latest_observed_lease_comment,
    latest_observed_at,
    latest_observed_device,
    tag_list,
):
    """Build template context for IP detail overview cards."""
    reachability_labels = {
        'online': _('Online'),
        'offline': _('Offline'),
        'unknown': _('Sconosciuta'),
    }
    ip_status_labels = {
        obj.Status.IN_USE: _('In uso'),
        obj.Status.RESERVED: _('Riservato'),
        obj.Status.DEPRECATED: _('Deprecato'),
    }
    badge_classes = {
        'success': (
            'border-green-200 bg-green-50 text-green-800 '
            'dark:border-green-700 dark:bg-green-900/30 dark:text-green-300'
        ),
        'warning': (
            'border-amber-200 bg-amber-50 text-amber-800 '
            'dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
        ),
        'danger': (
            'border-red-200 bg-red-50 text-red-800 '
            'dark:border-red-700 dark:bg-red-900/30 dark:text-red-300'
        ),
        'neutral': (
            'border-gray-200 bg-gray-50 text-gray-800 '
            'dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-300'
        ),
    }
    vlan_label = _format_vlan_read_label(
        obj.subnet.vlan if obj.subnet.vlan_id else None,
        empty_label=str(_('Nessuna VLAN assegnata')),
    )
    note = strip_tags(obj.note or '').strip()

    return {
        'ip_overview_header': {
            'ip_address': obj.address,
            'context_items': [
                {
                    'label': _('Sottorete'),
                    'value': format_html(
                        '<a href="{}">{}</a>',
                        subnet_change_url,
                        obj.subnet.cidr,
                    ),
                },
                {'label': _('VLAN'), 'value': vlan_label},
            ],
            'badges': [
                {
                    'label': _('Raggiungibilità'),
                    'value': reachability_labels[reachability_status],
                    'class': badge_classes.get(
                        {
                            'online': 'success',
                            'offline': 'danger',
                            'unknown': 'warning',
                        }.get(reachability_status, 'neutral'),
                        badge_classes['neutral'],
                    ),
                },
                {
                    'label': _('Stato IP'),
                    'value': ip_status_labels.get(obj.status, obj.get_status_display()),
                    'class': badge_classes.get(
                        {
                            obj.Status.IN_USE: 'success',
                            obj.Status.RESERVED: 'warning',
                            obj.Status.DEPRECATED: 'neutral',
                        }.get(obj.status, 'neutral'),
                        badge_classes['neutral'],
                    ),
                },
                {
                    'label': _('Modalità lease'),
                    'value': latest_observed_lease,
                    'class': badge_classes.get(
                        {
                            obj.LeaseKind.NONE: 'neutral',
                            obj.LeaseKind.DHCP_DYNAMIC: 'warning',
                            obj.LeaseKind.DHCP_STATIC: 'success',
                        }.get(lease_kind, 'neutral'),
                        badge_classes['neutral'],
                    ),
                },
            ],
        },
        'ip_overview_primary_cards': [
            {
                'title': _('Dati IPAM'),
                'details': [
                    {'label': _('Hostname canonico'), 'value': obj.hostname or '-'},
                    {'label': _('MAC canonico'), 'value': obj.mac_address or '-'},
                    {
                        'label': _('Note'),
                        'value': Truncator(note).chars(120) if note else '-',
                    },
                    {'label': _('Tag'), 'value': tag_list},
                ],
            },
            {
                'title': _('Ultimi dati osservati'),
                'details': [
                    {
                        'label': _('Hostname osservato'),
                        'value': latest_observed_hostname,
                    },
                    {'label': _('MAC osservato'), 'value': latest_observed_mac_address},
                    {'label': _('Modalità lease'), 'value': latest_observed_lease},
                    {
                        'label': _('Commento lease'),
                        'value': latest_observed_lease_comment,
                    },
                    {'label': _('Osservato il'), 'value': latest_observed_at},
                    {'label': _('Osservato da'), 'value': latest_observed_device},
                ],
            },
        ],
        'ip_overview_operational_card': {
            'title': _('Stato operativo'),
            'details': [
                {'label': _('Raggiungibilità'), 'value': reachability_labels[reachability_status]},
                {'label': _('Ultima rilevazione'), 'value': _format_admin_last_seen(obj.last_seen)},
                {
                    'label': _('Escluso dal ping'),
                    'value': _('Sì') if obj.exclude_ping else _('No'),
                },
                {'label': _('Stato IP'), 'value': ip_status_labels.get(obj.status, obj.get_status_display())},
            ],
        },
    }


def build_subnet_overview_context(obj, ip_addresses, ip_status_choices):
    """Build template context for subnet overview summary cards."""
    status_labels = dict(ip_status_choices)
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


def format_job_duration(duration):
    """Format a duration as ``H:MM:SS`` or ``M:SS`` for list displays."""
    if duration is None:
        return '-'
    total_seconds = int(duration.total_seconds())
    minutes, seconds = divmod(total_seconds, 60)
    hours, minutes = divmod(minutes, 60)
    if hours:
        return f'{hours:d}:{minutes:02d}:{seconds:02d}'
    return f'{minutes:d}:{seconds:02d}'


def _admin_datetime(value):
    """Format naive/aware datetimes for compact admin rendering."""
    if value is None:
        return '-'
    if timezone.is_naive(value):
        return value.strftime('%Y-%m-%d %H:%M:%S')
    return timezone.localtime(value).strftime('%Y-%m-%d %H:%M:%S')


def format_sync_counters(sync_run):
    """Format per-entity create/update counters for sync run rows."""
    parts = []
    if sync_run.vlans_created or sync_run.vlans_updated:
        parts.append(f'VLANs +{sync_run.vlans_created}/~{sync_run.vlans_updated}')
    if sync_run.subnets_created or sync_run.subnets_updated:
        parts.append(f'Subnets +{sync_run.subnets_created}/~{sync_run.subnets_updated}')
    if sync_run.ips_created or sync_run.ips_updated:
        parts.append(f'IPs +{sync_run.ips_created}/~{sync_run.ips_updated}')
    return ' | '.join(parts) if parts else '-'
