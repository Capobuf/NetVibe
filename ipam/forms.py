"""
Forms used by IPAM admin workflows.

Contains:
- write-only credential handling for MikroTik integration settings
- validation rules for singleton settings creation/edit flows

Does not contain:
- admin registration or ModelAdmin classes
- MikroTik sync/discovery business logic

Notes for maintainers:
- keep plaintext passwords transient and never persisted in form fields
- this module is intentionally small because admin flows are centralized
"""

from django import forms
from django.utils.translation import gettext_lazy as _

from .crypto import encrypt_password
from .models import MikroTikIntegrationSettings


class MikroTikIntegrationSettingsForm(forms.ModelForm):
    """Write-only password handling for the MikroTik singleton settings."""

    password = forms.CharField(
        widget=forms.PasswordInput(render_value=False),
        required=False,
        label=_('Password'),
        help_text=_('Leave blank to keep the existing password.'),
    )

    class Meta:
        model = MikroTikIntegrationSettings
        fields = ('username', 'run_retention_days')

    def __init__(self, *args, **kwargs):
        """Require password only on first create, not on subsequent edits."""
        super().__init__(*args, **kwargs)
        if self.instance.pk:
            return

        self.fields['password'].required = True
        self.fields['password'].help_text = _('Enter the global MikroTik password.')

    def clean_password(self):
        """Enforce password presence only when creating the singleton row."""
        value = self.cleaned_data.get('password', '')
        if not value and not self.instance.pk:
            raise forms.ValidationError(
                _('Password is required when creating MikroTik settings.')
            )
        return value

    def save(self, commit=True):
        """Encrypt and persist password only when a new value is provided."""
        password = self.cleaned_data.get('password', '')
        if password:
            self.instance.password_encrypted = encrypt_password(password)
        return super().save(commit=commit)
