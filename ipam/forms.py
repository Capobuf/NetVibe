"""Admin forms for the IPAM app."""

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
        super().__init__(*args, **kwargs)
        if self.instance.pk:
            return

        self.fields['password'].required = True
        self.fields['password'].help_text = _('Enter the global MikroTik password.')

    def clean_password(self):
        value = self.cleaned_data.get('password', '')
        if not value and not self.instance.pk:
            raise forms.ValidationError(
                _('Password is required when creating MikroTik settings.')
            )
        return value

    def save(self, commit=True):
        password = self.cleaned_data.get('password', '')
        if password:
            self.instance.password_encrypted = encrypt_password(password)
        return super().save(commit=commit)
