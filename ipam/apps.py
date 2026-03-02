from django.apps import AppConfig


class IpamConfig(AppConfig):
    name = 'ipam'
    verbose_name = 'IPAM'

    def ready(self):
        from . import signals  # noqa: F401
