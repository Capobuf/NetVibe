from datetime import timedelta
from unittest.mock import patch

from django.contrib import admin
from django.contrib.auth.models import AnonymousUser
from django.core.exceptions import ValidationError
from django.core.management import call_command
from django.test import RequestFactory, TestCase
from django.utils import timezone
from unfold.contrib.forms.widgets import WysiwygWidget

from .models import IPAMSettings, IPAddress, JobRun, Subnet, VLAN
from .tasks import run_subnet_scan, schedule_subnet_scan_now


def fake_enqueue(job_run, task_ref='task-ref'):
    if not job_run.task_ref:
        job_run.task_ref = task_ref
        job_run.save(update_fields=('task_ref',))
    return job_run


class IPAMModelTests(TestCase):
    def setUp(self):
        self.vlan = VLAN.objects.create(number=100, name='Users')

    def test_ipam_settings_clean_rejects_invalid_thresholds(self):
        settings_obj = IPAMSettings(
            warning_after_minutes=60,
            offline_after_minutes=60,
        )

        with self.assertRaisesMessage(
            ValidationError,
            'Offline threshold must be greater than the warning threshold.',
        ):
            settings_obj.full_clean()

    def test_subnet_clean_rejects_invalid_effective_values(self):
        settings_obj = IPAMSettings.objects.create()
        settings_obj.default_scan_interval_minutes = 0
        settings_obj.save(update_fields=('default_scan_interval_minutes',))

        subnet = Subnet(
            vlan=self.vlan,
            cidr='10.0.0.0/24',
            scan_enabled=True,
            offline_after_minutes_override=10,
            warning_after_minutes_override=20,
        )

        with self.assertRaises(ValidationError):
            subnet.full_clean()

    def test_repeated_save_does_not_duplicate_scheduled_jobs(self):
        with patch(
            'ipam.tasks._enqueue_job_run',
            side_effect=lambda job_run: fake_enqueue(job_run, 'task-one'),
        ):
            with self.captureOnCommitCallbacks(execute=True):
                subnet = Subnet.objects.create(
                    vlan=self.vlan,
                    cidr='10.0.1.0/24',
                    scan_enabled=True,
                )

            subnet.description = 'Updated description'
            with self.captureOnCommitCallbacks(execute=True):
                subnet.save()

        self.assertEqual(JobRun.objects.count(), 1)
        self.assertEqual(JobRun.objects.get().task_ref, 'task-one')

    def test_ip_address_admin_uses_compact_edit_layout(self):
        request = RequestFactory().get('/admin/ipam/ipaddress/add/')
        request.user = AnonymousUser()
        model_admin = admin.site._registry[IPAddress]

        fieldsets = model_admin.get_fieldsets(request)
        form = model_admin.get_form(request)

        self.assertEqual(str(fieldsets[0][0]), 'Identity')
        self.assertEqual(
            fieldsets[0][1]['fields'],
            (
                'subnet',
                ('address', 'mac_address', 'hostname'),
                ('status', 'exclude_ping'),
            ),
        )
        self.assertEqual(str(fieldsets[1][0]), 'Notes')
        self.assertEqual(fieldsets[1][1]['fields'], ('note',))
        self.assertIsInstance(form.base_fields['note'].widget, WysiwygWidget)
        self.assertNotIn('last_seen', form.base_fields)

    def test_subnet_admin_prioritizes_vlan_and_groups_scanning_fields(self):
        request = RequestFactory().get('/admin/ipam/subnet/add/')
        request.user = AnonymousUser()
        model_admin = admin.site._registry[Subnet]

        fieldsets = model_admin.get_fieldsets(request)

        self.assertEqual(str(fieldsets[0][0]), 'Base')
        self.assertEqual(
            fieldsets[0][1]['fields'],
            ('vlan', 'cidr', 'parent', 'description'),
        )
        self.assertEqual(str(fieldsets[1][0]), 'Scanning')
        self.assertEqual(
            fieldsets[1][1]['fields'],
            (
                'scan_enabled',
                'discover_subnet',
                'scan_interval_minutes',
                'warning_after_minutes_override',
                'offline_after_minutes_override',
            ),
        )

    def test_subnet_admin_explains_scan_vs_discovery(self):
        request = RequestFactory().get('/admin/ipam/subnet/add/')
        request.user = AnonymousUser()
        model_admin = admin.site._registry[Subnet]
        form = model_admin.get_form(request)()

        self.assertEqual(form.fields['discover_subnet'].label, 'Auto-discover IPs')
        self.assertIn('stop all scans', form.fields['scan_enabled'].help_text)
        self.assertIn(
            'only applies when scanning is enabled',
            form.fields['discover_subnet'].help_text,
        )

    def test_subnet_change_form_defaults_to_overview_and_edit_mode_restores_fields(self):
        subnet = Subnet.objects.create(vlan=self.vlan, cidr='10.0.2.0/24')
        model_admin = admin.site._registry[Subnet]

        overview_request = RequestFactory().get('/admin/ipam/subnet/1/change/')
        overview_request.user = AnonymousUser()
        edit_request = RequestFactory().get('/admin/ipam/subnet/1/change/?mode=edit')
        edit_request.user = AnonymousUser()

        self.assertEqual(model_admin.get_fieldsets(overview_request, subnet), ())
        self.assertEqual(
            model_admin.get_fieldsets(edit_request, subnet),
            model_admin.fieldsets,
        )


class SchedulingTests(TestCase):
    def setUp(self):
        self.vlan = VLAN.objects.create(number=200, name='Servers')

    def create_subnet(self, **overrides):
        data = {
            'vlan': self.vlan,
            'cidr': '10.10.0.0/24',
            'scan_enabled': True,
            'discover_subnet': False,
        }
        data.update(overrides)
        with self.captureOnCommitCallbacks(execute=False):
            return Subnet.objects.create(**data)

    def test_schedule_subnet_scan_now_creates_single_job(self):
        subnet = self.create_subnet()

        with patch(
            'ipam.tasks._enqueue_job_run',
            side_effect=lambda job_run: fake_enqueue(job_run, 'task-123'),
        ):
            job_run = schedule_subnet_scan_now(subnet.pk)
            same_job_run = schedule_subnet_scan_now(subnet.pk)

        self.assertEqual(job_run.pk, same_job_run.pk)
        self.assertEqual(JobRun.objects.count(), 1)
        self.assertEqual(JobRun.objects.get().task_ref, 'task-123')

    def test_disabled_job_marks_skipped_disabled(self):
        subnet = self.create_subnet(scan_enabled=False)
        job_run = JobRun.objects.create(
            subnet=subnet,
            scheduled_for=timezone.now(),
        )

        run_subnet_scan.call(job_run.pk)

        job_run.refresh_from_db()
        self.assertEqual(job_run.status, JobRun.Status.SKIPPED_DISABLED)

    def test_guardrail_job_skips_and_reschedules(self):
        subnet = self.create_subnet(cidr='10.20.0.0/20')
        job_run = JobRun.objects.create(
            subnet=subnet,
            scheduled_for=timezone.now(),
        )

        with patch(
            'ipam.tasks._enqueue_job_run',
            side_effect=lambda job_run: fake_enqueue(job_run, 'task-next'),
        ):
            run_subnet_scan.call(job_run.pk)

        job_run.refresh_from_db()
        self.assertEqual(job_run.status, JobRun.Status.SKIPPED_GUARDRAIL)
        self.assertEqual(JobRun.objects.count(), 2)
        next_job = JobRun.objects.exclude(pk=job_run.pk).get()
        self.assertEqual(next_job.status, JobRun.Status.SCHEDULED)
        self.assertEqual(next_job.task_ref, 'task-next')

    def test_scan_updates_last_seen_creates_missing_records_and_skips_excluded(self):
        subnet = self.create_subnet(cidr='10.30.0.0/29', discover_subnet=True)
        excluded = IPAddress.objects.create(
            subnet=subnet,
            address='10.30.0.1',
            exclude_ping=True,
        )
        existing = IPAddress.objects.create(
            subnet=subnet,
            address='10.30.0.2',
            last_seen=timezone.now() - timedelta(days=2),
        )
        job_run = JobRun.objects.create(
            subnet=subnet,
            scheduled_for=timezone.now(),
        )

        seen_addresses = []

        def ping_side_effect(address, timeout_ms):
            seen_addresses.append(address)
            return address in {'10.30.0.2', '10.30.0.3'}

        with patch('ipam.tasks._ping_host', side_effect=ping_side_effect), patch(
            'ipam.tasks._enqueue_job_run',
            side_effect=lambda job_run: fake_enqueue(job_run, 'task-next'),
        ):
            run_subnet_scan.call(job_run.pk)

        job_run.refresh_from_db()
        existing.refresh_from_db()
        self.assertEqual(job_run.status, JobRun.Status.SUCCESS)
        self.assertEqual(job_run.hosts_total, 6)
        self.assertEqual(job_run.hosts_pinged, 5)
        self.assertEqual(job_run.hosts_alive, 2)
        self.assertEqual(job_run.ip_records_created, 1)
        self.assertEqual(job_run.ip_records_updated, 1)
        self.assertNotIn(excluded.address, seen_addresses)
        self.assertGreater(existing.last_seen, timezone.now() - timedelta(minutes=1))
        self.assertTrue(
            IPAddress.objects.filter(subnet=subnet, address='10.30.0.3').exists()
        )

    def test_failed_ping_does_not_overwrite_last_seen(self):
        subnet = self.create_subnet(cidr='10.40.0.0/30')
        original_last_seen = timezone.now() - timedelta(days=1)
        ip_record = IPAddress.objects.create(
            subnet=subnet,
            address='10.40.0.1',
            last_seen=original_last_seen,
        )
        job_run = JobRun.objects.create(
            subnet=subnet,
            scheduled_for=timezone.now(),
        )

        with patch('ipam.tasks._ping_host', return_value=False), patch(
            'ipam.tasks._enqueue_job_run',
            side_effect=lambda job_run: fake_enqueue(job_run, 'task-next'),
        ):
            run_subnet_scan.call(job_run.pk)

        ip_record.refresh_from_db()
        job_run.refresh_from_db()
        self.assertEqual(job_run.status, JobRun.Status.SUCCESS)
        self.assertEqual(job_run.ip_records_updated, 0)
        self.assertEqual(ip_record.last_seen, original_last_seen)

    def test_prune_jobs_removes_only_old_terminal_rows(self):
        subnet = self.create_subnet()
        old_finished = JobRun.objects.create(
            subnet=subnet,
            status=JobRun.Status.SUCCESS,
            scheduled_for=timezone.now() - timedelta(days=40),
            started_at=timezone.now() - timedelta(days=40, minutes=1),
            finished_at=timezone.now() - timedelta(days=40),
        )
        active_job = JobRun.objects.create(
            subnet=subnet,
            status=JobRun.Status.SCHEDULED,
            scheduled_for=timezone.now() - timedelta(days=40),
        )

        with patch('ipam.management.commands.prune_jobs.call_command') as call_command_mock:
            call_command('prune_jobs', days=30)

        self.assertFalse(JobRun.objects.filter(pk=old_finished.pk).exists())
        self.assertTrue(JobRun.objects.filter(pk=active_job.pk).exists())
        call_command_mock.assert_called_once()
