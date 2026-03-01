from ipaddress import AddressValueError, NetmaskValueError, ip_address, ip_network

from django.core.exceptions import ValidationError
from django.core.validators import MaxValueValidator, MinValueValidator
from django.db import models
from django.utils.translation import gettext_lazy as _


def parse_ipv4_cidr(value: str):
    return ip_network(value, strict=True)


class Section(models.Model):
    name = models.CharField(
        max_length=100,
        unique=True,
        help_text=_('Enter a unique name for this section.'),
    )
    strict_mode = models.BooleanField(
        default=False,
        help_text=_('Prevent overlapping subnets within this section.'),
    )

    class Meta:
        ordering = ('name',)

    def __str__(self):
        return self.name


class VLAN(models.Model):
    number = models.PositiveSmallIntegerField(
        unique=True,
        validators=[MinValueValidator(1), MaxValueValidator(4094)],
        help_text=_('Enter a VLAN ID between 1 and 4094.'),
    )
    name = models.CharField(
        max_length=100,
        blank=True,
        help_text=_('Add an optional display name for this VLAN.'),
    )

    class Meta:
        ordering = ('number',)

    def __str__(self):
        label = f'VLAN {self.number}'
        if self.name:
            label = f'{label} ({self.name})'
        return label


class Subnet(models.Model):
    section = models.ForeignKey(
        Section,
        on_delete=models.PROTECT,
        help_text=_('Choose the section that owns this subnet.'),
    )
    vlan = models.ForeignKey(
        VLAN,
        on_delete=models.PROTECT,
        null=True,
        blank=True,
        help_text=_('Select the VLAN associated with this subnet, if any.'),
    )
    parent = models.ForeignKey(
        'self',
        on_delete=models.PROTECT,
        null=True,
        blank=True,
        related_name='children',
        help_text=_('Select a parent subnet when this subnet is nested inside another one.'),
    )
    cidr = models.CharField(
        max_length=18,
        verbose_name=_('Subnet (CIDR)'),
        help_text=_('Enter an IPv4 subnet in CIDR notation, for example 10.0.0.0/24.'),
    )
    description = models.TextField(
        blank=True,
        help_text=_('Add optional notes about this subnet.'),
    )
    ping_subnet = models.BooleanField(
        default=False,
        help_text=_('Enable ICMP reachability checks for this subnet.'),
    )
    discover_subnet = models.BooleanField(
        default=False,
        help_text=_('Enable discovery tasks for this subnet.'),
    )

    class Meta:
        ordering = ('section__name', 'cidr')

    def __str__(self):
        return self.cidr

    @property
    def network_object(self):
        return parse_ipv4_cidr(self.cidr)

    def clean(self):
        super().clean()

        if not self.cidr:
            return

        try:
            current_network = parse_ipv4_cidr(self.cidr)
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

        if self.section_id and self.section.strict_mode:
            overlap_qs = Subnet.objects.filter(section=self.section).exclude(pk=self.pk)

            for candidate in overlap_qs:
                candidate_network = candidate.network_object
                if current_network.overlaps(candidate_network):
                    raise ValidationError(
                        _(
                            'Overlapping subnets are not allowed in strict mode '
                            'within the same section.'
                        )
                    )


class IPAddress(models.Model):
    class State(models.TextChoices):
        ACTIVE = 'active', _('Active')
        RESERVED = 'reserved', _('Reserved')
        DEPRECATED = 'deprecated', _('Deprecated')

    subnet = models.ForeignKey(
        Subnet,
        on_delete=models.PROTECT,
        help_text=_('Choose the subnet that contains this IP address.'),
    )
    address = models.GenericIPAddressField(
        protocol='IPv4',
        help_text=_('Enter an IPv4 address.'),
    )
    state = models.CharField(
        max_length=20,
        choices=State.choices,
        default=State.ACTIVE,
        help_text=_('Set the lifecycle state for this IP address.'),
    )
    hostname = models.CharField(
        max_length=255,
        blank=True,
        help_text=_('Add an optional DNS name or host label.'),
    )
    description = models.TextField(
        blank=True,
        help_text=_('Add optional notes about this IP address.'),
    )
    note = models.TextField(
        blank=True,
        help_text=_('Store any operator note for this IP address.'),
    )
    mac_address = models.CharField(
        max_length=32,
        blank=True,
        help_text=_('Add an optional MAC address.'),
    )
    owner = models.CharField(
        max_length=255,
        blank=True,
        help_text=_('Record the person or system responsible for this IP address.'),
    )
    last_seen = models.DateTimeField(
        null=True,
        blank=True,
        help_text=_('Record when this IP address was last observed.'),
    )
    exclude_ping = models.BooleanField(
        default=False,
        help_text=_('Exclude this IP address from ping checks.'),
    )

    class Meta:
        ordering = ('subnet__cidr', 'address')
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
