"""Admin forms for the IPAM app.

The ``MikroTikDeviceForm`` handles the write-only password input:
- On creation: the password is required and encrypted before save.
- On edit:     blank means "keep existing"; a value means "replace".
"""

from django import forms
from django.utils.translation import gettext_lazy as _

from .crypto import encrypt_password
from .models import MikroTikDevice


class MikroTikDeviceForm(forms.ModelForm):
    """Custom form that adds a write-only password field.

    The real ``password_encrypted`` BinaryField is never exposed.
    Instead, this form presents a plain ``password`` CharField whose
    value is encrypted on save and stored into ``password_encrypted``.
    """

    password = forms.CharField(
        widget=forms.PasswordInput(render_value=False),
        required=False,
        label=_('Password'),
        help_text=_('Leave blank to keep the existing password.'),
    )
    custom_port = forms.BooleanField(
        required=False,
        label=_('Custom port'),
        help_text=_('Enable to manually set a port different from the default for the selected scheme.'),
    )
    is_gateway_router = forms.BooleanField(
        required=False,
        label=_('Is this the gateway/router?'),
        help_text=_('Enable this when the device is the main L3 gateway for this network.'),
    )

    class Meta:
        model = MikroTikDevice
        fields = [
            'name', 'host', 'port', 'scheme', 'username', 'verify_tls',
            'enabled', 'sync_enabled', 'sync_interval_minutes',
            'import_vlans', 'import_subnets', 'import_dhcp_leases',
            'import_arp', 'import_neighbors', 'ignore_dynamic_bridge_vlans',
            'device_role', 'is_authoritative_for_l3', 'is_authoritative_for_dhcp',
        ]

    class Media:
        js = ('ipam/admin/mikrotik_device_custom_port.js',)

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        custom_port_enabled = self._is_custom_port_enabled()
        self.initial['custom_port'] = custom_port_enabled
        if 'port' in self.fields:
            self.fields['port'].required = custom_port_enabled

        if self.instance.pk:
            self.fields.pop('is_gateway_router', None)
        else:
            self.initial['is_gateway_router'] = (
                self.instance.device_role == MikroTikDevice.DeviceRole.ROUTER
            )
            # On creation the password is required.
            self.fields['password'].required = True
            self.fields['password'].help_text = _('Enter the device password.')

    def _is_custom_port_enabled(self) -> bool:
        if self.is_bound:
            return self._is_checkbox_checked('custom_port')

        if not self.instance.pk:
            return False

        default_port = MikroTikDevice.default_port_for_scheme(self.instance.scheme)
        return self.instance.port != default_port

    def _is_checkbox_checked(self, field_name: str) -> bool:
        raw_value = self.data.get(self.add_prefix(field_name))
        return raw_value in {'on', '1', 'true', 'True'}

    def clean_password(self):
        value = self.cleaned_data.get('password', '')
        if not value and not self.instance.pk:
            raise forms.ValidationError(_('Password is required when creating a device.'))
        return value

    def clean(self):
        cleaned_data = super().clean()
        scheme = cleaned_data.get('scheme') or self.instance.scheme or MikroTikDevice.Scheme.HTTPS
        custom_port_enabled = cleaned_data.get('custom_port', False)

        if not custom_port_enabled:
            cleaned_data['port'] = MikroTikDevice.default_port_for_scheme(scheme)

        return cleaned_data

    def save(self, commit=True):
        if not self.instance.pk:
            self.instance.device_role = (
                MikroTikDevice.DeviceRole.ROUTER
                if self.cleaned_data.get('is_gateway_router')
                else MikroTikDevice.DeviceRole.UNKNOWN
            )

        if not self.cleaned_data.get('custom_port', False):
            scheme = self.cleaned_data.get('scheme') or self.instance.scheme
            self.instance.port = MikroTikDevice.default_port_for_scheme(scheme)

        password = self.cleaned_data.get('password', '')
        if password:
            # Encrypt and store the new password.
            self.instance.password_encrypted = encrypt_password(password)
        # If blank on edit, the existing password_encrypted remains untouched.
        return super().save(commit=commit)
