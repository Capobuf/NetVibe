import django.db.models.deletion
from django.db import migrations, models


def seed_ipam_settings(apps, schema_editor):
    IPAMSettings = apps.get_model('ipam', 'IPAMSettings')
    database_alias = schema_editor.connection.alias

    if not IPAMSettings.objects.using(database_alias).exists():
        IPAMSettings.objects.using(database_alias).create(
            warning_after_seconds=86400,
            offline_after_seconds=604800,
            default_scan_interval_seconds=3600,
            worker_mode='sequential',
            worker_concurrency=1,
            ping_timeout_ms=1000,
            ping_concurrency=64,
        )


class Migration(migrations.Migration):

    dependencies = [
        ('ipam', '0001_initial'),
    ]

    operations = [
        migrations.RenameField(
            model_name='subnet',
            old_name='ping_subnet',
            new_name='scan_enabled',
        ),
        migrations.CreateModel(
            name='IPAMSettings',
            fields=[
                (
                    'id',
                    models.BigAutoField(
                        auto_created=True,
                        primary_key=True,
                        serialize=False,
                        verbose_name='ID',
                    ),
                ),
                (
                    'warning_after_seconds',
                    models.PositiveIntegerField(
                        default=86400,
                        help_text='Mark an IP as stale after this many seconds without a response.',
                    ),
                ),
                (
                    'offline_after_seconds',
                    models.PositiveIntegerField(
                        default=604800,
                        help_text='Mark an IP as offline after this many seconds without a response.',
                    ),
                ),
                (
                    'default_scan_interval_seconds',
                    models.PositiveIntegerField(
                        default=3600,
                        help_text='Use this interval when a subnet does not override its scan frequency.',
                    ),
                ),
                (
                    'worker_mode',
                    models.CharField(
                        choices=[
                            ('sequential', 'Sequential'),
                            ('parallel', 'Parallel'),
                        ],
                        default='sequential',
                        help_text='Choose whether the worker runs one scan at a time or multiple scans.',
                        max_length=16,
                    ),
                ),
                (
                    'worker_concurrency',
                    models.PositiveIntegerField(
                        default=1,
                        help_text='Use this many worker processes when parallel mode is enabled.',
                    ),
                ),
                (
                    'ping_timeout_ms',
                    models.PositiveIntegerField(
                        default=1000,
                        help_text='Wait this many milliseconds for each ICMP response.',
                    ),
                ),
                (
                    'ping_concurrency',
                    models.PositiveIntegerField(
                        default=64,
                        help_text='Limit how many ping subprocesses run at once inside one scan job.',
                    ),
                ),
            ],
            options={
                'verbose_name': 'IPAM settings',
                'verbose_name_plural': 'IPAM settings',
            },
        ),
        migrations.AlterField(
            model_name='subnet',
            name='scan_enabled',
            field=models.BooleanField(
                default=False,
                help_text='Enable ICMP reachability checks for this subnet.',
            ),
        ),
        migrations.AddField(
            model_name='subnet',
            name='offline_after_seconds_override',
            field=models.PositiveIntegerField(
                blank=True,
                help_text='Override the global offline threshold for this subnet. Leave blank to use the global default.',
                null=True,
            ),
        ),
        migrations.AddField(
            model_name='subnet',
            name='scan_interval_seconds',
            field=models.PositiveIntegerField(
                blank=True,
                help_text='Override the global scan frequency for this subnet. Leave blank to use the global default.',
                null=True,
            ),
        ),
        migrations.AddField(
            model_name='subnet',
            name='warning_after_seconds_override',
            field=models.PositiveIntegerField(
                blank=True,
                help_text='Override the global warning threshold for this subnet. Leave blank to use the global default.',
                null=True,
            ),
        ),
        migrations.CreateModel(
            name='JobRun',
            fields=[
                (
                    'id',
                    models.BigAutoField(
                        auto_created=True,
                        primary_key=True,
                        serialize=False,
                        verbose_name='ID',
                    ),
                ),
                (
                    'job_type',
                    models.CharField(
                        choices=[('subnet_scan', 'Subnet scan')],
                        default='subnet_scan',
                        max_length=32,
                    ),
                ),
                (
                    'status',
                    models.CharField(
                        choices=[
                            ('scheduled', 'Scheduled'),
                            ('running', 'Running'),
                            ('success', 'Success'),
                            ('failed', 'Failed'),
                            ('skipped_guardrail', 'Skipped (guardrail)'),
                            ('skipped_disabled', 'Skipped (disabled)'),
                        ],
                        default='scheduled',
                        max_length=32,
                    ),
                ),
                ('scheduled_for', models.DateTimeField()),
                ('started_at', models.DateTimeField(blank=True, null=True)),
                ('finished_at', models.DateTimeField(blank=True, null=True)),
                ('hosts_total', models.PositiveIntegerField(default=0)),
                ('hosts_pinged', models.PositiveIntegerField(default=0)),
                ('hosts_alive', models.PositiveIntegerField(default=0)),
                ('ip_records_created', models.PositiveIntegerField(default=0)),
                ('ip_records_updated', models.PositiveIntegerField(default=0)),
                ('error_message', models.TextField(blank=True)),
                ('task_ref', models.CharField(blank=True, db_index=True, max_length=64)),
                (
                    'subnet',
                    models.ForeignKey(
                        on_delete=django.db.models.deletion.PROTECT,
                        related_name='job_runs',
                        to='ipam.subnet',
                    ),
                ),
            ],
            options={
                'ordering': ('-scheduled_for', '-pk'),
                'indexes': [
                    models.Index(fields=['status', 'scheduled_for'], name='ipam_jobrun_status_02a4bc_idx'),
                    models.Index(fields=['subnet', 'scheduled_for'], name='ipam_jobrun_subnet__7ef0a9_idx'),
                ],
            },
        ),
        migrations.AddConstraint(
            model_name='jobrun',
            constraint=models.UniqueConstraint(
                fields=('job_type', 'subnet', 'scheduled_for'),
                name='ipam_unique_jobrun_slot',
            ),
        ),
        migrations.RunPython(seed_ipam_settings, migrations.RunPython.noop),
    ]
