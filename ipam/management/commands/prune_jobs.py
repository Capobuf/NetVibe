from datetime import timedelta

from django.core.management import call_command
from django.core.management.base import BaseCommand, CommandError
from django.db.utils import OperationalError, ProgrammingError
from django.utils import timezone

from ...models import (
    JobRun,
    MikroTikDiscoveryRun,
    MikroTikIntegrationSettings,
    MikroTikSyncRun,
)


class Command(BaseCommand):
    help = (
        'Prune old terminal JobRun rows, MikroTik run history, '
        'and old django-tasks-db task results.'
    )

    def add_arguments(self, parser):
        parser.add_argument(
            '--days',
            type=int,
            default=30,
            help='Delete finished job rows older than this many days.',
        )

    def handle(self, *args, **options):
        days = options['days']
        if days < 1:
            raise CommandError('--days must be at least 1.')

        try:
            cutoff = timezone.now() - timedelta(days=days)
            terminal_statuses = (
                JobRun.Status.SUCCESS,
                JobRun.Status.FAILED,
                JobRun.Status.SKIPPED_DISABLED,
                JobRun.Status.SKIPPED_GUARDRAIL,
            )

            deleted_count, _ = JobRun.objects.filter(
                status__in=terminal_statuses,
                finished_at__isnull=False,
                finished_at__lt=cutoff,
            ).delete()

            mikrotik_settings = MikroTikIntegrationSettings.objects.order_by('pk').first()
            mikrotik_days = (
                mikrotik_settings.run_retention_days
                if mikrotik_settings is not None
                else MikroTikIntegrationSettings.DEFAULT_RUN_RETENTION_DAYS
            )
            mikrotik_cutoff = timezone.now() - timedelta(days=mikrotik_days)

            discovery_deleted_count, _ = MikroTikDiscoveryRun.objects.filter(
                status__in=(
                    MikroTikDiscoveryRun.Status.SUCCESS,
                    MikroTikDiscoveryRun.Status.PARTIAL,
                    MikroTikDiscoveryRun.Status.FAILED,
                ),
                finished_at__isnull=False,
                finished_at__lt=mikrotik_cutoff,
            ).delete()

            sync_deleted_count, _ = MikroTikSyncRun.objects.filter(
                status__in=(
                    MikroTikSyncRun.Status.SUCCESS,
                    MikroTikSyncRun.Status.FAILED,
                ),
                finished_at__isnull=False,
                finished_at__lt=mikrotik_cutoff,
            ).delete()

            call_command(
                'prune_db_task_results',
                min_age_days=days,
                failed_min_age_days=days,
            )
        except (OperationalError, ProgrammingError) as exc:
            self.stdout.write(
                self.style.WARNING(
                    'Skipping prune_jobs because database schema is not ready: '
                    f'{exc.__class__.__name__}: {exc}'
                )
            )
            return

        self.stdout.write(
            self.style.SUCCESS(
                f'Pruned {deleted_count} JobRun rows older than {days} day(s).'
            )
        )
        self.stdout.write(
            self.style.SUCCESS(
                'Pruned '
                f'{discovery_deleted_count} MikroTik discovery runs and '
                f'{sync_deleted_count} MikroTik sync runs older than '
                f'{mikrotik_days} day(s).'
            )
        )
