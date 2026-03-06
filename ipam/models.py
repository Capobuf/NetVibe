from datetime import timedelta
from ipaddress import AddressValueError, NetmaskValueError, ip_address, ip_network

from django.core.exceptions import ValidationError
from django.core.validators import MaxValueValidator, MinValueValidator
from django.db import models
from django.db.utils import OperationalError, ProgrammingError
from django.utils import timezone
from django.utils.translation import gettext_lazy as _


def parse_cidr(value: str):
    return ip_network(value)


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
