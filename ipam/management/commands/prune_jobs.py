from datetime import timedelta

from django.core.management import call_command
from django.core.management.base import BaseCommand, CommandError
from django.utils import timezone

from ...models import JobRun


class Command(BaseCommand):
    help = 'Prune finished JobRun rows and old django-tasks-db task results.'

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

        call_command(
            'prune_db_task_results',
            min_age_days=days,
            failed_min_age_days=days,
        )

        self.stdout.write(
            self.style.SUCCESS(
                f'Pruned {deleted_count} JobRun rows older than {days} day(s).'
            )
        )
