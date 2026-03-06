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

    class Meta:
        model = MikroTikDevice
        fields = [
            'name', 'host', 'port', 'scheme', 'username', 'verify_tls',
            'enabled', 'sync_enabled', 'sync_interval_minutes',
            'import_vlans', 'import_subnets', 'import_dhcp_leases',
            'import_arp', 'import_neighbors', 'ignore_dynamic_bridge_vlans',
            'device_role', 'is_authoritative_for_l3', 'is_authoritative_for_dhcp',
        ]

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        # On creation the password is required.
        if not self.instance.pk:
            self.fields['password'].required = True
            self.fields['password'].help_text = _('Enter the device password.')

    def clean_password(self):
        value = self.cleaned_data.get('password', '')
        if not value and not self.instance.pk:
            raise forms.ValidationError(_('Password is required when creating a device.'))
        return value

    def save(self, commit=True):
        password = self.cleaned_data.get('password', '')
        if password:
            # Encrypt and store the new password.
            self.instance.password_encrypted = encrypt_password(password)
        # If blank on edit, the existing password_encrypted remains untouched.
        return super().save(commit=commit)
