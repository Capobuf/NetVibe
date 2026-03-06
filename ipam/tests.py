from datetime import timedelta
from unittest.mock import patch

from django.contrib import admin
from django.contrib.auth.models import AnonymousUser
from django.core.exceptions import ValidationError
from django.core.management import call_command
from django.test import RequestFactory, SimpleTestCase, TestCase
from django.utils import timezone
from unfold.contrib.forms.widgets import WysiwygWidget

from .models import (
    IPAMSettings, IPAddress, JobRun, MikroTikDevice,
    MikroTikSyncRun, SourceKind, Subnet, VLAN,
)
from .tasks import (
    run_subnet_scan, schedule_mikrotik_sync_now,
    schedule_next_mikrotik_sync, schedule_subnet_scan_now,
)


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

    def test_ip_address_lists_show_hostname_in_a_dedicated_column(self):
        model_admin = admin.site._registry[IPAddress]

        self.assertEqual(
            model_admin.list_display[:4],
            (
                'status_badge',
                'address_display',
                'hostname_display',
                'mac_address_display',
            ),
        )

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
        self.assertIn(
            'capture their MAC address',
            form.fields['discover_subnet'].help_text,
        )
        self.assertIn(
            'reverse DNS hostname',
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
            'ipam.tasks._get_mac_address',
            side_effect=lambda address: {
                '10.30.0.2': 'aa:bb:cc:dd:ee:02',
                '10.30.0.3': 'aa:bb:cc:dd:ee:03',
            }.get(address, ''),
        ), patch(
            'ipam.tasks._get_hostname',
            side_effect=lambda address: {
                '10.30.0.2': 'host-02.example.test',
                '10.30.0.3': 'host-03.example.test',
            }.get(address, ''),
        ), patch(
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
        self.assertEqual(existing.mac_address, 'aa:bb:cc:dd:ee:02')
        self.assertEqual(existing.hostname, 'host-02.example.test')
        self.assertTrue(
            IPAddress.objects.filter(subnet=subnet, address='10.30.0.3').exists()
        )
        self.assertEqual(
            IPAddress.objects.get(subnet=subnet, address='10.30.0.3').mac_address,
            'aa:bb:cc:dd:ee:03',
        )
        self.assertEqual(
            IPAddress.objects.get(subnet=subnet, address='10.30.0.3').hostname,
            'host-03.example.test',
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

    def test_get_mac_address_parses_neighbor_table_output(self):
        completed = type(
            'CompletedProcess',
            (),
            {
                'returncode': 0,
                'stdout': '10.0.0.2 dev eth0 lladdr AA:BB:CC:DD:EE:FF REACHABLE\n',
            },
        )()

        with patch('ipam.tasks.subprocess.run', return_value=completed):
            from .tasks import _get_mac_address

            self.assertEqual(_get_mac_address('10.0.0.2'), 'aa:bb:cc:dd:ee:ff')

    def test_get_hostname_uses_reverse_dns(self):
        with patch(
            'ipam.tasks.socket.gethostbyaddr',
            return_value=('host-02.example.test', [], ['10.0.0.2']),
        ):
            from .tasks import _get_hostname

            self.assertEqual(_get_hostname('10.0.0.2'), 'host-02.example.test')

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


# =====================================================================
# pgcrypto and credential tests
# =====================================================================


class PgCryptoCredentialTests(TestCase):

    def test_missing_pgcrypto_key_fails_loudly(self):
        from ipam.crypto import _get_encryption_key
        from django.core.exceptions import ImproperlyConfigured

        with patch.dict('os.environ', {'NETVIBE_PGCRYPTO_KEY': ''}):
            with self.assertRaises(ImproperlyConfigured):
                _get_encryption_key()

    def test_encrypt_decrypt_roundtrip(self):
        from ipam.crypto import decrypt_password, encrypt_password

        plaintext = 'my-secret-password'
        ciphertext = encrypt_password(plaintext)
        self.assertIsInstance(ciphertext, (bytes, memoryview))
        # The ciphertext should NOT be the plaintext.
        self.assertNotEqual(ciphertext, plaintext.encode())
        self.assertEqual(decrypt_password(ciphertext), plaintext)

    def test_password_not_stored_in_plaintext(self):
        from ipam.crypto import encrypt_password
        from ipam.models import MikroTikDevice

        ciphertext = encrypt_password('RouterPassword123')
        device = MikroTikDevice.objects.create(
            host='10.0.0.1',
            username='admin',
            password_encrypted=ciphertext,
        )
        device.refresh_from_db()
        raw_bytes = bytes(device.password_encrypted)
        self.assertNotIn(b'RouterPassword123', raw_bytes)

    def test_password_unchanged_when_edit_blank(self):
        from ipam.crypto import decrypt_password, encrypt_password

        ciphertext = encrypt_password('original-password')
        device = MikroTikDevice.objects.create(
            host='10.0.0.2',
            username='admin',
            password_encrypted=ciphertext,
        )
        # Simulate blank password on edit (no change).
        device.save()
        device.refresh_from_db()
        self.assertEqual(decrypt_password(device.password_encrypted), 'original-password')

    def test_password_changes_when_new_value_submitted(self):
        from ipam.crypto import decrypt_password, encrypt_password

        device = MikroTikDevice.objects.create(
            host='10.0.0.3',
            username='admin',
            password_encrypted=encrypt_password('old-pass'),
        )
        device.password_encrypted = encrypt_password('new-pass')
        device.save()
        device.refresh_from_db()
        self.assertEqual(decrypt_password(device.password_encrypted), 'new-pass')

    def test_admin_form_does_not_expose_decrypted_password(self):
        from ipam.forms import MikroTikDeviceForm
        from ipam.crypto import encrypt_password

        device = MikroTikDevice.objects.create(
            host='10.0.0.4',
            username='admin',
            password_encrypted=encrypt_password('secret'),
        )
        form = MikroTikDeviceForm(instance=device)
        rendered = form.as_p()
        self.assertNotIn('secret', rendered)


# =====================================================================
# Contract tests
# =====================================================================


class ContractTests(SimpleTestCase):
    def test_envelope_validation(self):
        from ipam.contracts import Envelope, MessageType

        env = Envelope(
            message_type=MessageType.ICMP_SCAN_RESULT_V1,
            occurred_at=timezone.now(),
        )
        self.assertEqual(env.contract_version, '1')
        self.assertTrue(env.message_id)
        self.assertEqual(env.message_type, MessageType.ICMP_SCAN_RESULT_V1)

    def test_envelope_rejects_invalid_message_type(self):
        from ipam.contracts import Envelope
        from pydantic import ValidationError as PydanticValidationError

        with self.assertRaises(PydanticValidationError):
            Envelope(message_type='invalid.type', occurred_at=timezone.now())

    def test_icmp_scan_result_v1_serialization(self):
        from ipam.contracts import ICMPScanResultV1, ICMPHostResult

        result = ICMPScanResultV1(
            subnet_cidr='10.0.0.0/24',
            hosts_total=254,
            hosts_pinged=254,
            hosts_alive=10,
            results=[
                ICMPHostResult(address='10.0.0.1', alive=True, mac_address='aa:bb:cc:dd:ee:ff'),
            ],
        )
        data = result.model_dump()
        restored = ICMPScanResultV1.model_validate(data)
        self.assertEqual(restored.subnet_cidr, '10.0.0.0/24')
        self.assertEqual(len(restored.results), 1)

    def test_mikrotik_snapshot_result_v1_serialization(self):
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikBridgeVLAN

        snapshot = MikroTikSnapshotResultV1(
            device_host='10.0.0.1',
            bridge_vlans=[
                MikroTikBridgeVLAN(vlan_ids='10', bridge='bridge1', dynamic=False),
            ],
        )
        data = snapshot.model_dump()
        restored = MikroTikSnapshotResultV1.model_validate(data)
        self.assertEqual(restored.device_host, '10.0.0.1')
        self.assertEqual(len(restored.bridge_vlans), 1)
        self.assertFalse(restored.bridge_vlans[0].dynamic)

    def test_mikrotik_sync_apply_result_v1_serialization(self):
        from ipam.contracts import MikroTikSyncApplyResultV1, SyncApplyCounters

        result = MikroTikSyncApplyResultV1(
            device_host='10.0.0.1',
            counters=SyncApplyCounters(vlans_created=2, subnets_created=3),
            warnings=['VLAN 99: conflict'],
        )
        data = result.model_dump()
        restored = MikroTikSyncApplyResultV1.model_validate(data)
        self.assertEqual(restored.counters.vlans_created, 2)
        self.assertEqual(len(restored.warnings), 1)


# =====================================================================
# MikroTik device model/admin tests
# =====================================================================


class MikroTikDeviceModelTests(TestCase):

    def _make_device(self, **kwargs):
        from ipam.crypto import encrypt_password
        defaults = {
            'host': '192.168.1.1',
            'username': 'admin',
            'password_encrypted': encrypt_password('test-pass'),
        }
        defaults.update(kwargs)
        return MikroTikDevice.objects.create(**defaults)

    def test_defaults_are_correct(self):
        device = self._make_device()
        self.assertEqual(device.scheme, 'https')
        self.assertEqual(device.port, 443)
        self.assertFalse(device.verify_tls)
        self.assertTrue(device.sync_enabled)
        self.assertEqual(device.sync_interval_minutes, 15)
        self.assertTrue(device.import_vlans)
        self.assertTrue(device.import_subnets)
        self.assertTrue(device.import_dhcp_leases)
        self.assertTrue(device.import_arp)
        self.assertTrue(device.import_neighbors)
        self.assertTrue(device.ignore_dynamic_bridge_vlans)
        self.assertEqual(device.device_role, MikroTikDevice.DeviceRole.UNKNOWN)

    def test_base_url(self):
        device = self._make_device(scheme='https', host='10.0.0.1', port=443)
        self.assertEqual(device.base_url, 'https://10.0.0.1:443')

    def test_str_uses_name_or_host(self):
        device = self._make_device(name='', host='10.0.0.1')
        self.assertEqual(str(device), '10.0.0.1')
        device.name = 'MyRouter'
        self.assertEqual(str(device), 'MyRouter')

    def test_save_and_test_action_registered(self):
        model_admin = admin.site._registry[MikroTikDevice]
        self.assertIn('save_and_test', model_admin.actions_submit_line)

    def test_sync_now_action_registered(self):
        model_admin = admin.site._registry[MikroTikDevice]
        self.assertIn('sync_now_action', model_admin.actions_detail)

    def test_scheduling_defaults_correct(self):
        device = self._make_device()
        self.assertTrue(device.enabled)
        self.assertTrue(device.sync_enabled)
        self.assertEqual(device.sync_interval_minutes, 15)


# =====================================================================
# MikroTik client tests
# =====================================================================


class MikroTikClientTests(SimpleTestCase):

    def test_base_url_building(self):
        from ipam.integrations.mikrotik_client import MikroTikClient
        client = MikroTikClient(
            base_url='https://10.0.0.1:443',
            username='admin',
            password='pass',
        )
        self.assertEqual(client._base_url, 'https://10.0.0.1:443')

    def test_trailing_slash_stripped(self):
        from ipam.integrations.mikrotik_client import MikroTikClient
        client = MikroTikClient(
            base_url='https://10.0.0.1:443/',
            username='admin',
            password='pass',
        )
        self.assertEqual(client._base_url, 'https://10.0.0.1:443')

    def test_auth_failure_raises_auth_error(self):
        from ipam.integrations.mikrotik_client import MikroTikClient, MikroTikAuthError
        import httpx

        client = MikroTikClient(
            base_url='https://10.0.0.1:443',
            username='admin',
            password='wrong',
        )
        mock_response = httpx.Response(401, request=httpx.Request('GET', 'https://10.0.0.1/rest/test'))
        with patch('httpx.get', return_value=mock_response):
            with self.assertRaises(MikroTikAuthError):
                client._get('/test')

    def test_connection_error_raises_connection_error(self):
        from ipam.integrations.mikrotik_client import MikroTikClient, MikroTikConnectionError
        import httpx

        client = MikroTikClient(
            base_url='https://10.0.0.1:443',
            username='admin',
            password='pass',
        )
        with patch('httpx.get', side_effect=httpx.ConnectError('fail')):
            with self.assertRaises(MikroTikConnectionError):
                client._get('/test')

    def test_tls_verification(self):
        from ipam.integrations.mikrotik_client import MikroTikClient
        client_no_verify = MikroTikClient('https://x:443', 'a', 'b', verify_tls=False)
        client_verify = MikroTikClient('https://x:443', 'a', 'b', verify_tls=True)
        self.assertFalse(client_no_verify._verify)
        self.assertTrue(client_verify._verify)


# =====================================================================
# Mapper tests
# =====================================================================


class MikroTikMapperTests(SimpleTestCase):

    def test_dynamic_bridge_vlan_mapped_correctly(self):
        from ipam.integrations.mikrotik_mapper import map_bridge_vlans

        rows = [
            {'vlan-ids': '10', 'bridge': 'bridge1', 'dynamic': 'true', 'disabled': 'false'},
            {'vlan-ids': '20', 'bridge': 'bridge1', 'dynamic': 'false', 'disabled': 'true'},
        ]
        result = map_bridge_vlans(rows)
        self.assertEqual(len(result), 2)
        self.assertTrue(result[0].dynamic)
        self.assertFalse(result[1].dynamic)
        self.assertTrue(result[1].disabled)

    def test_ip_address_fields_mapped(self):
        from ipam.integrations.mikrotik_mapper import map_ip_addresses

        rows = [
            {
                'address': '10.0.0.1/24',
                'network': '10.0.0.0',
                'interface': 'ether1',
                'actual-interface': 'bridge_lan',
                'dynamic': 'false',
                'disabled': 'true',
                'invalid': 'false',
                '.id': '*1',
            },
        ]
        result = map_ip_addresses(rows)
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0].address, '10.0.0.1/24')
        self.assertEqual(result[0].actual_interface, 'bridge_lan')
        self.assertTrue(result[0].disabled)
        self.assertFalse(result[0].invalid)


# =====================================================================
# Sync engine tests
# =====================================================================


class MikroTikSyncTests(TestCase):

    def _make_device(self, **kwargs):
        from ipam.crypto import encrypt_password
        defaults = {
            'host': '192.168.1.1',
            'username': 'admin',
            'password_encrypted': encrypt_password('test-pass'),
        }
        defaults.update(kwargs)
        return MikroTikDevice.objects.create(**defaults)

    def test_device_test_populates_metadata(self):
        device = self._make_device()
        mock_identity = {'name': 'MyRouter'}
        mock_resource = {'version': '7.14.3', 'board-name': 'RB5009', 'platform': 'MikroTik'}

        with patch('ipam.integrations.mikrotik_sync._make_client') as mock_client_cls:
            mock_client = mock_client_cls.return_value
            mock_client.get_identity.return_value = mock_identity
            mock_client.get_system_resource.return_value = mock_resource

            from ipam.integrations.mikrotik_sync import run_device_test
            sync_run = run_device_test(device)

        device.refresh_from_db()
        self.assertEqual(sync_run.status, MikroTikSyncRun.Status.SUCCESS)
        self.assertEqual(device.last_seen_identity, 'MyRouter')
        self.assertEqual(device.last_seen_version, '7.14.3')
        self.assertEqual(device.last_seen_board, 'RB5009')
        self.assertEqual(device.last_seen_platform, 'MikroTik')
        self.assertEqual(device.name, 'MyRouter')

    def test_dynamic_bridge_vlans_are_ignored(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _sync_vlans
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikBridgeVLAN

        snapshot = MikroTikSnapshotResultV1(
            device_host='10.0.0.1',
            bridge_vlans=[
                MikroTikBridgeVLAN(vlan_ids='10', bridge='bridge1', dynamic=True),
                MikroTikBridgeVLAN(vlan_ids='20', bridge='bridge1', dynamic=False),
            ],
        )
        now = timezone.now()
        warnings = []
        counters = _sync_vlans(device, snapshot, now, 'trace', 'job', warnings)
        self.assertEqual(counters['vlans_created'], 1)
        self.assertTrue(VLAN.objects.filter(number=20).exists())
        self.assertFalse(VLAN.objects.filter(number=10).exists())

    def test_explicit_non_dynamic_disabled_vlan_is_represented(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _sync_vlans
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikBridgeVLAN

        snapshot = MikroTikSnapshotResultV1(
            bridge_vlans=[
                MikroTikBridgeVLAN(vlan_ids='30', bridge='bridge1', dynamic=False, disabled=True),
            ],
        )
        now = timezone.now()
        warnings = []
        _sync_vlans(device, snapshot, now, 'trace', 'job', warnings)
        vlan = VLAN.objects.get(number=30)
        self.assertTrue(vlan.source_upstream_disabled)

    def test_subnet_created_from_ip_address_not_vlan(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _sync_subnets
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikIPAddress

        snapshot = MikroTikSnapshotResultV1(
            ip_addresses=[
                MikroTikIPAddress(address='10.0.0.1/24', interface='ether1'),
            ],
        )
        now = timezone.now()
        warnings = []
        counters = _sync_subnets(device, snapshot, now, 'trace', 'job', warnings)
        self.assertEqual(counters['subnets_created'], 1)
        subnet = Subnet.objects.get(cidr='10.0.0.0/24')
        self.assertTrue(subnet.scan_enabled)
        self.assertEqual(subnet.source_kind, SourceKind.MIKROTIK)

    def test_disabled_invalid_subnet_state_persisted(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _sync_subnets
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikIPAddress

        snapshot = MikroTikSnapshotResultV1(
            ip_addresses=[
                MikroTikIPAddress(
                    address='10.1.0.1/24', interface='ether2',
                    disabled=True, invalid=True,
                ),
            ],
        )
        now = timezone.now()
        warnings = []
        _sync_subnets(device, snapshot, now, 'trace', 'job', warnings)
        subnet = Subnet.objects.get(cidr='10.1.0.0/24')
        self.assertTrue(subnet.source_upstream_disabled)
        self.assertTrue(subnet.source_upstream_invalid)

    def test_missing_lease_does_not_become_static(self):
        """IPs from ARP without DHCP info must NOT be marked as static leases."""
        device = self._make_device()
        subnet = Subnet.objects.create(
            cidr='10.0.0.0/24',
            source_kind=SourceKind.MIKROTIK,
            source_device=device,
        )
        from ipam.integrations.mikrotik_sync import _sync_arp
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikARPEntry

        snapshot = MikroTikSnapshotResultV1(
            arp_entries=[
                MikroTikARPEntry(address='10.0.0.5', mac_address='aa:bb:cc:dd:ee:01', interface='bridge1'),
            ],
        )
        now = timezone.now()
        warnings = []
        _sync_arp(device, snapshot, now, 'trace', 'job', warnings)
        ip_record = IPAddress.objects.get(address='10.0.0.5')
        self.assertEqual(ip_record.lease_kind, IPAddress.LeaseKind.NONE)

    def test_arp_outside_managed_subnets_is_ignored(self):
        device = self._make_device()
        # No subnets created — ARP should be completely ignored.
        from ipam.integrations.mikrotik_sync import _sync_arp
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikARPEntry

        snapshot = MikroTikSnapshotResultV1(
            arp_entries=[
                MikroTikARPEntry(address='8.8.8.8', mac_address='ff:ff:ff:ff:ff:ff', interface='ether1'),
            ],
        )
        now = timezone.now()
        warnings = []
        counters = _sync_arp(device, snapshot, now, 'trace', 'job', warnings)
        self.assertEqual(counters['ips_created'], 0)
        self.assertEqual(IPAddress.objects.count(), 0)

    def test_neighbor_data_does_not_create_ipam_objects(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _build_observed_summary
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikNeighbor

        snapshot = MikroTikSnapshotResultV1(
            neighbors=[
                MikroTikNeighbor(address='10.0.0.99', identity='switch1', platform='MikroTik'),
            ],
        )
        summary = _build_observed_summary(snapshot)
        self.assertEqual(summary['neighbor_count'], 1)
        # No IPAM objects created from neighbors.
        self.assertEqual(IPAddress.objects.count(), 0)
        self.assertEqual(Subnet.objects.count(), 0)

    def test_repeated_sync_is_idempotent(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _sync_vlans, _sync_subnets
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikBridgeVLAN, MikroTikIPAddress

        snapshot = MikroTikSnapshotResultV1(
            bridge_vlans=[MikroTikBridgeVLAN(vlan_ids='10', bridge='bridge1')],
            ip_addresses=[MikroTikIPAddress(address='10.0.0.1/24', interface='ether1')],
        )
        now = timezone.now()
        # First sync
        _sync_vlans(device, snapshot, now, 'trace1', 'job1', [])
        _sync_subnets(device, snapshot, now, 'trace1', 'job1', [])
        # Second sync — should not create duplicates.
        counters_v = _sync_vlans(device, snapshot, now, 'trace2', 'job2', [])
        counters_s = _sync_subnets(device, snapshot, now, 'trace2', 'job2', [])
        self.assertEqual(counters_v['vlans_created'], 0)
        self.assertEqual(counters_v['vlans_updated'], 1)
        self.assertEqual(counters_s['subnets_created'], 0)
        self.assertEqual(counters_s['subnets_updated'], 1)
        self.assertEqual(VLAN.objects.count(), 1)
        self.assertEqual(Subnet.objects.count(), 1)

    def test_provenance_fields_populated_on_imported_objects(self):
        device = self._make_device()
        from ipam.integrations.mikrotik_sync import _sync_vlans
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikBridgeVLAN

        snapshot = MikroTikSnapshotResultV1(
            bridge_vlans=[MikroTikBridgeVLAN(vlan_ids='50', bridge='bridge1')],
        )
        now = timezone.now()
        _sync_vlans(device, snapshot, now, 'trace-abc', 'job-123', [])
        vlan = VLAN.objects.get(number=50)
        self.assertEqual(vlan.source_kind, SourceKind.MIKROTIK)
        self.assertEqual(vlan.source_device_id, device.pk)
        self.assertEqual(vlan.source_trace_id, 'trace-abc')
        self.assertEqual(vlan.source_job_id, 'job-123')
        self.assertIsNotNone(vlan.last_synced_at)


# =====================================================================
# MikroTik scheduling tests
# =====================================================================


class MikroTikSchedulingTests(TestCase):

    def _make_device(self, **kwargs):
        from ipam.crypto import encrypt_password
        defaults = {
            'host': '192.168.1.1',
            'username': 'admin',
            'password_encrypted': encrypt_password('test-pass'),
            'enabled': True,
            'sync_enabled': True,
        }
        defaults.update(kwargs)
        return MikroTikDevice.objects.create(**defaults)

    def test_schedule_now_creates_single_run(self):
        device = self._make_device()
        with patch(
            'ipam.tasks._enqueue_mikrotik_sync_run',
            side_effect=lambda sr: _fake_enqueue_mikrotik(sr, 'task-m1'),
        ):
            sr1 = schedule_mikrotik_sync_now(device)
            sr2 = schedule_mikrotik_sync_now(device)
        self.assertEqual(sr1.pk, sr2.pk)
        self.assertEqual(MikroTikSyncRun.objects.filter(device=device).count(), 1)

    def test_duplicate_active_runs_prevented(self):
        device = self._make_device()
        MikroTikSyncRun.objects.create(
            device=device,
            mode=MikroTikSyncRun.Mode.SYNC,
            status=MikroTikSyncRun.Status.RUNNING,
            scheduled_for=timezone.now(),
            task_ref='existing-task',
        )
        with patch(
            'ipam.tasks._enqueue_mikrotik_sync_run',
            side_effect=lambda sr: _fake_enqueue_mikrotik(sr, 'task-m2'),
        ):
            result = schedule_mikrotik_sync_now(device)
        # Should return the existing run, not create a new one.
        self.assertEqual(MikroTikSyncRun.objects.filter(device=device).count(), 1)

    def test_next_run_scheduled_only_when_sync_enabled(self):
        device = self._make_device(sync_enabled=False)
        result = schedule_next_mikrotik_sync(device, timezone.now())
        self.assertIsNone(result)

    def test_disabling_device_stops_scheduling(self):
        device = self._make_device(enabled=False)
        result = schedule_mikrotik_sync_now(device)
        self.assertIsNone(result)


# =====================================================================
# Proxy / filter behavior tests
# =====================================================================


class MikroTikProxyAdminTests(TestCase):

    def test_mikrotik_vlan_view_only_shows_mikrotik_vlans(self):
        VLAN.objects.create(number=100, name='Manual VLAN')
        VLAN.objects.create(number=200, name='MT VLAN', source_kind=SourceKind.MIKROTIK)

        from ipam.admin import MikroTikVLAN, MikroTikVLANAdmin
        model_admin = admin.site._registry[MikroTikVLAN]
        factory = RequestFactory()
        request = factory.get('/')
        request.user = AnonymousUser()
        qs = model_admin.get_queryset(request)
        self.assertEqual(qs.count(), 1)
        self.assertEqual(qs.first().number, 200)

    def test_mikrotik_subnet_view_only_shows_mikrotik_subnets(self):
        Subnet.objects.create(cidr='10.0.0.0/24')
        Subnet.objects.create(cidr='10.1.0.0/24', source_kind=SourceKind.MIKROTIK)

        from ipam.admin import MikroTikSubnet, MikroTikSubnetAdmin
        model_admin = admin.site._registry[MikroTikSubnet]
        factory = RequestFactory()
        request = factory.get('/')
        request.user = AnonymousUser()
        qs = model_admin.get_queryset(request)
        self.assertEqual(qs.count(), 1)
        self.assertEqual(qs.first().cidr, '10.1.0.0/24')

    def test_disabling_integration_sync_on_vlan_affects_future_sync(self):
        from ipam.crypto import encrypt_password

        device = MikroTikDevice.objects.create(
            host='10.0.0.1', username='admin',
            password_encrypted=encrypt_password('pass'),
        )
        vlan = VLAN.objects.create(
            number=300, source_kind=SourceKind.MIKROTIK,
            source_device=device, integration_sync_enabled=False,
        )
        from ipam.integrations.mikrotik_sync import _sync_vlans
        from ipam.contracts import MikroTikSnapshotResultV1, MikroTikBridgeVLAN

        snapshot = MikroTikSnapshotResultV1(
            bridge_vlans=[MikroTikBridgeVLAN(vlan_ids='300', bridge='bridge1')],
        )
        warnings = []
        counters = _sync_vlans(device, snapshot, timezone.now(), 't', 'j', warnings)
        self.assertEqual(counters['vlans_updated'], 0)


# =====================================================================
# Regression tests
# =====================================================================


class RegressionTests(TestCase):

    def test_existing_subnet_scan_still_works(self):
        """Ensure the MikroTik changes did not break the existing ICMP scan flow."""
        vlan = VLAN.objects.create(number=400, name='Regression')
        subnet = Subnet.objects.create(
            vlan=vlan, cidr='10.40.0.0/30', scan_enabled=True, discover_subnet=False,
        )
        ip = IPAddress.objects.create(subnet=subnet, address='10.40.0.1')
        job_run = JobRun.objects.create(subnet=subnet, scheduled_for=timezone.now())

        with patch('ipam.tasks._ping_host', return_value=True), \
             patch('ipam.tasks._get_mac_address', return_value=''), \
             patch('ipam.tasks._get_hostname', return_value=''), \
             patch('ipam.tasks._enqueue_job_run',
                   side_effect=lambda jr: fake_enqueue(jr, 'next-ref')):
            run_subnet_scan.call(job_run.pk)

        job_run.refresh_from_db()
        self.assertEqual(job_run.status, JobRun.Status.SUCCESS)

    def test_no_synthetic_placeholder_vlan_created(self):
        """No 'L2 Scan' or similar placeholder VLAN should exist after import."""
        self.assertFalse(VLAN.objects.filter(name__icontains='L2 Scan').exists())

    def test_existing_admin_pages_not_broken(self):
        """Spot-check that existing admin registrations are intact."""
        self.assertIn(VLAN, admin.site._registry)
        self.assertIn(Subnet, admin.site._registry)
        self.assertIn(IPAddress, admin.site._registry)
        self.assertIn(IPAMSettings, admin.site._registry)
        self.assertIn(JobRun, admin.site._registry)


def _fake_enqueue_mikrotik(sync_run, task_ref='task-ref'):
    if not sync_run.task_ref:
        sync_run.task_ref = task_ref
        sync_run.save(update_fields=('task_ref',))
    return sync_run


