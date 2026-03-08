import os
from datetime import timedelta
from io import StringIO
from pathlib import Path
from unittest.mock import patch

from django.conf import settings
from django.contrib import admin
from django.contrib.auth import get_user_model
from django.contrib.auth.models import AnonymousUser
from django.contrib.messages.storage.fallback import FallbackStorage
from django.core.exceptions import FieldDoesNotExist, ValidationError
from django.core.management import call_command
from django.db.utils import ProgrammingError
from django.test import RequestFactory, SimpleTestCase, TestCase
from django.urls import reverse
from django.utils import timezone
from django.utils.module_loading import import_string

from .crypto import decrypt_password, encrypt_password
from .forms import MikroTikIntegrationSettingsForm
from .integrations.mikrotik_client import MikroTikConnectionError
from .integrations.mikrotik_sync import run_device_sync
from .models import (
    IPAMSettings,
    IPAddress,
    IPAddressObservation,
    JobRun,
    MikroTikDevice,
    MikroTikDiscoveryResult,
    MikroTikDiscoveryRun,
    MikroTikIntegrationSettings,
    MikroTikSyncRun,
    MikroTikTarget,
    ReviewItem,
    SourceKind,
    Subnet,
    Tag,
    VLAN,
)
from .tasks import (
    run_mikrotik_discovery,
    run_subnet_scan,
    schedule_mikrotik_discovery_for_targets,
)

TEST_NETVIBE_PGCRYPTO_KEY = 'netvibe-test-pgcrypto-key'


def attach_request_messages(request):
    request.session = {}
    request._messages = FallbackStorage(request)


def fake_enqueue(run_obj, task_ref='task-ref'):
    if not run_obj.task_ref:
        run_obj.task_ref = task_ref
        run_obj.save(update_fields=('task_ref',))
    return run_obj


def ensure_test_pgcrypto_key():
    # Keep encryption-related tests independent from host/container .env files.
    os.environ.setdefault('NETVIBE_PGCRYPTO_KEY', TEST_NETVIBE_PGCRYPTO_KEY)


def create_mikrotik_settings(username='admin', password='secret-pass', retention_days=30):
    ensure_test_pgcrypto_key()
    settings_obj = MikroTikIntegrationSettings(
        username=username,
        run_retention_days=retention_days,
    )
    settings_obj.password_encrypted = encrypt_password(password)
    settings_obj.save()
    return settings_obj


def passthrough(value):
    return value


def mikrotik_metadata(name, version, board_name, platform='MikroTik'):
    return (
        {'name': name},
        {'version': version, 'board-name': board_name, 'platform': platform},
    )


def create_admin_user(username):
    return get_user_model().objects.create_superuser(
        username=username,
        email=f'{username}@example.com',
        password='test-pass-123',
    )


class MikroTikSettingsTests(TestCase):
    def test_singleton_admin_add_permission(self):
        settings_admin = admin.site._registry[MikroTikIntegrationSettings]
        request = RequestFactory().get('/admin/ipam/mikrotikintegrationsettings/')
        request.user = AnonymousUser()

        self.assertTrue(settings_admin.has_add_permission(request))
        create_mikrotik_settings()
        self.assertFalse(settings_admin.has_add_permission(request))

    def test_settings_form_requires_password_on_first_create(self):
        form = MikroTikIntegrationSettingsForm(
            data={
                'username': 'admin',
                'run_retention_days': 15,
                'password': '',
            }
        )
        self.assertFalse(form.is_valid())
        self.assertIn('password', form.errors)

    def test_settings_password_blank_on_edit_keeps_existing_ciphertext(self):
        settings_obj = create_mikrotik_settings(password='initial-pass')
        original_cipher = bytes(settings_obj.password_encrypted)

        form = MikroTikIntegrationSettingsForm(
            instance=settings_obj,
            data={
                'username': 'admin-updated',
                'run_retention_days': 45,
                'password': '',
            },
        )
        self.assertTrue(form.is_valid(), form.errors)
        updated = form.save()

        updated.refresh_from_db()
        self.assertEqual(bytes(updated.password_encrypted), original_cipher)
        self.assertEqual(updated.username, 'admin-updated')
        self.assertEqual(updated.run_retention_days, 45)
        self.assertEqual(decrypt_password(updated.password_encrypted), 'initial-pass')

    def test_settings_password_is_write_only_in_form_html(self):
        settings_obj = create_mikrotik_settings(password='hidden-secret')
        form = MikroTikIntegrationSettingsForm(instance=settings_obj)
        rendered = form.as_p()

        self.assertNotIn('hidden-secret', rendered)
        self.assertIn('type="password"', rendered)


class MikroTikTargetModelTests(TestCase):
    def test_target_requires_ipv4_and_unique_host(self):
        target = MikroTikTarget(host='not-an-ip')
        with self.assertRaises(ValidationError):
            target.full_clean()

        MikroTikTarget.objects.create(host='10.1.1.1')
        duplicate = MikroTikTarget(host='10.1.1.1')
        with self.assertRaises(ValidationError):
            duplicate.full_clean()

    def test_target_host_change_clears_stale_linked_device(self):
        device = MikroTikDevice.objects.create(host='10.2.2.1')
        target = MikroTikTarget.objects.create(host='10.2.2.1', linked_device=device)

        target.host = '10.2.2.2'
        target.save()
        target.refresh_from_db()

        self.assertIsNone(target.linked_device)


class MikroTikDiscoveryFlowTests(TestCase):
    def setUp(self):
        create_mikrotik_settings()

    def _create_targets(self, *hosts):
        return [
            MikroTikTarget.objects.create(host=host)
            for host in hosts
        ]

    def _schedule_discovery(self, queryset):
        with patch(
            'ipam.tasks._enqueue_mikrotik_discovery_run',
            side_effect=passthrough,
        ):
            return schedule_mikrotik_discovery_for_targets(
                queryset,
                created_by=None,
            )

    def _run_discovery_with_metadata(self, run_pk, *, name, version, board_name):
        with patch(
            'ipam.tasks.collect_target_metadata',
            return_value=mikrotik_metadata(name, version, board_name),
        ), patch('ipam.tasks.schedule_mikrotik_sync_now') as schedule_sync_mock:
            run_mikrotik_discovery.call(run_pk)
        return schedule_sync_mock

    def _run_discovery_with_sync_enqueue(self, run_pk, *, name, version, board_name):
        with patch(
            'ipam.tasks.collect_target_metadata',
            return_value=mikrotik_metadata(name, version, board_name),
        ), patch(
            'ipam.tasks._enqueue_mikrotik_sync_run',
            side_effect=passthrough,
        ):
            run_mikrotik_discovery.call(run_pk)

    def test_bulk_scheduler_creates_discovery_run_and_precreates_results(self):
        t1, t2 = self._create_targets('10.10.0.1', '10.10.0.2')

        run, error = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk__in=[t1.pk, t2.pk]),
        )

        self.assertIsNone(error)
        self.assertIsNotNone(run)
        self.assertEqual(run.total_targets, 2)
        self.assertEqual(
            MikroTikDiscoveryResult.objects.filter(run=run).count(),
            2,
        )
        self.assertEqual(
            MikroTikDiscoveryResult.objects.filter(
                run=run,
                status=MikroTikDiscoveryResult.Status.SCHEDULED,
            ).count(),
            2,
        )

    def test_scheduler_blocks_when_another_run_is_active(self):
        target = self._create_targets('10.11.0.1')[0]
        MikroTikDiscoveryRun.objects.create(
            status=MikroTikDiscoveryRun.Status.RUNNING,
            total_targets=1,
        )

        run, error = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )

        self.assertIsNone(run)
        self.assertIn('already active', error)

    def test_discovery_success_creates_device_and_schedules_first_sync(self):
        target = self._create_targets('10.12.0.1')[0]

        run, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )

        self.assertEqual(
            MikroTikDiscoveryResult.objects.filter(
                run=run,
                status=MikroTikDiscoveryResult.Status.SCHEDULED,
            ).count(),
            1,
        )

        schedule_sync_mock = self._run_discovery_with_metadata(
            run.pk,
            name='RTR-12',
            version='7.15.2',
            board_name='RB5009',
        )

        run.refresh_from_db()
        target.refresh_from_db()
        result = MikroTikDiscoveryResult.objects.get(run=run, target=target)
        device = MikroTikDevice.objects.get(host='10.12.0.1')

        self.assertEqual(run.status, MikroTikDiscoveryRun.Status.SUCCESS)
        self.assertEqual(result.status, MikroTikDiscoveryResult.Status.SUCCESS)
        self.assertEqual(target.linked_device_id, device.pk)
        self.assertEqual(device.last_seen_identity, 'RTR-12')
        self.assertEqual(device.last_seen_version, '7.15.2')
        schedule_sync_mock.assert_called_once_with(device)

    def test_rerun_updates_same_device_and_propagates_target_endpoint_config(self):
        target = self._create_targets('10.13.0.1')[0]

        run_one, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_metadata(
            run_one.pk,
            name='RTR-13',
            version='7.14.1',
            board_name='RB4011',
        )

        device = MikroTikDevice.objects.get(host='10.13.0.1')
        device_pk = device.pk

        target.scheme = 'http'
        target.port = 8080
        target.verify_tls = True
        target.save()

        run_two, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_metadata(
            run_two.pk,
            name='RTR-13',
            version='7.16',
            board_name='RB4011',
        )

        device.refresh_from_db()
        self.assertEqual(device.pk, device_pk)
        self.assertEqual(device.scheme, 'http')
        self.assertEqual(device.port, 8080)
        self.assertTrue(device.verify_tls)
        self.assertTrue(device.sync_enabled)

    def test_rerun_does_not_create_duplicate_sync_run_when_one_is_active(self):
        target = self._create_targets('10.13.0.20')[0]

        run_one, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_sync_enqueue(
            run_one.pk,
            name='RTR-13-20',
            version='7.17',
            board_name='RB5009',
        )

        device = MikroTikDevice.objects.get(host='10.13.0.20')
        self.assertEqual(MikroTikSyncRun.objects.filter(device=device).count(), 1)

        run_two, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_sync_enqueue(
            run_two.pk,
            name='RTR-13-20',
            version='7.17.1',
            board_name='RB5009',
        )

        self.assertEqual(MikroTikSyncRun.objects.filter(device=device).count(), 1)

    def test_discovery_never_updates_last_successful_sync_at(self):
        old_sync_time = timezone.now() - timedelta(days=3)
        device = MikroTikDevice.objects.create(
            host='10.13.0.30',
            last_successful_sync_at=old_sync_time,
        )
        target = MikroTikTarget.objects.create(host='10.13.0.30', linked_device=device)

        run, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_metadata(
            run.pk,
            name='RTR-13-30',
            version='7.17',
            board_name='RB4011',
        )

        device.refresh_from_db()
        self.assertEqual(device.last_successful_sync_at, old_sync_time)

    def test_non_v7_routeros_is_blocked_as_failed(self):
        target = self._create_targets('10.14.0.1')[0]

        run, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_metadata(
            run.pk,
            name='RTR-14',
            version='6.49.8',
            board_name='RB750',
        )

        result = MikroTikDiscoveryResult.objects.get(run=run, target=target)
        self.assertEqual(result.status, MikroTikDiscoveryResult.Status.FAILED)
        self.assertEqual(
            result.reason_code,
            MikroTikDiscoveryResult.ReasonCode.UNSUPPORTED_ROUTEROS,
        )
        self.assertFalse(MikroTikDevice.objects.filter(host='10.14.0.1').exists())

    def test_identity_conflict_blocks_creation_and_records_conflicting_device(self):
        existing = MikroTikDevice.objects.create(
            host='10.15.0.10',
            last_seen_identity='RTR-CONFLICT',
        )
        target = self._create_targets('10.15.0.20')[0]

        run, _ = self._schedule_discovery(
            MikroTikTarget.objects.filter(pk=target.pk),
        )
        self._run_discovery_with_metadata(
            run.pk,
            name='RTR-CONFLICT',
            version='7.12',
            board_name='RB5009',
        )

        result = MikroTikDiscoveryResult.objects.get(run=run, target=target)
        self.assertEqual(result.status, MikroTikDiscoveryResult.Status.BLOCKED)
        self.assertEqual(
            result.reason_code,
            MikroTikDiscoveryResult.ReasonCode.IDENTITY_CONFLICT,
        )
        self.assertEqual(result.conflicting_device_id, existing.pk)
        self.assertIn(existing.host, result.detail_message)
        self.assertFalse(MikroTikDevice.objects.filter(host='10.15.0.20').exists())
        self.assertEqual(
            ReviewItem.objects.filter(kind=ReviewItem.Kind.DISCOVERY_CONFLICT).count(),
            1,
        )


class MikroTikAdminTests(TestCase):
    def setUp(self):
        create_mikrotik_settings()
        self.factory = RequestFactory()

    def test_target_admin_queryset_action_redirects_to_run(self):
        user = create_admin_user('admin-action')
        target = MikroTikTarget.objects.create(host='10.20.0.1')
        model_admin = admin.site._registry[MikroTikTarget]

        request = self.factory.post('/admin/ipam/mikrotiktarget/')
        request.user = user
        attach_request_messages(request)

        with patch(
            'ipam.tasks._enqueue_mikrotik_discovery_run',
            side_effect=passthrough,
        ):
            response = model_admin.run_discovery_for_selected_targets(
                request,
                MikroTikTarget.objects.filter(pk=target.pk),
            )

        self.assertEqual(response.status_code, 302)
        self.assertEqual(MikroTikDiscoveryRun.objects.count(), 1)
        self.assertEqual(MikroTikDiscoveryResult.objects.count(), 1)

    def test_device_admin_is_post_onboarding_only(self):
        model_admin = admin.site._registry[MikroTikDevice]
        request = self.factory.get('/admin/ipam/mikrotikdevice/')
        request.user = AnonymousUser()

        self.assertFalse(model_admin.has_add_permission(request))
        self.assertFalse(hasattr(model_admin, 'save_and_test'))
        self.assertNotIn('save_and_test', getattr(model_admin, 'actions_submit_line', ()))

    def test_singleton_admin_permissions_fail_safe_before_migrations(self):
        ipam_settings_admin = admin.site._registry[IPAMSettings]
        mikrotik_settings_admin = admin.site._registry[MikroTikIntegrationSettings]
        request = self.factory.get('/admin/')
        request.user = AnonymousUser()

        with patch(
            'ipam.admin.IPAMSettings.objects.exists',
            side_effect=ProgrammingError('relation "ipam_ipamsettings" does not exist'),
        ):
            self.assertTrue(ipam_settings_admin.has_add_permission(request))

        with patch(
            'ipam.admin.MikroTikIntegrationSettings.objects.exists',
            side_effect=ProgrammingError(
                'relation "ipam_mikrotikintegrationsettings" does not exist'
            ),
        ):
            self.assertTrue(mikrotik_settings_admin.has_add_permission(request))

    def test_old_custom_port_assets_and_fields_are_gone(self):
        static_path = Path('ipam/static/ipam/admin/mikrotik_device_custom_port.js')
        self.assertFalse(static_path.exists())

        form = MikroTikIntegrationSettingsForm()
        self.assertNotIn('custom_port', form.fields)
        self.assertNotIn('is_gateway_router', form.fields)

    def test_sync_run_model_and_admin_are_sync_only(self):
        with self.assertRaises(FieldDoesNotExist):
            MikroTikSyncRun._meta.get_field('mode')

        sync_admin = admin.site._registry[MikroTikSyncRun]
        self.assertNotIn('mode', sync_admin.list_display)


class AdminNavigationTests(TestCase):
    def setUp(self):
        create_mikrotik_settings()
        self.user = create_admin_user('admin-nav')
        self.client.force_login(self.user)

    def test_discovery_overview_page_is_reachable(self):
        response = self.client.get(reverse('admin:ipam_discovery_overview'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'Discovery overview')

    def test_target_device_and_run_detail_actions_remain_available(self):
        target_admin = admin.site._registry[MikroTikTarget]
        device_admin = admin.site._registry[MikroTikDevice]
        discovery_run_admin = admin.site._registry[MikroTikDiscoveryRun]

        self.assertIn('run_discovery_now', target_admin.actions_detail)
        self.assertIn('sync_now_action', device_admin.actions_detail)
        self.assertIn('open_filtered_results', discovery_run_admin.actions_detail)

    def test_sidebar_removes_fragmented_mikrotik_entries(self):
        navigation = settings.UNFOLD['SIDEBAR']['navigation']
        all_titles = {
            str(item['title'])
            for group in navigation
            for item in group['items']
        }

        self.assertIn('Discovery overview', all_titles)
        self.assertIn('Review queue', all_titles)
        self.assertIn('Tags', all_titles)
        self.assertNotIn('MikroTik sync runs', all_titles)
        self.assertNotIn('MikroTik VLANs', all_titles)
        self.assertNotIn('MikroTik subnets', all_titles)


class VLANAdminHierarchyTests(TestCase):
    def setUp(self):
        self.user = create_admin_user('admin-vlan-hierarchy')
        self.client.force_login(self.user)
        self.factory = RequestFactory()

    def _get_vlan_section_class(self):
        vlan_admin = admin.site._registry[VLAN]
        self.assertGreater(len(vlan_admin.list_sections), 0)

        section_class = vlan_admin.list_sections[0]
        if isinstance(section_class, str):
            section_class = import_string(section_class)
        return section_class

    def test_vlan_changelist_loads_successfully(self):
        VLAN.objects.create(number=100, name='Core')

        response = self.client.get(reverse('admin:ipam_vlan_changelist'))

        self.assertEqual(response.status_code, 200)

    def test_vlan_admin_has_expandable_subnet_section_configuration(self):
        section_class = self._get_vlan_section_class()

        self.assertEqual(section_class.related_name, 'subnet_set')
        self.assertIn('cidr_link', section_class.fields)

    def test_vlan_subnets_are_rendered_inside_section_content(self):
        vlan = VLAN.objects.create(number=60, name='Operations')
        subnet_one = Subnet.objects.create(
            vlan=vlan,
            cidr='10.60.0.0/24',
            description='Primary office',
        )
        subnet_two = Subnet.objects.create(
            vlan=vlan,
            cidr='10.60.1.0/24',
        )
        other_vlan = VLAN.objects.create(number=61, name='Guests')
        other_subnet = Subnet.objects.create(vlan=other_vlan, cidr='10.61.0.0/24')

        request = self.factory.get(reverse('admin:ipam_vlan_changelist'))
        request.user = self.user

        section_class = self._get_vlan_section_class()
        section_html = section_class(request, vlan).render()

        self.assertIn(subnet_one.cidr, section_html)
        self.assertIn(subnet_two.cidr, section_html)
        self.assertNotIn(other_subnet.cidr, section_html)
        self.assertIn(reverse('admin:ipam_subnet_change', args=(subnet_one.pk,)), section_html)


class TaggingTests(TestCase):
    def test_predefined_system_tags_exist(self):
        self.assertTrue(Tag.objects.filter(slug='vpn', name='VPN', is_system=True).exists())
        self.assertTrue(Tag.objects.filter(slug='pppoe', name='PPPoE', is_system=True).exists())
        self.assertTrue(
            Tag.objects.filter(slug='wireguard', name='WireGuard', is_system=True).exists()
        )

    def test_vlan_subnet_and_ip_can_all_hold_tags(self):
        vpn_tag = Tag.objects.get(slug='vpn')
        custom_tag = Tag.objects.create(slug='custom', name='Custom')
        vlan = VLAN.objects.create(number=410)
        subnet = Subnet.objects.create(cidr='10.41.0.0/24', vlan=vlan)
        ip_address = IPAddress.objects.create(subnet=subnet, address='10.41.0.10')

        vlan.tags.add(vpn_tag, custom_tag)
        subnet.tags.add(vpn_tag)
        ip_address.tags.add(custom_tag)

        self.assertEqual(set(vlan.tags.values_list('slug', flat=True)), {'vpn', 'custom'})
        self.assertEqual(set(subnet.tags.values_list('slug', flat=True)), {'vpn'})
        self.assertEqual(set(ip_address.tags.values_list('slug', flat=True)), {'custom'})

    def test_system_tags_are_protected_from_admin_queryset_delete(self):
        system_tag = Tag.objects.get(slug='vpn')
        custom_tag = Tag.objects.create(slug='removable', name='Removable')
        tag_admin = admin.site._registry[Tag]
        request = RequestFactory().post('/admin/ipam/tag/')
        request.user = create_admin_user('admin-tag-delete')
        attach_request_messages(request)

        self.assertFalse(tag_admin.has_delete_permission(request, obj=system_tag))
        tag_admin.delete_queryset(request, Tag.objects.filter(pk__in=[system_tag.pk, custom_tag.pk]))

        self.assertTrue(Tag.objects.filter(pk=system_tag.pk).exists())
        self.assertFalse(Tag.objects.filter(pk=custom_tag.pk).exists())


class SubnetAdminEmptyStateTests(TestCase):
    def test_subnet_admin_reports_empty_subnets_clearly(self):
        subnet_admin = admin.site._registry[Subnet]
        subnet = Subnet.objects.create(cidr='10.42.0.0/24')

        state_code, state_label = subnet_admin.subnet_ip_state(subnet)
        overview = subnet_admin._get_overview_context(subnet, [])
        ip_card = next(
            card
            for card in overview['subnet_overview_cards']
            if str(card['title']) == 'IP records'
        )

        self.assertEqual(state_code, 'empty')
        self.assertEqual(str(state_label), 'Empty (no tracked IPs yet)')
        self.assertIn('No tracked IP records yet.', str(ip_card['footer']))


class AdminReadUXTests(TestCase):
    def setUp(self):
        self.user = create_admin_user('admin-read-ux')
        self.client.force_login(self.user)
        self.factory = RequestFactory()

    def test_subnet_changelist_columns_are_essential(self):
        subnet_admin = admin.site._registry[Subnet]

        self.assertEqual(
            subnet_admin.list_display,
            ('cidr', 'vlan_display', 'tag_list'),
        )

    def test_subnet_vlan_label_prefers_name_with_vlan_number_fallback(self):
        subnet_admin = admin.site._registry[Subnet]
        named_vlan = VLAN.objects.create(number=333, name='Operations')
        unnamed_vlan = VLAN.objects.create(number=334, name='')
        named_subnet = Subnet.objects.create(cidr='10.33.3.0/24', vlan=named_vlan)
        unnamed_subnet = Subnet.objects.create(cidr='10.33.4.0/24', vlan=unnamed_vlan)

        self.assertEqual(subnet_admin.vlan_display(named_subnet), 'Operations')
        self.assertEqual(subnet_admin.vlan_display(unnamed_subnet), 'VLAN 334')

    def test_ip_last_seen_formatter_uses_operational_short_format(self):
        vlan = VLAN.objects.create(number=350, name='Support')
        subnet = Subnet.objects.create(cidr='10.35.0.0/24', vlan=vlan)
        ip = IPAddress.objects.create(subnet=subnet, address='10.35.0.10')
        ip_admin = admin.site._registry[IPAddress]

        today_value = timezone.localtime(timezone.now()).replace(second=0, microsecond=0)
        yesterday_value = (today_value - timedelta(days=1)).replace(second=0, microsecond=0)
        same_year_value = (today_value - timedelta(days=30)).replace(second=0, microsecond=0)
        if timezone.localtime(same_year_value).year != timezone.localdate().year:
            same_year_value = today_value.replace(month=6, day=15, hour=9, minute=45)
        different_year_value = (today_value - timedelta(days=400)).replace(
            second=0,
            microsecond=0,
        )

        ip.last_seen = today_value
        self.assertEqual(
            ip_admin.last_seen_display(ip)[0],
            f"Oggi - {timezone.localtime(today_value):%H:%M}",
        )

        ip.last_seen = yesterday_value
        self.assertEqual(
            ip_admin.last_seen_display(ip)[0],
            f"Ieri - {timezone.localtime(yesterday_value):%H:%M}",
        )

        ip.last_seen = same_year_value
        self.assertEqual(
            ip_admin.last_seen_display(ip)[0],
            f"{timezone.localtime(same_year_value):%d/%m} - {timezone.localtime(same_year_value):%H:%M}",
        )

        ip.last_seen = different_year_value
        self.assertEqual(
            ip_admin.last_seen_display(ip)[0],
            f"{timezone.localtime(different_year_value):%d/%m/%y} - {timezone.localtime(different_year_value):%H:%M}",
        )

    def test_ip_admin_default_change_view_is_read_only_overview(self):
        vlan = VLAN.objects.create(number=360, name='Users')
        subnet = Subnet.objects.create(cidr='10.36.0.0/24', vlan=vlan)
        ip = IPAddress.objects.create(subnet=subnet, address='10.36.0.11')
        ip_admin = admin.site._registry[IPAddress]
        change_url = reverse('admin:ipam_ipaddress_change', args=(ip.pk,))

        overview_request = self.factory.get(change_url)
        overview_request.user = self.user
        edit_request = self.factory.get(f'{change_url}?mode=edit')
        edit_request.user = self.user

        self.assertEqual(ip_admin.get_fieldsets(overview_request, obj=ip), ())
        self.assertNotEqual(ip_admin.get_fieldsets(edit_request, obj=ip), ())

        overview_response = self.client.get(change_url)
        self.assertEqual(overview_response.status_code, 200)
        self.assertNotContains(overview_response, 'name="_save"')

        edit_response = self.client.get(f'{change_url}?mode=edit')
        self.assertEqual(edit_response.status_code, 200)
        self.assertContains(edit_response, 'name="_save"')

    def test_ip_admin_back_to_subnet_action_redirects_to_parent_subnet(self):
        vlan = VLAN.objects.create(number=370, name='Infra')
        subnet = Subnet.objects.create(cidr='10.37.0.0/24', vlan=vlan)
        ip = IPAddress.objects.create(subnet=subnet, address='10.37.0.12')
        ip_admin = admin.site._registry[IPAddress]
        request = self.factory.get(reverse('admin:ipam_ipaddress_change', args=(ip.pk,)))
        request.user = self.user

        response = ip_admin.back_to_subnet(request, ip.pk)

        self.assertEqual(response.status_code, 302)
        self.assertEqual(
            response.url,
            reverse('admin:ipam_subnet_change', args=(subnet.pk,)),
        )

    def test_ip_admin_subnet_ip_list_action_redirects_to_filtered_list(self):
        vlan = VLAN.objects.create(number=371, name='Infra')
        subnet = Subnet.objects.create(cidr='10.37.1.0/24', vlan=vlan)
        ip = IPAddress.objects.create(subnet=subnet, address='10.37.1.12')
        ip_admin = admin.site._registry[IPAddress]
        request = self.factory.get(reverse('admin:ipam_ipaddress_change', args=(ip.pk,)))
        request.user = self.user

        response = ip_admin.open_subnet_ip_list(request, ip.pk)

        self.assertEqual(response.status_code, 302)
        self.assertEqual(
            response.url,
            f'{reverse("admin:ipam_ipaddress_changelist")}?subnet__id__exact={subnet.pk}',
        )

    def test_ip_overview_context_includes_tags_and_latest_observed_values(self):
        vlan = VLAN.objects.create(number=372, name='Users')
        subnet = Subnet.objects.create(cidr='10.37.2.0/24', vlan=vlan)
        device = MikroTikDevice.objects.create(host='10.37.2.1')
        tag = Tag.objects.create(slug='ux-tag', name='UX tag')
        ip = IPAddress.objects.create(
            subnet=subnet,
            address='10.37.2.12',
            hostname='canonical-host',
            mac_address='aa:bb:cc:dd:ee:12',
            note='Canonical note',
            source_kind=SourceKind.MIKROTIK,
            source_device=device,
        )
        ip.tags.add(tag)
        IPAddressObservation.objects.create(
            integration_vendor='mikrotik',
            mikrotik_device=device,
            subnet=subnet,
            address='10.37.2.12',
            observed_hostname='observed-host',
            observed_mac_address='aa:bb:cc:dd:ee:99',
            lease_kind=IPAddress.LeaseKind.DHCP_DYNAMIC,
            observed_via_dhcp=True,
            payload_json={
                'source': 'dhcp',
                'lease_comment': 'Observed comment',
            },
        )

        ip_admin = admin.site._registry[IPAddress]
        overview_context = ip_admin._get_overview_context(ip)
        canonical_card = next(
            card
            for card in overview_context['ip_overview_cards']
            if str(card['title']) == 'Canonical data'
        )
        observed_card = next(
            card
            for card in overview_context['ip_overview_cards']
            if str(card['title']) == 'Latest observed data'
        )

        self.assertTrue(
            any(
                str(item['label']) == 'Tags' and 'UX tag' in str(item['value'])
                for item in canonical_card['details']
            )
        )
        self.assertTrue(
            any(
                str(item['label']) == 'Observed hostname'
                and str(item['value']) == 'observed-host'
                for item in observed_card['details']
            )
        )
        self.assertTrue(
            any(
                str(item['label']) == 'Commento lease'
                and str(item['value']) == 'Observed comment'
                for item in observed_card['details']
            )
        )


class MikroTikRetentionTests(TestCase):
    def setUp(self):
        create_mikrotik_settings(retention_days=5)

    def test_pruning_uses_retention_and_keeps_active_runs(self):
        old_time = timezone.now() - timedelta(days=10)
        recent_time = timezone.now() - timedelta(days=1)

        discovery_old = MikroTikDiscoveryRun.objects.create(
            status=MikroTikDiscoveryRun.Status.SUCCESS,
            total_targets=1,
            started_at=old_time,
            finished_at=old_time,
        )
        discovery_active = MikroTikDiscoveryRun.objects.create(
            status=MikroTikDiscoveryRun.Status.RUNNING,
            total_targets=1,
            started_at=recent_time,
        )

        sync_old = MikroTikSyncRun.objects.create(
            status=MikroTikSyncRun.Status.SUCCESS,
            scheduled_for=old_time,
            started_at=old_time,
            finished_at=old_time,
        )
        sync_active = MikroTikSyncRun.objects.create(
            status=MikroTikSyncRun.Status.RUNNING,
            scheduled_for=recent_time,
            started_at=recent_time,
        )

        vlan = VLAN.objects.create(number=401)
        subnet = Subnet.objects.create(vlan=vlan, cidr='10.40.1.0/24', scan_enabled=True)
        old_job = JobRun.objects.create(
            subnet=subnet,
            status=JobRun.Status.SUCCESS,
            scheduled_for=old_time,
            started_at=old_time,
            finished_at=old_time,
        )
        active_job = JobRun.objects.create(
            subnet=subnet,
            status=JobRun.Status.RUNNING,
            scheduled_for=recent_time,
            started_at=recent_time,
        )

        with patch('ipam.management.commands.prune_jobs.call_command'):
            call_command('prune_jobs', days=7)

        self.assertFalse(MikroTikDiscoveryRun.objects.filter(pk=discovery_old.pk).exists())
        self.assertTrue(MikroTikDiscoveryRun.objects.filter(pk=discovery_active.pk).exists())
        self.assertFalse(MikroTikSyncRun.objects.filter(pk=sync_old.pk).exists())
        self.assertTrue(MikroTikSyncRun.objects.filter(pk=sync_active.pk).exists())
        self.assertFalse(JobRun.objects.filter(pk=old_job.pk).exists())
        self.assertTrue(JobRun.objects.filter(pk=active_job.pk).exists())

    def test_prune_command_fails_safe_when_schema_not_ready(self):
        output = StringIO()

        with patch(
            'ipam.management.commands.prune_jobs.JobRun.objects.filter',
            side_effect=ProgrammingError('relation "ipam_jobrun" does not exist'),
        ):
            call_command('prune_jobs', days=7, stdout=output)

        self.assertIn('Skipping prune_jobs because database schema is not ready', output.getvalue())


class MikroTikDeletionTests(TestCase):
    def test_deleting_device_clears_target_link(self):
        device = MikroTikDevice.objects.create(host='10.30.0.1')
        target = MikroTikTarget.objects.create(host='10.30.0.1', linked_device=device)

        device.delete()
        target.refresh_from_db()

        self.assertIsNone(target.linked_device)

    def test_deleting_target_does_not_delete_linked_device(self):
        device = MikroTikDevice.objects.create(host='10.31.0.1')
        target = MikroTikTarget.objects.create(host='10.31.0.1', linked_device=device)

        target.delete()

        self.assertTrue(MikroTikDevice.objects.filter(pk=device.pk).exists())


class MikroTikSyncRegressionTests(TestCase):
    def setUp(self):
        create_mikrotik_settings()

    def test_sync_imports_vlan_subnet_and_ip_data(self):
        device = MikroTikDevice.objects.create(host='10.50.0.1')
        sync_run = MikroTikSyncRun.objects.create(
            device=device,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=timezone.now(),
        )

        class FakeClient:
            def get_identity(self):
                return {'name': 'RTR-50'}

            def get_system_resource(self):
                return {
                    'version': '7.15',
                    'board-name': 'RB5009',
                    'platform': 'MikroTik',
                }

            def get_bridge_vlans(self):
                return [
                    {
                        'vlan-ids': '50',
                        'bridge': 'bridge1',
                        'dynamic': 'false',
                        'disabled': 'false',
                    }
                ]

            def get_ip_addresses(self):
                return [
                    {
                        'address': '10.50.0.1/24',
                        'interface': 'bridge1',
                        'actual-interface': 'bridge1',
                        'dynamic': 'false',
                        'disabled': 'false',
                        'invalid': 'false',
                        '.id': '*1',
                    }
                ]

            def get_dhcp_servers(self):
                return []

            def get_dhcp_leases(self):
                return [
                    {
                        'address': '10.50.0.10',
                        'mac-address': 'AA:BB:CC:DD:EE:01',
                        'host-name': 'host-50',
                        'server': 'dhcp1',
                        'status': 'bound',
                        'dynamic': 'true',
                        '.id': '*2',
                    }
                ]

            def get_arp(self):
                return [
                    {
                        'address': '10.50.0.11',
                        'mac-address': 'AA:BB:CC:DD:EE:02',
                        'interface': 'bridge1',
                        'dynamic': 'true',
                        'complete': 'true',
                    }
                ]

            def get_neighbors(self):
                return []

        with patch('ipam.integrations.mikrotik_sync._make_client_for_device', return_value=FakeClient()):
            run_device_sync(device, sync_run)

        sync_run.refresh_from_db()
        device.refresh_from_db()

        self.assertEqual(sync_run.status, MikroTikSyncRun.Status.SUCCESS)
        self.assertTrue(VLAN.objects.filter(number=50, source_kind=SourceKind.MIKROTIK).exists())
        self.assertTrue(Subnet.objects.filter(cidr='10.50.0.0/24', source_kind=SourceKind.MIKROTIK).exists())
        self.assertTrue(IPAddress.objects.filter(address='10.50.0.10').exists())
        self.assertTrue(IPAddress.objects.filter(address='10.50.0.11').exists())
        self.assertEqual(device.last_seen_identity, 'RTR-50')
        self.assertIsNotNone(device.last_successful_sync_at)

    def _run_sync_with_client_data(
        self,
        device,
        *,
        bridge_vlans=None,
        ip_addresses=None,
    ):
        sync_run = MikroTikSyncRun.objects.create(
            device=device,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=timezone.now(),
        )

        class FakeClient:
            def get_identity(self):
                return {'name': 'RTR-50'}

            def get_system_resource(self):
                return {
                    'version': '7.15',
                    'board-name': 'RB5009',
                    'platform': 'MikroTik',
                }

            def get_bridge_vlans(self):
                return bridge_vlans or []

            def get_ip_addresses(self):
                return ip_addresses or []

            def get_dhcp_servers(self):
                return []

            def get_dhcp_leases(self):
                return []

            def get_arp(self):
                return []

            def get_neighbors(self):
                return []

        with patch(
            'ipam.integrations.mikrotik_sync._make_client_for_device',
            return_value=FakeClient(),
        ):
            run_device_sync(device, sync_run)

        sync_run.refresh_from_db()
        return sync_run

    def test_sync_ignores_dynamic_vlans_except_vlan_1(self):
        device = MikroTikDevice.objects.create(host='10.50.1.1')
        sync_run = self._run_sync_with_client_data(
            device,
            bridge_vlans=[
                {
                    'vlan-ids': '1',
                    'bridge': 'bridge1',
                    'dynamic': 'true',
                    'disabled': 'false',
                },
                {
                    'vlan-ids': '60',
                    'bridge': 'bridge1',
                    'dynamic': 'true',
                    'disabled': 'false',
                },
            ],
        )

        self.assertEqual(sync_run.status, MikroTikSyncRun.Status.SUCCESS)
        self.assertTrue(VLAN.objects.filter(number=1).exists())
        self.assertFalse(VLAN.objects.filter(number=60).exists())

    def test_sync_reuses_existing_manual_vlan_1(self):
        device = MikroTikDevice.objects.create(host='10.50.1.2')
        manual_vlan_1 = VLAN.objects.create(number=1, name='Manual Native VLAN')

        sync_run = self._run_sync_with_client_data(
            device,
            bridge_vlans=[
                {
                    'vlan-ids': '1',
                    'bridge': 'bridge1',
                    'dynamic': 'true',
                    'disabled': 'false',
                }
            ],
        )

        manual_vlan_1.refresh_from_db()
        self.assertEqual(sync_run.status, MikroTikSyncRun.Status.SUCCESS)
        self.assertEqual(VLAN.objects.filter(number=1).count(), 1)
        self.assertEqual(manual_vlan_1.source_kind, SourceKind.MANUAL)
        self.assertIsNone(manual_vlan_1.source_device_id)

    def test_native_vlan_1_links_multiple_imported_subnets(self):
        device = MikroTikDevice.objects.create(host='10.50.2.1')
        sync_run = self._run_sync_with_client_data(
            device,
            bridge_vlans=[
                {
                    'vlan-ids': '1',
                    'bridge': 'bridge1',
                    'dynamic': 'true',
                    'disabled': 'false',
                }
            ],
            ip_addresses=[
                {
                    'address': '10.51.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*511',
                },
                {
                    'address': '10.52.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'vlan1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*521',
                },
            ],
        )

        vlan_1 = VLAN.objects.get(number=1)
        subnet_one = Subnet.objects.get(cidr='10.51.0.0/24')
        subnet_two = Subnet.objects.get(cidr='10.52.0.0/24')

        self.assertEqual(sync_run.status, MikroTikSyncRun.Status.SUCCESS)
        self.assertEqual(subnet_one.vlan_id, vlan_1.pk)
        self.assertEqual(subnet_two.vlan_id, vlan_1.pk)

    def test_native_vlan_1_updates_existing_subnet_only_when_vlan_is_null(self):
        device = MikroTikDevice.objects.create(host='10.50.3.1')
        custom_vlan = VLAN.objects.create(number=300, name='Manual 300')
        existing_with_vlan = Subnet.objects.create(
            cidr='10.53.0.0/24',
            vlan=custom_vlan,
            source_kind=SourceKind.MIKROTIK,
            source_device=device,
        )
        existing_without_vlan = Subnet.objects.create(
            cidr='10.54.0.0/24',
            source_kind=SourceKind.MIKROTIK,
            source_device=device,
        )

        sync_run = self._run_sync_with_client_data(
            device,
            bridge_vlans=[
                {
                    'vlan-ids': '1',
                    'bridge': 'bridge1',
                    'dynamic': 'true',
                    'disabled': 'false',
                }
            ],
            ip_addresses=[
                {
                    'address': '10.53.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*531',
                },
                {
                    'address': '10.54.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*541',
                },
            ],
        )

        existing_with_vlan.refresh_from_db()
        existing_without_vlan.refresh_from_db()
        vlan_1 = VLAN.objects.get(number=1)

        self.assertEqual(sync_run.status, MikroTikSyncRun.Status.SUCCESS)
        self.assertEqual(existing_with_vlan.vlan_id, custom_vlan.pk)
        self.assertEqual(existing_without_vlan.vlan_id, vlan_1.pk)


class MikroTikObservationReviewTests(TestCase):
    def setUp(self):
        create_mikrotik_settings()
        self.device = MikroTikDevice.objects.create(host='10.60.0.1')
        self.vlan = VLAN.objects.create(number=60)
        self.subnet = Subnet.objects.create(
            vlan=self.vlan,
            cidr='10.60.0.0/24',
            source_kind=SourceKind.MANUAL,
        )

    def _run_sync(
        self,
        *,
        dhcp_leases=None,
        arp_entries=None,
        ip_addresses=None,
        raise_client_error=None,
    ):
        sync_run = MikroTikSyncRun.objects.create(
            device=self.device,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=timezone.now(),
        )

        class FakeClient:
            def get_identity(self_inner):
                if raise_client_error:
                    raise raise_client_error
                return {'name': 'RTR-60'}

            def get_system_resource(self_inner):
                return {
                    'version': '7.15',
                    'board-name': 'RB5009',
                    'platform': 'MikroTik',
                }

            def get_bridge_vlans(self_inner):
                return []

            def get_ip_addresses(self_inner):
                return ip_addresses or []

            def get_dhcp_servers(self_inner):
                return []

            def get_dhcp_leases(self_inner):
                return dhcp_leases or []

            def get_arp(self_inner):
                return arp_entries or []

            def get_neighbors(self_inner):
                return []

        with patch(
            'ipam.integrations.mikrotik_sync._make_client_for_device',
            return_value=FakeClient(),
        ):
            run_device_sync(self.device, sync_run)
        sync_run.refresh_from_db()
        return sync_run

    def test_existing_canonical_hostname_is_not_overwritten_by_dhcp_observation(self):
        ip = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.10',
            hostname='canonical-host',
        )

        self._run_sync(
            dhcp_leases=[
                {
                    'address': '10.60.0.10',
                    'host-name': 'observed-host',
                    'mac-address': 'AA:BB:CC:DD:EE:10',
                    'status': 'bound',
                    'server': 'dhcp60',
                    'dynamic': 'true',
                }
            ]
        )

        ip.refresh_from_db()
        self.assertEqual(ip.hostname, 'canonical-host')
        self.assertEqual(
            ReviewItem.objects.filter(kind=ReviewItem.Kind.HOSTNAME_CONFLICT).count(),
            1,
        )

    def test_existing_canonical_mac_is_not_overwritten_by_arp_observation(self):
        ip = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.20',
            mac_address='aa:bb:cc:dd:ee:aa',
        )

        self._run_sync(
            arp_entries=[
                {
                    'address': '10.60.0.20',
                    'mac-address': 'aa:bb:cc:dd:ee:bb',
                    'interface': 'bridge1',
                    'complete': 'true',
                }
            ]
        )

        ip.refresh_from_db()
        self.assertEqual(ip.mac_address, 'aa:bb:cc:dd:ee:aa')
        self.assertEqual(
            ReviewItem.objects.filter(kind=ReviewItem.Kind.MAC_CONFLICT).count(),
            1,
        )

    def test_conflict_review_items_are_deduplicated_on_repeated_sync(self):
        ip = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.30',
            hostname='canonical-host',
            mac_address='aa:bb:cc:dd:ee:30',
        )

        lease_payload = [
            {
                'address': '10.60.0.30',
                'host-name': 'observed-host',
                'mac-address': 'aa:bb:cc:dd:ee:31',
                'status': 'bound',
                'server': 'dhcp60',
                'dynamic': 'true',
            }
        ]
        self._run_sync(dhcp_leases=lease_payload)
        self._run_sync(dhcp_leases=lease_payload)

        ip.refresh_from_db()
        self.assertEqual(ip.hostname, 'canonical-host')
        self.assertEqual(ip.mac_address, 'aa:bb:cc:dd:ee:30')
        self.assertEqual(
            ReviewItem.objects.filter(kind=ReviewItem.Kind.HOSTNAME_CONFLICT).count(),
            1,
        )
        self.assertEqual(
            ReviewItem.objects.filter(kind=ReviewItem.Kind.MAC_CONFLICT).count(),
            1,
        )

    def test_newly_observed_ip_creates_canonical_observation_and_review_item(self):
        self._run_sync(
            dhcp_leases=[
                {
                    'address': '10.60.0.40',
                    'host-name': 'observed-40',
                    'mac-address': 'AA:BB:CC:DD:EE:40',
                    'status': 'bound',
                    'server': 'dhcp60',
                    'dynamic': 'true',
                }
            ]
        )

        ip = IPAddress.objects.get(subnet=self.subnet, address='10.60.0.40')
        observation = IPAddressObservation.objects.get(
            mikrotik_device=self.device,
            address='10.60.0.40',
        )
        review_item = ReviewItem.objects.get(kind=ReviewItem.Kind.NEW_IP_OBSERVED)

        self.assertEqual(ip.hostname, '')
        self.assertEqual(ip.mac_address, '')
        self.assertEqual(ip.source_kind, SourceKind.MIKROTIK)
        self.assertEqual(observation.observed_hostname, 'observed-40')
        self.assertEqual(observation.observed_mac_address, 'aa:bb:cc:dd:ee:40')
        self.assertTrue(observation.observed_via_dhcp)
        self.assertEqual(review_item.ip_address_id, ip.pk)

    def test_dhcp_lease_comment_is_stored_in_observation_not_in_canonical_note(self):
        canonical_ip = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.50',
            note='MSP editorial note',
        )

        self._run_sync(
            dhcp_leases=[
                {
                    'address': '10.60.0.50',
                    'host-name': 'observed-50',
                    'mac-address': 'AA:BB:CC:DD:EE:50',
                    'status': 'bound',
                    'server': 'dhcp60',
                    'dynamic': 'true',
                    'comment': 'Customer CPE',
                }
            ]
        )

        canonical_ip.refresh_from_db()
        observation = IPAddressObservation.objects.get(
            mikrotik_device=self.device,
            address='10.60.0.50',
        )

        self.assertEqual(canonical_ip.note, 'MSP editorial note')
        self.assertEqual(observation.payload_json.get('lease_comment'), 'Customer CPE')

    def test_local_ip_from_address_snapshot_creates_observation_for_existing_canonical_ip(self):
        IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.1',
            source_kind=SourceKind.MIKROTIK,
            source_device=self.device,
        )

        self._run_sync(
            ip_addresses=[
                {
                    'address': '10.60.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*601',
                }
            ]
        )

        observation = IPAddressObservation.objects.get(
            mikrotik_device=self.device,
            address='10.60.0.1',
        )
        self.assertEqual(observation.integration_vendor, 'mikrotik')
        self.assertEqual(observation.subnet_id, self.subnet.pk)
        self.assertEqual(observation.address, '10.60.0.1')
        self.assertEqual(observation.observed_hostname, '')
        self.assertEqual(observation.observed_mac_address, '')
        self.assertEqual(observation.lease_kind, IPAddress.LeaseKind.NONE)
        self.assertFalse(observation.observed_via_dhcp)
        self.assertFalse(observation.observed_via_arp)
        self.assertIsNotNone(observation.last_observed_at)

    def test_local_ip_observation_updates_and_dhcp_arp_observation_behavior_is_unchanged(self):
        IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.1',
            source_kind=SourceKind.MIKROTIK,
            source_device=self.device,
        )
        sync_payload = {
            'ip_addresses': [
                {
                    'address': '10.60.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*601',
                }
            ],
            'dhcp_leases': [
                {
                    'address': '10.60.0.40',
                    'host-name': 'observed-40',
                    'mac-address': 'AA:BB:CC:DD:EE:40',
                    'status': 'bound',
                    'server': 'dhcp60',
                    'dynamic': 'true',
                }
            ],
            'arp_entries': [
                {
                    'address': '10.60.0.41',
                    'mac-address': 'aa:bb:cc:dd:ee:41',
                    'interface': 'bridge1',
                    'complete': 'true',
                }
            ],
        }
        self._run_sync(**sync_payload)

        local_observation = IPAddressObservation.objects.get(
            mikrotik_device=self.device,
            address='10.60.0.1',
        )
        old_last_observed_at = timezone.now() - timedelta(days=1)
        local_observation.last_observed_at = old_last_observed_at
        local_observation.save(update_fields=['last_observed_at'])

        self._run_sync(**sync_payload)

        local_observation.refresh_from_db()
        dhcp_observation = IPAddressObservation.objects.get(
            mikrotik_device=self.device,
            address='10.60.0.40',
        )
        arp_observation = IPAddressObservation.objects.get(
            mikrotik_device=self.device,
            address='10.60.0.41',
        )

        self.assertGreater(local_observation.last_observed_at, old_last_observed_at)
        self.assertFalse(local_observation.observed_via_dhcp)
        self.assertFalse(local_observation.observed_via_arp)
        self.assertTrue(dhcp_observation.observed_via_dhcp)
        self.assertTrue(arp_observation.observed_via_arp)

    def test_local_only_update_preserves_rich_preexisting_observation_fields(self):
        IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.1',
            source_kind=SourceKind.MIKROTIK,
            source_device=self.device,
        )
        observation = IPAddressObservation.objects.create(
            integration_vendor='mikrotik',
            mikrotik_device=self.device,
            subnet=self.subnet,
            address='10.60.0.1',
            observed_hostname='rich-host',
            observed_mac_address='aa:bb:cc:dd:ee:60',
            lease_kind=IPAddress.LeaseKind.DHCP_DYNAMIC,
            lease_status='bound',
            lease_server_name='dhcp60',
            observed_via_dhcp=True,
            observed_via_arp=True,
            payload_json={'source': 'dhcp'},
        )
        old_last_observed_at = timezone.now() - timedelta(days=1)
        observation.last_observed_at = old_last_observed_at
        observation.save(update_fields=['last_observed_at'])

        self._run_sync(
            ip_addresses=[
                {
                    'address': '10.60.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*601',
                }
            ],
            dhcp_leases=[],
            arp_entries=[],
        )

        observation.refresh_from_db()
        self.assertEqual(observation.observed_hostname, 'rich-host')
        self.assertEqual(observation.observed_mac_address, 'aa:bb:cc:dd:ee:60')
        self.assertEqual(observation.lease_kind, IPAddress.LeaseKind.DHCP_DYNAMIC)
        self.assertEqual(observation.lease_status, 'bound')
        self.assertEqual(observation.lease_server_name, 'dhcp60')
        self.assertTrue(observation.observed_via_dhcp)
        self.assertTrue(observation.observed_via_arp)
        self.assertEqual(observation.payload_json, {'source': 'dhcp'})
        self.assertGreater(observation.last_observed_at, old_last_observed_at)

    def test_ip_admin_latest_observed_helpers_use_local_observation_values(self):
        ip = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.1',
            source_kind=SourceKind.MIKROTIK,
            source_device=self.device,
        )
        self._run_sync(
            ip_addresses=[
                {
                    'address': '10.60.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*601',
                }
            ]
        )

        ip_admin = admin.site._registry[IPAddress]
        latest_observation = ip_admin._get_latest_observation(ip)

        self.assertIsNotNone(latest_observation)
        self.assertEqual(latest_observation.mikrotik_device_id, self.device.pk)
        self.assertEqual(ip_admin.latest_observed_hostname(ip), '-')
        self.assertEqual(ip_admin.latest_observed_mac_address(ip), '-')
        self.assertEqual(ip_admin.latest_observed_via(ip), '-')
        self.assertEqual(ip_admin.latest_observed_lease(ip), 'Statico')
        self.assertEqual(ip_admin.latest_observed_lease_comment(ip), '-')
        self.assertNotEqual(ip_admin.latest_observed_at(ip), '-')
        self.assertNotEqual(ip_admin.latest_observed_device(ip), '-')

    def test_ip_admin_observed_lease_mode_hides_status_and_server(self):
        ip = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.60.0.70',
            source_kind=SourceKind.MIKROTIK,
            source_device=self.device,
        )
        IPAddressObservation.objects.create(
            integration_vendor='mikrotik',
            mikrotik_device=self.device,
            subnet=self.subnet,
            address='10.60.0.70',
            lease_kind=IPAddress.LeaseKind.DHCP_STATIC,
            lease_status='bound',
            lease_server_name='dhcp60',
            payload_json={
                'source': 'dhcp',
                'status': 'bound',
                'server': 'dhcp60',
                'lease_comment': 'Desk phone',
            },
        )

        ip_admin = admin.site._registry[IPAddress]

        lease_mode = ip_admin.latest_observed_lease(ip)
        self.assertEqual(lease_mode, 'DHCP lease statico')
        self.assertNotIn('bound', lease_mode)
        self.assertNotIn('dhcp60', lease_mode)
        self.assertEqual(ip_admin.latest_observed_lease_comment(ip), 'Desk phone')

    def test_imported_mikrotik_subnets_default_to_scan_and_discovery_disabled(self):
        self._run_sync(
            ip_addresses=[
                {
                    'address': '10.61.0.1/24',
                    'interface': 'bridge1',
                    'actual-interface': 'bridge1',
                    'disabled': 'false',
                    'invalid': 'false',
                    '.id': '*61',
                }
            ]
        )

        imported_subnet = Subnet.objects.get(cidr='10.61.0.0/24')
        self.assertEqual(imported_subnet.source_kind, SourceKind.MIKROTIK)
        self.assertFalse(imported_subnet.scan_enabled)
        self.assertFalse(imported_subnet.discover_subnet)

    def test_failed_sync_creates_and_updates_sync_failure_review_item(self):
        self._run_sync(
            raise_client_error=MikroTikConnectionError('connection failed'),
        )
        self._run_sync(
            raise_client_error=MikroTikConnectionError('connection failed again'),
        )

        self.assertEqual(
            ReviewItem.objects.filter(kind=ReviewItem.Kind.SYNC_FAILURE).count(),
            1,
        )

class SubnetScanRegressionTests(TestCase):
    def setUp(self):
        self.vlan = VLAN.objects.create(number=999)
        self.subnet = Subnet.objects.create(
            vlan=self.vlan,
            cidr='10.99.0.0/30',
            scan_enabled=True,
            discover_subnet=False,
        )

    def _run_scan(self):
        job_run = JobRun.objects.create(
            subnet=self.subnet,
            scheduled_for=timezone.now(),
        )

        with patch('ipam.tasks._ping_host', return_value=True), patch(
            'ipam.tasks._get_mac_address',
            return_value='aa:bb:cc:dd:ee:ff',
        ), patch('ipam.tasks._get_hostname', return_value='host-99.local'), patch(
            'ipam.tasks._enqueue_job_run',
            side_effect=lambda jr: fake_enqueue(jr, 'next-task'),
        ):
            run_subnet_scan.call(job_run.pk)

        job_run.refresh_from_db()
        return job_run

    def test_icmp_scan_on_existing_ip_does_not_overwrite_canonical_hostname(self):
        ip_record = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.99.0.1',
            hostname='canonical-host',
        )

        self._run_scan()

        ip_record.refresh_from_db()
        self.assertEqual(ip_record.hostname, 'canonical-host')

    def test_icmp_scan_on_existing_ip_does_not_overwrite_canonical_mac(self):
        ip_record = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.99.0.1',
            mac_address='aa:bb:cc:dd:ee:01',
        )

        self._run_scan()

        ip_record.refresh_from_db()
        self.assertEqual(ip_record.mac_address, 'aa:bb:cc:dd:ee:01')

    def test_icmp_scan_updates_last_seen_for_existing_ip(self):
        old_last_seen = timezone.now() - timedelta(days=2)
        ip_record = IPAddress.objects.create(
            subnet=self.subnet,
            address='10.99.0.1',
            last_seen=old_last_seen,
        )

        job_run = self._run_scan()

        ip_record.refresh_from_db()
        self.assertEqual(job_run.status, JobRun.Status.SUCCESS)
        self.assertGreater(job_run.hosts_pinged, 0)
        self.assertGreater(ip_record.last_seen, old_last_seen)

    def test_discover_subnet_creation_keeps_canonical_identity_fields_blank(self):
        self.subnet.discover_subnet = True
        self.subnet.save(update_fields=['discover_subnet'])

        job_run = self._run_scan()
        created_ips = list(IPAddress.objects.filter(subnet=self.subnet).order_by('address'))

        self.assertEqual(job_run.status, JobRun.Status.SUCCESS)
        self.assertEqual(job_run.ip_records_created, 2)
        self.assertEqual(len(created_ips), 2)
        self.assertTrue(all(ip.hostname == '' for ip in created_ips))
        self.assertTrue(all(ip.mac_address == '' for ip in created_ips))
        self.assertTrue(all(ip.last_seen is not None for ip in created_ips))


class CompatibilitySmokeTests(SimpleTestCase):
    def test_core_admin_registrations_exist(self):
        self.assertIn(Tag, admin.site._registry)
        self.assertIn(VLAN, admin.site._registry)
        self.assertIn(Subnet, admin.site._registry)
        self.assertIn(IPAddress, admin.site._registry)
        self.assertIn(IPAMSettings, admin.site._registry)
        self.assertIn(JobRun, admin.site._registry)
        self.assertIn(MikroTikIntegrationSettings, admin.site._registry)
        self.assertIn(MikroTikTarget, admin.site._registry)
        self.assertIn(MikroTikDiscoveryRun, admin.site._registry)
        self.assertIn(MikroTikDevice, admin.site._registry)
        self.assertIn(MikroTikSyncRun, admin.site._registry)
        self.assertIn(ReviewItem, admin.site._registry)
