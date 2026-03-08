from datetime import timedelta
from ipaddress import AddressValueError, NetmaskValueError, ip_address, ip_network

from django.conf import settings
from django.core.exceptions import ValidationError
from django.core.validators import MaxValueValidator, MinValueValidator
from django.db import models
from django.db.utils import OperationalError, ProgrammingError
from django.utils import timezone
from django.utils.translation import gettext_lazy as _


class SourceKind(models.TextChoices):
    """How an IPAM object was originally created."""
    MANUAL = 'manual', _('Manual')
    MIKROTIK = 'mikrotik', _('MikroTik')


class SyncState(models.TextChoices):
    """Lifecycle state of a synced object."""
    LIVE = 'live', _('Live')
    ORPHANED = 'orphaned', _('Orphaned')
    CONFLICT = 'conflict', _('Conflict')


def parse_cidr(value: str):
    return ip_network(value)


class Tag(models.Model):
    slug = models.SlugField(
        max_length=64,
        unique=True,
        verbose_name=_('Slug'),
        help_text=_('Stable unique identifier, for example "vpn".'),
    )
    name = models.CharField(
        max_length=100,
        verbose_name=_('Name'),
        help_text=_('Display label shown in the admin UI.'),
    )
    is_system = models.BooleanField(
        default=False,
        verbose_name=_('System tag'),
        help_text=_('System tags are predefined and protected from accidental deletion.'),
    )

    class Meta:
        ordering = ('name',)
        verbose_name = _('Tag')
        verbose_name_plural = _('Tags')

    def __str__(self):
        return self.name


class VLAN(models.Model):
    number = models.PositiveSmallIntegerField(
        unique=True,
        validators=[MinValueValidator(1), MaxValueValidator(4094)],
        verbose_name=_('Number'),
        help_text=_('Enter a VLAN ID between 1 and 4094.'),
    )
    name = models.CharField(
        max_length=100,
        blank=True,
        verbose_name=_('Name'),
        help_text=_('Add an optional display name for this VLAN.'),
    )

    # --- Provenance fields ---
    source_kind = models.CharField(
        max_length=16,
        choices=SourceKind.choices,
        default=SourceKind.MANUAL,
        verbose_name=_('Source'),
    )
    source_device = models.ForeignKey(
        'MikroTikDevice',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='imported_vlans',
        verbose_name=_('Source device'),
    )
    source_remote_id = models.CharField(
        max_length=255,
        blank=True,
        verbose_name=_('Source remote ID'),
        help_text=_('Opaque key from the upstream system.'),
    )
    source_job_id = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Source job ID'),
    )
    source_trace_id = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Source trace ID'),
    )
    last_synced_at = models.DateTimeField(
        null=True,
        blank=True,
        verbose_name=_('Last synced at'),
    )
    sync_state = models.CharField(
        max_length=16,
        choices=SyncState.choices,
        default=SyncState.LIVE,
        blank=True,
        verbose_name=_('Sync state'),
    )
    integration_sync_enabled = models.BooleanField(
        default=True,
        verbose_name=_('Integration sync enabled'),
        help_text=_('Disable to stop updating this VLAN from the upstream device.'),
    )
    source_upstream_disabled = models.BooleanField(
        default=False,
        verbose_name=_('Upstream disabled'),
        help_text=_('True when the upstream device reports this VLAN as disabled.'),
    )
    source_bridge_name = models.CharField(
        max_length=128,
        blank=True,
        verbose_name=_('Source bridge'),
        help_text=_('Bridge name from the upstream device, if applicable.'),
    )
    tags = models.ManyToManyField(
        Tag,
        blank=True,
        related_name='vlans',
        verbose_name=_('Tags'),
    )

    class Meta:
        ordering = ('number',)
        verbose_name = _('VLAN')
        verbose_name_plural = _('VLANs')

    def __str__(self):
        label = f'VLAN {self.number}'
        if self.name:
            label = f'{label} ({self.name})'
        return label


class IPAMSettings(models.Model):
    class WorkerMode(models.TextChoices):
        SEQUENTIAL = 'sequential', _('Sequential')
        PARALLEL = 'parallel', _('Parallel')

    DEFAULT_WARNING_AFTER_MINUTES = 1440
    DEFAULT_OFFLINE_AFTER_MINUTES = 10080
    DEFAULT_SCAN_INTERVAL_MINUTES = 60
    DEFAULT_WORKER_MODE = WorkerMode.SEQUENTIAL
    DEFAULT_WORKER_CONCURRENCY = 1
    DEFAULT_PING_TIMEOUT_MS = 1000
    DEFAULT_PING_CONCURRENCY = 64

    warning_after_minutes = models.PositiveIntegerField(
        default=DEFAULT_WARNING_AFTER_MINUTES,
        validators=[MinValueValidator(1)],
        verbose_name=_('Warning after (minutes)'),
        help_text=_('Mark an IP as stale after this many minutes without a response.'),
    )
    offline_after_minutes = models.PositiveIntegerField(
        default=DEFAULT_OFFLINE_AFTER_MINUTES,
        validators=[MinValueValidator(1)],
        verbose_name=_('Offline after (minutes)'),
        help_text=_('Mark an IP as offline after this many minutes without a response.'),
    )
    default_scan_interval_minutes = models.PositiveIntegerField(
        default=DEFAULT_SCAN_INTERVAL_MINUTES,
        validators=[MinValueValidator(1)],
        verbose_name=_('Default scan interval (minutes)'),
        help_text=_('Use this interval when a subnet does not override its scan frequency.'),
    )
    worker_mode = models.CharField(
        max_length=16,
        choices=WorkerMode.choices,
        default=DEFAULT_WORKER_MODE,
        verbose_name=_('Worker mode'),
        help_text=_('Choose whether the worker runs one scan at a time or multiple scans.'),
    )
    worker_concurrency = models.PositiveIntegerField(
        default=DEFAULT_WORKER_CONCURRENCY,
        validators=[MinValueValidator(1)],
        verbose_name=_('Worker concurrency'),
        help_text=_('Use this many worker processes when parallel mode is enabled.'),
    )
    ping_timeout_ms = models.PositiveIntegerField(
        default=DEFAULT_PING_TIMEOUT_MS,
        validators=[MinValueValidator(1)],
        verbose_name=_('Ping timeout (ms)'),
        help_text=_('Wait this many milliseconds for each ICMP response.'),
    )
    ping_concurrency = models.PositiveIntegerField(
        default=DEFAULT_PING_CONCURRENCY,
        validators=[MinValueValidator(1)],
        verbose_name=_('Ping concurrency'),
        help_text=_('Limit how many ping subprocesses run at once inside one scan job.'),
    )

    class Meta:
        verbose_name = _('IPAM settings')
        verbose_name_plural = _('IPAM settings')

    def __str__(self):
        return str(_('IPAM settings'))

    @classmethod
    def load(cls):
        # The admin enforces a single row because a dedicated singleton library
        # would add infrastructure complexity without improving this workflow.
        try:
            instance = cls.objects.order_by('pk').first()
        except (OperationalError, ProgrammingError):
            instance = None

        if instance is not None:
            return instance

        return cls(
            warning_after_minutes=cls.DEFAULT_WARNING_AFTER_MINUTES,
            offline_after_minutes=cls.DEFAULT_OFFLINE_AFTER_MINUTES,
            default_scan_interval_minutes=cls.DEFAULT_SCAN_INTERVAL_MINUTES,
            worker_mode=cls.DEFAULT_WORKER_MODE,
            worker_concurrency=cls.DEFAULT_WORKER_CONCURRENCY,
            ping_timeout_ms=cls.DEFAULT_PING_TIMEOUT_MS,
            ping_concurrency=cls.DEFAULT_PING_CONCURRENCY,
        )

    def clean(self):
        super().clean()

        if (
            self.warning_after_minutes
            and self.offline_after_minutes
            and self.offline_after_minutes <= self.warning_after_minutes
        ):
            raise ValidationError(
                {
                    'offline_after_minutes': _(
                        'Offline threshold must be greater than the warning threshold.'
                    )
                }
            )


class Subnet(models.Model):
    vlan = models.ForeignKey(
        VLAN,
        on_delete=models.PROTECT,
        null=True,
        blank=True,
        verbose_name=_('VLAN'),
        help_text=_('Select the VLAN associated with this subnet, if any.'),
    )
    parent = models.ForeignKey(
        'self',
        on_delete=models.PROTECT,
        null=True,
        blank=True,
        related_name='children',
        verbose_name=_('Parent subnet'),
        help_text=_('Select a parent subnet when this subnet is nested inside another one.'),
    )
    cidr = models.CharField(
        max_length=18,
        verbose_name=_('Subnet (CIDR)'),
        help_text=_('Enter an IPv4 subnet in CIDR notation, for example 10.0.0.0/24.'),
    )
    description = models.TextField(
        blank=True,
        verbose_name=_('Description'),
        help_text=_('Add optional notes about this subnet.'),
    )
    scan_enabled = models.BooleanField(
        default=False,
        verbose_name=_('Scan enabled'),
        help_text=_('Enable ICMP reachability checks for this subnet.'),
    )
    discover_subnet = models.BooleanField(
        default=False,
        verbose_name=_('Discovery enabled'),
        help_text=_('Enable discovery tasks for this subnet.'),
    )
    scan_interval_minutes = models.PositiveIntegerField(
        null=True,
        blank=True,
        validators=[MinValueValidator(1)],
        verbose_name=_('Scan interval (minutes)'),
        help_text=_('Override the global scan frequency for this subnet. Leave blank to use the global default.'),
    )
    warning_after_minutes_override = models.PositiveIntegerField(
        null=True,
        blank=True,
        validators=[MinValueValidator(1)],
        verbose_name=_('Warning after override (minutes)'),
        help_text=_('Override the global warning threshold for this subnet. Leave blank to use the global default.'),
    )
    offline_after_minutes_override = models.PositiveIntegerField(
        null=True,
        blank=True,
        validators=[MinValueValidator(1)],
        verbose_name=_('Offline after override (minutes)'),
        help_text=_('Override the global offline threshold for this subnet. Leave blank to use the global default.'),
    )

    # --- Provenance fields ---
    source_kind = models.CharField(
        max_length=16,
        choices=SourceKind.choices,
        default=SourceKind.MANUAL,
        verbose_name=_('Source'),
    )
    source_device = models.ForeignKey(
        'MikroTikDevice',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='imported_subnets',
        verbose_name=_('Source device'),
    )
    source_remote_id = models.CharField(
        max_length=255,
        blank=True,
        verbose_name=_('Source remote ID'),
    )
    source_job_id = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Source job ID'),
    )
    source_trace_id = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Source trace ID'),
    )
    last_synced_at = models.DateTimeField(
        null=True,
        blank=True,
        verbose_name=_('Last synced at'),
    )
    sync_state = models.CharField(
        max_length=16,
        choices=SyncState.choices,
        default=SyncState.LIVE,
        blank=True,
        verbose_name=_('Sync state'),
    )
    integration_sync_enabled = models.BooleanField(
        default=True,
        verbose_name=_('Integration sync enabled'),
        help_text=_('Disable to stop updating this subnet from the upstream device.'),
    )
    source_upstream_disabled = models.BooleanField(
        default=False,
        verbose_name=_('Upstream disabled'),
        help_text=_('True when the upstream device reports this subnet as disabled.'),
    )
    source_upstream_invalid = models.BooleanField(
        default=False,
        verbose_name=_('Upstream invalid'),
        help_text=_('True when the upstream device reports this subnet as invalid.'),
    )
    source_interface_name = models.CharField(
        max_length=128,
        blank=True,
        verbose_name=_('Source interface'),
        help_text=_('Interface name from the upstream device.'),
    )
    tags = models.ManyToManyField(
        Tag,
        blank=True,
        related_name='subnets',
        verbose_name=_('Tags'),
    )

    class Meta:
        ordering = ('cidr',)
        verbose_name = _('Subnet')
        verbose_name_plural = _('Subnets')

    def __str__(self):
        return self.cidr

    @property
    def network_object(self):
        return parse_cidr(self.cidr)

    def get_ipam_settings(self):
        return IPAMSettings.load()

    def get_effective_scan_interval_minutes(self):
        if self.scan_interval_minutes is not None:
            return self.scan_interval_minutes
        return self.get_ipam_settings().default_scan_interval_minutes

    def get_effective_thresholds(self):
        settings = self.get_ipam_settings()
        warning_after_minutes = (
            self.warning_after_minutes_override
            if self.warning_after_minutes_override is not None
            else settings.warning_after_minutes
        )
        offline_after_minutes = (
            self.offline_after_minutes_override
            if self.offline_after_minutes_override is not None
            else settings.offline_after_minutes
        )
        return warning_after_minutes, offline_after_minutes

    def clean(self):
        super().clean()

        if not self.cidr:
            return

        try:
            current_network = parse_cidr(self.cidr)
        except (AddressValueError, NetmaskValueError, ValueError):
            raise ValidationError(
                {
                    'cidr': _(
                        'Enter a valid IPv4 subnet in CIDR notation, for example '
                        '10.0.0.0/24.'
                    )
                }
            )

        self.cidr = str(current_network)

        if self.parent_id:
            if self.pk and self.parent_id == self.pk:
                raise ValidationError(
                    {'parent': _('A subnet cannot be its own parent.')}
                )

            parent_network = self.parent.network_object

            if not current_network.subnet_of(parent_network):
                raise ValidationError(
                    {
                        'parent': _(
                            'The child subnet must be contained in the parent subnet.'
                        )
                    }
                )

        if (
            self.warning_after_minutes_override
            and self.offline_after_minutes_override
            and self.offline_after_minutes_override <= self.warning_after_minutes_override
        ):
            raise ValidationError(
                {
                    'offline_after_minutes_override': _(
                        'Offline override must be greater than the warning override.'
                    )
                }
            )

        if self.scan_enabled:
            interval_minutes = self.get_effective_scan_interval_minutes()
            if not interval_minutes or interval_minutes <= 0:
                raise ValidationError(
                    {
                        'scan_interval_minutes': _(
                            'Scanning requires a valid interval in minutes.'
                        )
                    }
                )

            warning_after_minutes, offline_after_minutes = self.get_effective_thresholds()
            if offline_after_minutes <= warning_after_minutes:
                raise ValidationError(
                    _(
                        'The effective offline threshold must be greater than the '
                        'effective warning threshold.'
                    )
                )


class IPAddress(models.Model):
    class Status(models.TextChoices):
        IN_USE = 'in_use', _('In use')
        RESERVED = 'reserved', _('Reserved')
        DEPRECATED = 'deprecated', _('Deprecated')

    class LeaseKind(models.TextChoices):
        NONE = 'none', _('None')
        DHCP_DYNAMIC = 'dhcp_dynamic', _('DHCP dynamic')
        DHCP_STATIC = 'dhcp_static', _('DHCP static')

    subnet = models.ForeignKey(
        Subnet,
        on_delete=models.PROTECT,
        verbose_name=_('Subnet'),
        help_text=_('Choose the subnet that contains this IP address.'),
    )
    address = models.GenericIPAddressField(
        protocol='IPv4',
        verbose_name=_('IP address'),
        help_text=_('Enter an IPv4 address.'),
    )
    status = models.CharField(
        max_length=20,
        choices=Status.choices,
        default=Status.IN_USE,
        verbose_name=_('Status'),
        help_text=_('Controls allocation and scan handling.'),
    )
    hostname = models.CharField(
        max_length=255,
        blank=True,
        verbose_name=_('Hostname'),
        help_text=_('Add an optional DNS name or host label.'),
    )
    note = models.TextField(
        blank=True,
        verbose_name=_('Notes'),
        help_text=_('Add optional operational notes for this IP address.'),
    )
    mac_address = models.CharField(
        max_length=32,
        blank=True,
        verbose_name=_('MAC address'),
        help_text=_('Add an optional MAC address.'),
    )
    last_seen = models.DateTimeField(
        null=True,
        blank=True,
        editable=False,
        verbose_name=_('Last seen'),
        help_text=_('Record when this IP address was last observed.'),
    )
    exclude_ping = models.BooleanField(
        default=False,
        verbose_name=_('Exclude from ping'),
        help_text=_('Exclude this IP address from ping checks.'),
    )

    # --- Provenance fields ---
    source_kind = models.CharField(
        max_length=16,
        choices=SourceKind.choices,
        default=SourceKind.MANUAL,
        verbose_name=_('Source'),
    )
    source_device = models.ForeignKey(
        'MikroTikDevice',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='imported_ips',
        verbose_name=_('Source device'),
    )
    source_remote_id = models.CharField(
        max_length=255,
        blank=True,
        verbose_name=_('Source remote ID'),
    )
    source_job_id = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Source job ID'),
    )
    source_trace_id = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Source trace ID'),
    )
    source_observed_at = models.DateTimeField(
        null=True,
        blank=True,
        verbose_name=_('Source observed at'),
    )
    last_synced_at = models.DateTimeField(
        null=True,
        blank=True,
        verbose_name=_('Last synced at'),
    )
    sync_state = models.CharField(
        max_length=16,
        choices=SyncState.choices,
        default=SyncState.LIVE,
        blank=True,
        verbose_name=_('Sync state'),
    )

    # --- Lease fields (populated by DHCP sync) ---
    lease_kind = models.CharField(
        max_length=16,
        choices=LeaseKind.choices,
        default=LeaseKind.NONE,
        verbose_name=_('Lease kind'),
    )
    lease_status = models.CharField(
        max_length=32,
        blank=True,
        verbose_name=_('Lease status'),
        help_text=_('Raw lease status from the upstream DHCP server.'),
    )
    lease_server_name = models.CharField(
        max_length=128,
        blank=True,
        verbose_name=_('DHCP server'),
    )
    tags = models.ManyToManyField(
        Tag,
        blank=True,
        related_name='ip_addresses',
        verbose_name=_('Tags'),
    )

    class Meta:
        ordering = ('subnet__cidr', 'address')
        verbose_name = _('IP address')
        verbose_name_plural = _('IP addresses')
        constraints = [
            models.UniqueConstraint(
                fields=('subnet', 'address'),
                name='ipam_unique_ip_per_subnet',
            )
        ]

    def __str__(self):
        return self.address

    def clean(self):
        super().clean()

        if not self.subnet_id or not self.address:
            return

        subnet_network = self.subnet.network_object

        if ip_address(self.address) not in subnet_network:
            raise ValidationError(
                {'address': _('The IP address must belong to the selected subnet.')}
            )

    @property
    def reachability_window(self):
        warning_after_minutes, offline_after_minutes = self.subnet.get_effective_thresholds()
        return (
            timedelta(minutes=warning_after_minutes),
            timedelta(minutes=offline_after_minutes),
        )


class IntegrationVendor(models.TextChoices):
    MIKROTIK = 'mikrotik', _('MikroTik')


class IPAddressObservation(models.Model):
    integration_vendor = models.CharField(
        max_length=32,
        choices=IntegrationVendor.choices,
        default=IntegrationVendor.MIKROTIK,
        verbose_name=_('Integration vendor'),
    )
    mikrotik_device = models.ForeignKey(
        'MikroTikDevice',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='ip_observations',
        verbose_name=_('MikroTik device'),
    )
    subnet = models.ForeignKey(
        Subnet,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='ip_observations',
        verbose_name=_('Subnet'),
    )
    address = models.GenericIPAddressField(
        protocol='IPv4',
        verbose_name=_('IP address'),
    )
    observed_hostname = models.CharField(
        max_length=255,
        blank=True,
        verbose_name=_('Observed hostname'),
    )
    observed_mac_address = models.CharField(
        max_length=32,
        blank=True,
        verbose_name=_('Observed MAC address'),
    )
    lease_kind = models.CharField(
        max_length=16,
        choices=IPAddress.LeaseKind.choices,
        default=IPAddress.LeaseKind.NONE,
        verbose_name=_('Observed lease kind'),
    )
    lease_status = models.CharField(
        max_length=32,
        blank=True,
        verbose_name=_('Observed lease status'),
    )
    lease_server_name = models.CharField(
        max_length=128,
        blank=True,
        verbose_name=_('Observed DHCP server'),
    )
    observed_via_dhcp = models.BooleanField(
        default=False,
        verbose_name=_('Observed via DHCP'),
    )
    observed_via_arp = models.BooleanField(
        default=False,
        verbose_name=_('Observed via ARP'),
    )
    first_observed_at = models.DateTimeField(
        default=timezone.now,
        verbose_name=_('First observed at'),
    )
    last_observed_at = models.DateTimeField(
        default=timezone.now,
        verbose_name=_('Last observed at'),
    )
    payload_json = models.JSONField(
        default=dict,
        blank=True,
        verbose_name=_('Payload'),
    )

    class Meta:
        ordering = ('-last_observed_at', 'address')
        verbose_name = _('IP address observation')
        verbose_name_plural = _('IP address observations')
        constraints = [
            models.UniqueConstraint(
                fields=('integration_vendor', 'mikrotik_device', 'address'),
                name='ipam_ipobs_vendor_dev_addr_uniq',
            ),
        ]
        indexes = [
            models.Index(
                fields=('address', 'last_observed_at'),
                name='ipam_ipobs_addr_seen_idx',
            ),
        ]

    def __str__(self):
        return f'{self.address} ({self.integration_vendor})'


class ReviewItem(models.Model):
    class Kind(models.TextChoices):
        NEW_IP_OBSERVED = 'new_ip_observed', _('New IP observed')
        HOSTNAME_CONFLICT = 'hostname_conflict', _('Hostname conflict')
        MAC_CONFLICT = 'mac_conflict', _('MAC conflict')
        SYNC_FAILURE = 'sync_failure', _('Sync failure')
        DISCOVERY_CONFLICT = 'discovery_conflict', _('Discovery conflict')

    class Severity(models.TextChoices):
        INFO = 'info', _('Info')
        WARNING = 'warning', _('Warning')
        CRITICAL = 'critical', _('Critical')

    class Status(models.TextChoices):
        OPEN = 'open', _('Open')
        REVIEWED = 'reviewed', _('Reviewed')
        IGNORED = 'ignored', _('Ignored')

    kind = models.CharField(
        max_length=64,
        choices=Kind.choices,
        verbose_name=_('Kind'),
    )
    severity = models.CharField(
        max_length=16,
        choices=Severity.choices,
        default=Severity.INFO,
        verbose_name=_('Severity'),
    )
    status = models.CharField(
        max_length=16,
        choices=Status.choices,
        default=Status.OPEN,
        verbose_name=_('Status'),
    )
    title = models.CharField(
        max_length=200,
        verbose_name=_('Title'),
    )
    message = models.TextField(
        blank=True,
        verbose_name=_('Message'),
    )
    subnet = models.ForeignKey(
        Subnet,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='review_items',
        verbose_name=_('Subnet'),
    )
    ip_address = models.ForeignKey(
        IPAddress,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='review_items',
        verbose_name=_('IP address'),
    )
    mikrotik_device = models.ForeignKey(
        'MikroTikDevice',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='review_items',
        verbose_name=_('MikroTik device'),
    )
    dedupe_key = models.CharField(
        max_length=255,
        unique=True,
        verbose_name=_('Dedupe key'),
    )
    first_seen_at = models.DateTimeField(
        default=timezone.now,
        verbose_name=_('First seen at'),
    )
    last_seen_at = models.DateTimeField(
        default=timezone.now,
        verbose_name=_('Last seen at'),
    )
    payload_json = models.JSONField(
        default=dict,
        blank=True,
        verbose_name=_('Payload'),
    )

    class Meta:
        ordering = ('-last_seen_at', '-pk')
        verbose_name = _('Review item')
        verbose_name_plural = _('Review queue')
        indexes = [
            models.Index(
                fields=('status', 'severity', 'kind'),
                name='ipam_review_state_idx',
            ),
        ]

    def __str__(self):
        return self.title


def upsert_review_item(
    *,
    kind: str,
    severity: str,
    title: str,
    message: str,
    dedupe_key: str,
    subnet: Subnet | None = None,
    ip_address: IPAddress | None = None,
    mikrotik_device: 'MikroTikDevice | None' = None,
    payload_json: dict | None = None,
) -> tuple[ReviewItem, bool]:
    now = timezone.now()
    payload = payload_json or {}

    item, created = ReviewItem.objects.get_or_create(
        dedupe_key=dedupe_key,
        defaults={
            'kind': kind,
            'severity': severity,
            'status': ReviewItem.Status.OPEN,
            'title': title,
            'message': message,
            'subnet': subnet,
            'ip_address': ip_address,
            'mikrotik_device': mikrotik_device,
            'first_seen_at': now,
            'last_seen_at': now,
            'payload_json': payload,
        },
    )
    if created:
        return item, True

    # Repeated signals should refresh context and reopen the queue item.
    item.kind = kind
    item.severity = severity
    item.status = ReviewItem.Status.OPEN
    item.title = title
    item.message = message
    item.subnet = subnet
    item.ip_address = ip_address
    item.mikrotik_device = mikrotik_device
    item.last_seen_at = now
    item.payload_json = payload
    item.save(update_fields=[
        'kind',
        'severity',
        'status',
        'title',
        'message',
        'subnet',
        'ip_address',
        'mikrotik_device',
        'last_seen_at',
        'payload_json',
    ])
    return item, False


class JobRun(models.Model):
    class JobType(models.TextChoices):
        SUBNET_SCAN = 'subnet_scan', _('Subnet scan')

    class Status(models.TextChoices):
        SCHEDULED = 'scheduled', _('Scheduled')
        RUNNING = 'running', _('Running')
        SUCCESS = 'success', _('Success')
        FAILED = 'failed', _('Failed')
        SKIPPED_GUARDRAIL = 'skipped_guardrail', _('Skipped (guardrail)')
        SKIPPED_DISABLED = 'skipped_disabled', _('Skipped (disabled)')

    job_type = models.CharField(
        max_length=32,
        choices=JobType.choices,
        default=JobType.SUBNET_SCAN,
        verbose_name=_('Job type'),
    )
    subnet = models.ForeignKey(
        Subnet,
        on_delete=models.PROTECT,
        related_name='job_runs',
        verbose_name=_('Subnet'),
    )
    status = models.CharField(
        max_length=32,
        choices=Status.choices,
        default=Status.SCHEDULED,
        verbose_name=_('Status'),
    )
    scheduled_for = models.DateTimeField(verbose_name=_('Scheduled for'))
    started_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Started at'))
    finished_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Finished at'))
    hosts_total = models.PositiveIntegerField(default=0, verbose_name=_('Hosts total'))
    hosts_pinged = models.PositiveIntegerField(default=0, verbose_name=_('Hosts pinged'))
    hosts_alive = models.PositiveIntegerField(default=0, verbose_name=_('Hosts alive'))
    ip_records_created = models.PositiveIntegerField(default=0, verbose_name=_('IP records created'))
    ip_records_updated = models.PositiveIntegerField(default=0, verbose_name=_('IP records updated'))
    error_message = models.TextField(blank=True, verbose_name=_('Error message'))
    task_ref = models.CharField(max_length=64, blank=True, db_index=True, verbose_name=_('Task reference'))

    class Meta:
        ordering = ('-scheduled_for', '-pk')
        verbose_name = _('Job run')
        verbose_name_plural = _('Job runs')
        constraints = [
            models.UniqueConstraint(
                fields=('job_type', 'subnet', 'scheduled_for'),
                name='ipam_unique_jobrun_slot',
            ),
        ]
        indexes = [
            models.Index(
                fields=('status', 'scheduled_for'),
                name='ipam_jobrun_status_02a4bc_idx',
            ),
            models.Index(
                fields=('subnet', 'scheduled_for'),
                name='ipam_jobrun_subnet__7ef0a9_idx',
            ),
        ]

    def __str__(self):
        return str(_('%(job_type)s for %(subnet)s at %(when)s')) % {
            'job_type': self.get_job_type_display(),
            'subnet': self.subnet,
            'when': timezone.localtime(self.scheduled_for),
        }

    @property
    def duration(self):
        if not self.started_at:
            return None

        end_time = self.finished_at or timezone.now()
        return end_time - self.started_at


class MikroTikScheme(models.TextChoices):
    HTTPS = 'https', _('HTTPS')
    HTTP = 'http', _('HTTP')


class MikroTikIntegrationSettings(models.Model):
    DEFAULT_RUN_RETENTION_DAYS = 30

    username = models.CharField(
        max_length=128,
        verbose_name=_('Username'),
        help_text=_('Global username used for MikroTik discovery and synchronization.'),
    )
    password_encrypted = models.BinaryField(
        editable=False,
        blank=True,
        verbose_name=_('Password (encrypted)'),
    )
    run_retention_days = models.PositiveIntegerField(
        default=DEFAULT_RUN_RETENTION_DAYS,
        validators=[MinValueValidator(1)],
        verbose_name=_('Run retention (days)'),
        help_text=_('Delete completed MikroTik discovery and sync history older than this value.'),
    )

    class Meta:
        verbose_name = _('MikroTik settings')
        verbose_name_plural = _('MikroTik settings')

    def __str__(self):
        return str(_('MikroTik settings'))

    @classmethod
    def load(cls):
        try:
            instance = cls.objects.order_by('pk').first()
        except (OperationalError, ProgrammingError):
            instance = None

        if instance is not None:
            return instance

        return cls(run_retention_days=cls.DEFAULT_RUN_RETENTION_DAYS)


class MikroTikDevice(models.Model):
    """Post-onboarding MikroTik RouterOS v7 device settings and state."""

    name = models.CharField(
        max_length=128,
        blank=True,
        verbose_name=_('Name'),
        help_text=_('Display name. If empty, discovery populates it from RouterOS identity.'),
    )
    host = models.GenericIPAddressField(
        protocol='IPv4',
        unique=True,
        verbose_name=_('Host / IP'),
        help_text=_('IPv4 address of the RouterOS endpoint.'),
    )
    port = models.PositiveIntegerField(
        default=443,
        validators=[MinValueValidator(1), MaxValueValidator(65535)],
        verbose_name=_('Port'),
    )
    scheme = models.CharField(
        max_length=8,
        choices=MikroTikScheme.choices,
        default=MikroTikScheme.HTTPS,
        verbose_name=_('Scheme'),
    )
    verify_tls = models.BooleanField(
        default=False,
        verbose_name=_('Verify TLS'),
        help_text=_('Enable strict TLS certificate verification.'),
    )
    enabled = models.BooleanField(
        default=True,
        verbose_name=_('Enabled'),
    )
    sync_enabled = models.BooleanField(
        default=True,
        verbose_name=_('Periodic sync'),
        help_text=_('Enable automatic periodic synchronization.'),
    )
    sync_interval_minutes = models.PositiveIntegerField(
        default=15,
        validators=[MinValueValidator(1)],
        verbose_name=_('Sync interval (minutes)'),
    )
    import_vlans = models.BooleanField(default=True, verbose_name=_('Import VLANs'))
    import_subnets = models.BooleanField(default=True, verbose_name=_('Import subnets'))
    import_dhcp_leases = models.BooleanField(default=True, verbose_name=_('Import DHCP leases'))
    import_arp = models.BooleanField(default=True, verbose_name=_('Import ARP'))
    import_neighbors = models.BooleanField(default=True, verbose_name=_('Import neighbors'))
    ignore_dynamic_bridge_vlans = models.BooleanField(
        default=True,
        verbose_name=_('Ignore dynamic bridge VLANs'),
        help_text=_('Skip bridge VLAN rows where dynamic=true.'),
    )
    is_authoritative_for_l3 = models.BooleanField(
        default=True,
        verbose_name=_('Authoritative for L3'),
        help_text=_('If true, sync will create/update subnets from this device.'),
    )
    is_authoritative_for_dhcp = models.BooleanField(
        default=True,
        verbose_name=_('Authoritative for DHCP'),
        help_text=_('If true, sync will import DHCP lease data from this device.'),
    )
    last_successful_sync_at = models.DateTimeField(
        null=True,
        blank=True,
        verbose_name=_('Last successful sync'),
    )
    last_error = models.TextField(blank=True, verbose_name=_('Last error'))
    last_seen_identity = models.CharField(max_length=128, blank=True, verbose_name=_('Identity'))
    last_seen_version = models.CharField(max_length=64, blank=True, verbose_name=_('RouterOS version'))
    last_seen_board = models.CharField(max_length=128, blank=True, verbose_name=_('Board'))
    last_seen_platform = models.CharField(max_length=128, blank=True, verbose_name=_('Platform'))

    class Meta:
        ordering = ('host',)
        verbose_name = _('MikroTik device')
        verbose_name_plural = _('MikroTik devices')

    def __str__(self):
        return self.name or self.host

    @property
    def base_url(self) -> str:
        return f'{self.scheme}://{self.host}:{self.port}'


class MikroTikTarget(models.Model):
    class LastStatus(models.TextChoices):
        SCHEDULED = 'scheduled', _('Scheduled')
        RUNNING = 'running', _('Running')
        SUCCESS = 'success', _('Success')
        FAILED = 'failed', _('Failed')
        BLOCKED = 'blocked', _('Blocked')

    host = models.GenericIPAddressField(
        protocol='IPv4',
        unique=True,
        verbose_name=_('Host / IP'),
        help_text=_('One IPv4 endpoint per target.'),
    )
    scheme = models.CharField(
        max_length=8,
        choices=MikroTikScheme.choices,
        default=MikroTikScheme.HTTPS,
        verbose_name=_('Scheme'),
    )
    port = models.PositiveIntegerField(
        default=443,
        validators=[MinValueValidator(1), MaxValueValidator(65535)],
        verbose_name=_('Port'),
    )
    verify_tls = models.BooleanField(
        default=False,
        verbose_name=_('Verify TLS'),
    )
    linked_device = models.OneToOneField(
        MikroTikDevice,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='linked_target',
        verbose_name=_('Linked device'),
    )
    last_discovered_at = models.DateTimeField(
        null=True,
        blank=True,
        verbose_name=_('Last discovered at'),
    )
    last_discovery_status = models.CharField(
        max_length=16,
        choices=LastStatus.choices,
        blank=True,
        verbose_name=_('Last discovery status'),
    )
    last_reason_code = models.CharField(
        max_length=64,
        blank=True,
        verbose_name=_('Last reason code'),
    )
    last_message = models.TextField(
        blank=True,
        verbose_name=_('Last message'),
    )
    last_seen_identity = models.CharField(max_length=128, blank=True, verbose_name=_('Last seen identity'))
    last_seen_version = models.CharField(max_length=64, blank=True, verbose_name=_('Last seen version'))
    last_seen_board = models.CharField(max_length=128, blank=True, verbose_name=_('Last seen board'))
    last_seen_platform = models.CharField(max_length=128, blank=True, verbose_name=_('Last seen platform'))

    class Meta:
        ordering = ('host',)
        verbose_name = _('MikroTik target')
        verbose_name_plural = _('MikroTik targets')

    def __str__(self):
        return self.host

    def save(self, *args, **kwargs):
        if self.linked_device_id:
            linked_host = None
            if hasattr(self, 'linked_device') and self.linked_device is not None:
                linked_host = self.linked_device.host
            elif self.pk:
                linked_host = (
                    MikroTikDevice.objects.filter(pk=self.linked_device_id)
                    .values_list('host', flat=True)
                    .first()
                )
            if linked_host and linked_host != self.host:
                self.linked_device = None
        super().save(*args, **kwargs)

    @property
    def base_url(self) -> str:
        return f'{self.scheme}://{self.host}:{self.port}'


class MikroTikDiscoveryRun(models.Model):
    class Status(models.TextChoices):
        SCHEDULED = 'scheduled', _('Scheduled')
        RUNNING = 'running', _('Running')
        SUCCESS = 'success', _('Success')
        PARTIAL = 'partial', _('Partial')
        FAILED = 'failed', _('Failed')

    status = models.CharField(
        max_length=16,
        choices=Status.choices,
        default=Status.SCHEDULED,
        verbose_name=_('Status'),
    )
    created_at = models.DateTimeField(auto_now_add=True, verbose_name=_('Created at'))
    started_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Started at'))
    finished_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Finished at'))
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='mikrotik_discovery_runs',
        verbose_name=_('Created by'),
    )
    total_targets = models.PositiveIntegerField(default=0, verbose_name=_('Total targets'))
    success_count = models.PositiveIntegerField(default=0, verbose_name=_('Success count'))
    failed_count = models.PositiveIntegerField(default=0, verbose_name=_('Failed count'))
    blocked_count = models.PositiveIntegerField(default=0, verbose_name=_('Blocked count'))
    created_count = models.PositiveIntegerField(default=0, verbose_name=_('Created count'))
    updated_count = models.PositiveIntegerField(default=0, verbose_name=_('Updated count'))
    reused_count = models.PositiveIntegerField(default=0, verbose_name=_('Reused count'))
    task_ref = models.CharField(
        max_length=64,
        blank=True,
        db_index=True,
        verbose_name=_('Task reference'),
    )

    class Meta:
        ordering = ('-created_at', '-pk')
        verbose_name = _('MikroTik discovery run')
        verbose_name_plural = _('MikroTik discovery runs')

    def __str__(self):
        return f'Discovery run #{self.pk}'


class MikroTikDiscoveryResult(models.Model):
    class Status(models.TextChoices):
        SCHEDULED = 'scheduled', _('Scheduled')
        RUNNING = 'running', _('Running')
        SUCCESS = 'success', _('Success')
        FAILED = 'failed', _('Failed')
        BLOCKED = 'blocked', _('Blocked')

    class ReasonCode(models.TextChoices):
        TIMEOUT = 'timeout', _('Timeout')
        CONNECTION_ERROR = 'connection_error', _('Connection error')
        CONNECTION_REFUSED = 'connection_refused', _('Connection refused')
        TLS_ERROR = 'tls_error', _('TLS error')
        AUTH_FAILED = 'auth_failed', _('Authentication failed')
        FORBIDDEN = 'forbidden', _('Forbidden')
        HTTP_ERROR = 'http_error', _('HTTP error')
        UNSUPPORTED_ROUTEROS = 'unsupported_routeros', _('Unsupported RouterOS')
        IDENTITY_CONFLICT = 'identity_conflict', _('Identity conflict')
        INVALID_TARGET = 'invalid_target', _('Invalid target')
        UNEXPECTED_ERROR = 'unexpected_error', _('Unexpected error')

    class ActionTaken(models.TextChoices):
        NONE = 'none', _('None')
        CREATED_DEVICE = 'created_device', _('Created device')
        UPDATED_EXISTING_DEVICE = 'updated_existing_device', _('Updated existing device')
        REUSED_EXISTING_DEVICE = 'reused_existing_device', _('Reused existing device')
        BLOCKED_MANUAL_RESOLUTION = 'blocked_manual_resolution', _('Blocked (manual resolution)')

    run = models.ForeignKey(
        MikroTikDiscoveryRun,
        on_delete=models.CASCADE,
        related_name='results',
        verbose_name=_('Run'),
    )
    target = models.ForeignKey(
        MikroTikTarget,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='discovery_results',
        verbose_name=_('Target'),
    )
    status = models.CharField(
        max_length=16,
        choices=Status.choices,
        default=Status.SCHEDULED,
        verbose_name=_('Status'),
    )
    reason_code = models.CharField(
        max_length=64,
        choices=ReasonCode.choices,
        blank=True,
        verbose_name=_('Reason code'),
    )
    detail_message = models.TextField(blank=True, verbose_name=_('Detail message'))
    seen_identity = models.CharField(max_length=128, blank=True, verbose_name=_('Seen identity'))
    seen_version = models.CharField(max_length=64, blank=True, verbose_name=_('Seen version'))
    seen_board = models.CharField(max_length=128, blank=True, verbose_name=_('Seen board'))
    seen_platform = models.CharField(max_length=128, blank=True, verbose_name=_('Seen platform'))
    action_taken = models.CharField(
        max_length=64,
        choices=ActionTaken.choices,
        default=ActionTaken.NONE,
        verbose_name=_('Action taken'),
    )
    linked_device = models.ForeignKey(
        MikroTikDevice,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='discovery_linked_results',
        verbose_name=_('Linked device'),
    )
    conflicting_device = models.ForeignKey(
        MikroTikDevice,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='discovery_conflicts',
        verbose_name=_('Conflicting device'),
    )
    created_at = models.DateTimeField(auto_now_add=True, verbose_name=_('Created at'))
    started_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Started at'))
    finished_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Finished at'))

    class Meta:
        ordering = ('run_id', 'pk')
        verbose_name = _('MikroTik discovery result')
        verbose_name_plural = _('MikroTik discovery results')
        constraints = [
            models.UniqueConstraint(
                fields=('run', 'target'),
                name='ipam_mikrotik_discovery_result_unique_target_per_run',
            ),
        ]

    def __str__(self):
        target_label = self.target.host if self.target_id and self.target else _('Deleted target')
        return f'{target_label} [{self.get_status_display()}]'


class MikroTikSyncRun(models.Model):
    class Status(models.TextChoices):
        SCHEDULED = 'scheduled', _('Scheduled')
        RUNNING = 'running', _('Running')
        SUCCESS = 'success', _('Success')
        FAILED = 'failed', _('Failed')

    device = models.ForeignKey(
        MikroTikDevice,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='sync_runs',
        verbose_name=_('Device'),
    )
    status = models.CharField(
        max_length=16,
        choices=Status.choices,
        default=Status.SCHEDULED,
        verbose_name=_('Status'),
    )
    scheduled_for = models.DateTimeField(verbose_name=_('Scheduled for'))
    started_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Started at'))
    finished_at = models.DateTimeField(null=True, blank=True, verbose_name=_('Finished at'))
    trace_id = models.CharField(max_length=64, blank=True, db_index=True, verbose_name=_('Trace ID'))
    job_id = models.CharField(max_length=64, blank=True, verbose_name=_('Job ID'))
    vlans_created = models.PositiveIntegerField(default=0, verbose_name=_('VLANs created'))
    vlans_updated = models.PositiveIntegerField(default=0, verbose_name=_('VLANs updated'))
    subnets_created = models.PositiveIntegerField(default=0, verbose_name=_('Subnets created'))
    subnets_updated = models.PositiveIntegerField(default=0, verbose_name=_('Subnets updated'))
    ips_created = models.PositiveIntegerField(default=0, verbose_name=_('IPs created'))
    ips_updated = models.PositiveIntegerField(default=0, verbose_name=_('IPs updated'))
    warning_summary = models.JSONField(default=list, blank=True, verbose_name=_('Warnings'))
    observed_summary = models.JSONField(
        default=dict,
        blank=True,
        verbose_name=_('Observed summary'),
        help_text=_('Normalized neighbor/ARP summary for diagnostics.'),
    )
    error_message = models.TextField(blank=True, verbose_name=_('Error message'))
    task_ref = models.CharField(
        max_length=64,
        blank=True,
        db_index=True,
        verbose_name=_('Task reference'),
    )

    class Meta:
        ordering = ('-scheduled_for', '-pk')
        verbose_name = _('MikroTik sync run')
        verbose_name_plural = _('MikroTik sync runs')

    def __str__(self):
        device_label = self.device if self.device_id else _('Deleted device')
        return f'{device_label} @ {self.scheduled_for}'

    @property
    def duration(self):
        if not self.started_at:
            return None
        end_time = self.finished_at or timezone.now()
        return end_time - self.started_at


class MikroTikVLAN(VLAN):
    class Meta:
        proxy = True
        verbose_name = _('MikroTik VLAN')
        verbose_name_plural = _('MikroTik VLANs')


class MikroTikSubnet(Subnet):
    class Meta:
        proxy = True
        verbose_name = _('MikroTik subnet')
        verbose_name_plural = _('MikroTik subnets')
