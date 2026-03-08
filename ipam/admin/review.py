"""
Admin view for the operational review queue.

Contains:
- read-oriented ModelAdmin for ``ReviewItem``
- queue actions that transition review status in bulk

Does not contain:
- review item creation/deduplication logic
- discovery or synchronization orchestration

Notes for maintainers:
- add-only is intentionally disabled; items come from runtime signals/jobs
- keep actions limited to status transitions used by operations
"""

from django.contrib import admin
from django.utils.translation import gettext_lazy as _

from unfold.admin import ModelAdmin

from ..models import ReviewItem


@admin.register(ReviewItem)
class ReviewItemAdmin(ModelAdmin):
    """Operational inbox for conflicts and newly observed entities."""

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
