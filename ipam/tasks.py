from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import timedelta
from ipaddress import ip_address
import re
import socket
import subprocess

from django.db import transaction
from django.tasks import task
from django.utils import timezone

from .integrations.mikrotik_client import (
    MikroTikAuthError,
    MikroTikConnectionError,
    MikroTikForbiddenError,
    MikroTikHTTPError,
    MikroTikTLSError,
    MikroTikTimeoutError,
)
from .integrations.mikrotik_sync import (
    collect_target_metadata,
    is_routeros_v7,
    load_global_credentials,
    run_device_sync,
    sanitize_detail_message,
)
from .models import (
    IPAMSettings,
    IPAddress,
    JobRun,
    MikroTikDevice,
    MikroTikDiscoveryResult,
    MikroTikDiscoveryRun,
    MikroTikSyncRun,
    MikroTikTarget,
    Subnet,
)


ACTIVE_JOB_STATUSES = (
    JobRun.Status.SCHEDULED,
    JobRun.Status.RUNNING,
)

MIKROTIK_SYNC_ACTIVE_STATUSES = (
    MikroTikSyncRun.Status.SCHEDULED,
    MikroTikSyncRun.Status.RUNNING,
)

MIKROTIK_DISCOVERY_ACTIVE_STATUSES = (
    MikroTikDiscoveryRun.Status.SCHEDULED,
    MikroTikDiscoveryRun.Status.RUNNING,
)

MIKROTIK_DISCOVERY_MAX_TARGETS = 100
MIKROTIK_DISCOVERY_MAX_WORKERS = 10


def _normalize_schedule_time(value):
    return value.replace(microsecond=0)


# =====================================================================
# Subnet scan scheduling
# =====================================================================

def _enqueue_job_run(job_run):
    if job_run.task_ref:
        return job_run

    task_result = run_subnet_scan.using(run_after=job_run.scheduled_for).enqueue(
        job_run_id=job_run.pk
    )
    job_run.task_ref = str(task_result.id)
    job_run.save(update_fields=('task_ref',))
    return job_run


def schedule_subnet_scan_now(subnet):
    subnet_id = subnet.pk if isinstance(subnet, Subnet) else int(subnet)
    return _schedule_subnet_job_at(
        subnet_id=subnet_id,
        scheduled_for=_normalize_schedule_time(timezone.now()),
    )


def schedule_next_subnet_scan(job_run, finished_at):
    with transaction.atomic():
        subnet = Subnet.objects.select_for_update().get(pk=job_run.subnet_id)
        if not subnet.scan_enabled:
            return None

        existing_job = (
            JobRun.objects.select_for_update()
            .filter(
                subnet_id=subnet.pk,
                job_type=JobRun.JobType.SUBNET_SCAN,
                status__in=ACTIVE_JOB_STATUSES,
            )
            .exclude(pk=job_run.pk)
            .order_by('scheduled_for')
            .first()
        )
        if existing_job is not None:
            return _enqueue_job_run(existing_job)

        interval_minutes = subnet.get_effective_scan_interval_minutes()
        if not interval_minutes:
            return None

        scheduled_for = _normalize_schedule_time(
            finished_at + timedelta(minutes=interval_minutes)
        )
        scheduled_job, _ = JobRun.objects.get_or_create(
            subnet=subnet,
            job_type=JobRun.JobType.SUBNET_SCAN,
            scheduled_for=scheduled_for,
            defaults={
                'status': JobRun.Status.SCHEDULED,
            },
        )
        return _enqueue_job_run(scheduled_job)


def _schedule_subnet_job_at(subnet_id, scheduled_for):
    with transaction.atomic():
        subnet = Subnet.objects.select_for_update().get(pk=subnet_id)
        if not subnet.scan_enabled:
            return None

        existing_job = (
            JobRun.objects.select_for_update()
            .filter(
                subnet_id=subnet.pk,
                job_type=JobRun.JobType.SUBNET_SCAN,
                status__in=ACTIVE_JOB_STATUSES,
            )
            .order_by('scheduled_for')
            .first()
        )
        if existing_job is not None:
            return _enqueue_job_run(existing_job)

        scheduled_job, _ = JobRun.objects.get_or_create(
            subnet=subnet,
            job_type=JobRun.JobType.SUBNET_SCAN,
            scheduled_for=scheduled_for,
            defaults={
                'status': JobRun.Status.SCHEDULED,
            },
        )
        return _enqueue_job_run(scheduled_job)


def _finalize_job_run(
    job_run_id,
    *,
    status,
    finished_at,
    hosts_total=0,
    hosts_pinged=0,
    hosts_alive=0,
    ip_records_created=0,
    ip_records_updated=0,
    error_message='',
):
    with transaction.atomic():
        job_run = JobRun.objects.select_for_update().get(pk=job_run_id)
        job_run.status = status
        job_run.finished_at = finished_at
        job_run.hosts_total = hosts_total
        job_run.hosts_pinged = hosts_pinged
        job_run.hosts_alive = hosts_alive
        job_run.ip_records_created = ip_records_created
        job_run.ip_records_updated = ip_records_updated
        job_run.error_message = error_message
        job_run.save(
            update_fields=(
                'status',
                'finished_at',
                'hosts_total',
                'hosts_pinged',
                'hosts_alive',
                'ip_records_created',
                'ip_records_updated',
                'error_message',
            )
        )
        return job_run


def _ping_host(address, timeout_ms):
    try:
        completed = subprocess.run(
            ['ping', '-n', '-c', '1', address],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=max(timeout_ms / 1000, 0.001),
        )
    except (OSError, subprocess.TimeoutExpired):
        return False

    return completed.returncode == 0


def _get_mac_address(address):
    try:
        completed = subprocess.run(
            ['ip', 'neigh', 'show', address],
            check=False,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
        )
    except OSError:
        return ''

    if completed.returncode != 0:
        return ''

    match = re.search(
        r'\blladdr\s+([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})\b',
        completed.stdout or '',
    )
    if match is None:
        return ''

    return match.group(1).lower()


def _get_hostname(address):
    try:
        hostname, _, _ = socket.gethostbyaddr(address)
    except (OSError, socket.herror, socket.gaierror):
        return ''

    return hostname


def _scan_subnet(subnet, network, settings_obj):
    host_addresses = [str(address) for address in network.hosts()]
    ip_records = list(
        IPAddress.objects.filter(subnet_id=subnet.pk).only(
            'id',
            'address',
            'hostname',
            'last_seen',
            'mac_address',
            'exclude_ping',
        )
    )
    records_by_address = {record.address: record for record in ip_records}
    excluded_addresses = {
        record.address for record in ip_records if record.exclude_ping
    }
    ping_targets = [
        address for address in host_addresses if address not in excluded_addresses
    ]

    successful_addresses = []
    max_workers = max(settings_obj.ping_concurrency, 1)

    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        future_map = {
            executor.submit(_ping_host, address, settings_obj.ping_timeout_ms): address
            for address in ping_targets
        }
        for future in as_completed(future_map):
            if future.result():
                successful_addresses.append(future_map[future])

    def _collect_host_info(address):
        return address, _get_mac_address(address), _get_hostname(address)

    host_info = {}
    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        futures = {
            executor.submit(_collect_host_info, addr): addr
            for addr in successful_addresses
        }
        for future in as_completed(futures):
            addr, mac, hostname = future.result()
            host_info[addr] = (mac, hostname)

    observed_at = timezone.now()
    existing_updates = []
    new_records = []

    for address in successful_addresses:
        mac_address, hostname = host_info[address]
        ip_record = records_by_address.get(address)
        if ip_record is not None:
            ip_record.last_seen = observed_at
            if mac_address:
                ip_record.mac_address = mac_address
            if hostname:
                ip_record.hostname = hostname
            existing_updates.append(ip_record)
            continue

        if subnet.discover_subnet:
            new_records.append(
                IPAddress(
                    subnet=subnet,
                    address=address,
                    hostname=hostname,
                    last_seen=observed_at,
                    mac_address=mac_address,
                )
            )

    if existing_updates:
        IPAddress.objects.bulk_update(
            existing_updates,
            ('last_seen', 'mac_address', 'hostname'),
        )
    if new_records:
        IPAddress.objects.bulk_create(new_records)

    return {
        'hosts_total': len(host_addresses),
        'hosts_pinged': len(ping_targets),
        'hosts_alive': len(successful_addresses),
        'ip_records_created': len(new_records),
        'ip_records_updated': len(existing_updates),
    }


SCAN_MIN_PREFIX_LENGTH = 21


@task
def run_subnet_scan(job_run_id: int):
    subnet = None

    with transaction.atomic():
        job_run = JobRun.objects.select_for_update().get(pk=job_run_id)
        if job_run.status != JobRun.Status.SCHEDULED:
            return

        job_run.status = JobRun.Status.RUNNING
        job_run.started_at = timezone.now()
        job_run.error_message = ''
        job_run.save(update_fields=('status', 'started_at', 'error_message'))
        subnet_id = job_run.subnet_id

    try:
        subnet = Subnet.objects.get(pk=subnet_id)
        settings_obj = subnet.get_ipam_settings()

        if not subnet.scan_enabled:
            _finalize_job_run(
                job_run_id,
                status=JobRun.Status.SKIPPED_DISABLED,
                finished_at=timezone.now(),
            )
            return

        network = subnet.network_object
        if network.prefixlen < SCAN_MIN_PREFIX_LENGTH:
            completed_job = _finalize_job_run(
                job_run_id,
                status=JobRun.Status.SKIPPED_GUARDRAIL,
                finished_at=timezone.now(),
            )
            schedule_next_subnet_scan(completed_job, completed_job.finished_at)
            return

        counters = _scan_subnet(subnet, network, settings_obj)
        completed_job = _finalize_job_run(
            job_run_id,
            status=JobRun.Status.SUCCESS,
            finished_at=timezone.now(),
            **counters,
        )
        schedule_next_subnet_scan(completed_job, completed_job.finished_at)
    except Exception as exc:
        error_message = f'{exc.__class__.__name__}: {exc}'[:2000]
        completed_job = _finalize_job_run(
            job_run_id,
            status=JobRun.Status.FAILED,
            finished_at=timezone.now(),
            error_message=error_message,
        )
        if subnet is None:
            subnet = Subnet.objects.filter(pk=completed_job.subnet_id).first()
        if subnet is not None and subnet.scan_enabled:
            schedule_next_subnet_scan(completed_job, completed_job.finished_at)
        raise


# =====================================================================
# MikroTik discovery scheduling + execution
# =====================================================================

def schedule_mikrotik_discovery_for_targets(target_queryset, *, created_by=None):
    target_ids = list(target_queryset.values_list('pk', flat=True))
    if not target_ids:
        return None, 'Select at least one target to run discovery.'

    if len(target_ids) > MIKROTIK_DISCOVERY_MAX_TARGETS:
        return (
            None,
            f'You can select at most {MIKROTIK_DISCOVERY_MAX_TARGETS} targets per run.',
        )

    with transaction.atomic():
        active_run = (
            MikroTikDiscoveryRun.objects.select_for_update()
            .filter(status__in=MIKROTIK_DISCOVERY_ACTIVE_STATUSES)
            .order_by('created_at')
            .first()
        )
        if active_run is not None:
            return (
                None,
                f'Discovery run #{active_run.pk} is already active. Wait for completion.',
            )

        targets = list(
            MikroTikTarget.objects.select_for_update()
            .filter(pk__in=target_ids)
            .order_by('pk')
        )
        if not targets:
            return None, 'No valid targets found for discovery.'

        created_by_user = (
            created_by
            if getattr(created_by, 'is_authenticated', False)
            else None
        )

        run = MikroTikDiscoveryRun.objects.create(
            status=MikroTikDiscoveryRun.Status.SCHEDULED,
            created_by=created_by_user,
            total_targets=len(targets),
        )

        MikroTikDiscoveryResult.objects.bulk_create(
            [
                MikroTikDiscoveryResult(
                    run=run,
                    target=target,
                    status=MikroTikDiscoveryResult.Status.SCHEDULED,
                    action_taken=MikroTikDiscoveryResult.ActionTaken.NONE,
                )
                for target in targets
            ]
        )

    run = _enqueue_mikrotik_discovery_run(run)
    return run, None


def _enqueue_mikrotik_discovery_run(run: MikroTikDiscoveryRun) -> MikroTikDiscoveryRun:
    if run.task_ref:
        return run

    task_result = run_mikrotik_discovery.using(run_after=timezone.now()).enqueue(
        discovery_run_id=run.pk
    )
    run.task_ref = str(task_result.id)
    run.save(update_fields=('task_ref',))
    return run


def _map_discovery_exception(exc: Exception) -> tuple[str, str]:
    message = sanitize_detail_message(str(exc))

    if isinstance(exc, MikroTikTimeoutError):
        return MikroTikDiscoveryResult.ReasonCode.TIMEOUT, message
    if isinstance(exc, MikroTikTLSError):
        return MikroTikDiscoveryResult.ReasonCode.TLS_ERROR, message
    if isinstance(exc, MikroTikAuthError):
        return MikroTikDiscoveryResult.ReasonCode.AUTH_FAILED, message
    if isinstance(exc, MikroTikForbiddenError):
        return MikroTikDiscoveryResult.ReasonCode.FORBIDDEN, message
    if isinstance(exc, MikroTikHTTPError):
        return MikroTikDiscoveryResult.ReasonCode.HTTP_ERROR, message
    if isinstance(exc, MikroTikConnectionError):
        if 'refused' in message.lower():
            return MikroTikDiscoveryResult.ReasonCode.CONNECTION_REFUSED, message
        return MikroTikDiscoveryResult.ReasonCode.CONNECTION_ERROR, message

    return MikroTikDiscoveryResult.ReasonCode.UNEXPECTED_ERROR, message


def _probe_target(
    *,
    result_id: int,
    target_id: int,
    host: str,
    scheme: str,
    port: int,
    verify_tls: bool,
    username: str,
    password: str,
) -> dict:
    try:
        ip_address(host)
    except ValueError:
        return {
            'result_id': result_id,
            'target_id': target_id,
            'ok': False,
            'reason_code': MikroTikDiscoveryResult.ReasonCode.INVALID_TARGET,
            'detail_message': 'Target host must be a valid IPv4 address.',
            'status': MikroTikDiscoveryResult.Status.FAILED,
            'action_taken': MikroTikDiscoveryResult.ActionTaken.NONE,
        }

    try:
        identity, resource = collect_target_metadata(
            host=host,
            scheme=scheme,
            port=port,
            verify_tls=verify_tls,
            username=username,
            password=password,
        )
    except Exception as exc:  # noqa: BLE001 - normalized into reason codes below
        reason_code, message = _map_discovery_exception(exc)
        return {
            'result_id': result_id,
            'target_id': target_id,
            'ok': False,
            'reason_code': reason_code,
            'detail_message': message,
            'status': MikroTikDiscoveryResult.Status.FAILED,
            'action_taken': MikroTikDiscoveryResult.ActionTaken.NONE,
        }

    version = (resource or {}).get('version', '')
    if not is_routeros_v7(version):
        return {
            'result_id': result_id,
            'target_id': target_id,
            'ok': False,
            'reason_code': MikroTikDiscoveryResult.ReasonCode.UNSUPPORTED_ROUTEROS,
            'detail_message': sanitize_detail_message(
                f'Unsupported RouterOS version "{version}". Only RouterOS v7 is supported.'
            ),
            'status': MikroTikDiscoveryResult.Status.FAILED,
            'action_taken': MikroTikDiscoveryResult.ActionTaken.NONE,
            'seen_identity': (identity or {}).get('name', ''),
            'seen_version': version,
            'seen_board': (resource or {}).get('board-name', ''),
            'seen_platform': (resource or {}).get('platform', ''),
        }

    return {
        'result_id': result_id,
        'target_id': target_id,
        'ok': True,
        'seen_identity': (identity or {}).get('name', ''),
        'seen_version': version,
        'seen_board': (resource or {}).get('board-name', ''),
        'seen_platform': (resource or {}).get('platform', ''),
    }


def _update_target_discovery_state(
    target: MikroTikTarget,
    *,
    status: str,
    reason_code: str,
    detail_message: str,
    seen_identity: str = '',
    seen_version: str = '',
    seen_board: str = '',
    seen_platform: str = '',
) -> None:
    target.last_discovered_at = timezone.now()
    target.last_discovery_status = status
    target.last_reason_code = reason_code
    target.last_message = detail_message
    target.last_seen_identity = seen_identity
    target.last_seen_version = seen_version
    target.last_seen_board = seen_board
    target.last_seen_platform = seen_platform
    target.save(update_fields=[
        'last_discovered_at',
        'last_discovery_status',
        'last_reason_code',
        'last_message',
        'last_seen_identity',
        'last_seen_version',
        'last_seen_board',
        'last_seen_platform',
    ])


def _apply_discovery_failure(outcome: dict) -> dict:
    with transaction.atomic():
        result = MikroTikDiscoveryResult.objects.select_for_update().get(
            pk=outcome['result_id']
        )
        target = (
            MikroTikTarget.objects.select_for_update()
            .filter(pk=outcome['target_id'])
            .first()
        )

        reason_code = outcome['reason_code']
        status = outcome.get('status', MikroTikDiscoveryResult.Status.FAILED)
        detail_message = sanitize_detail_message(outcome.get('detail_message', ''))

        result.status = status
        result.reason_code = reason_code
        result.detail_message = detail_message
        result.seen_identity = outcome.get('seen_identity', '')
        result.seen_version = outcome.get('seen_version', '')
        result.seen_board = outcome.get('seen_board', '')
        result.seen_platform = outcome.get('seen_platform', '')
        result.action_taken = outcome.get(
            'action_taken', MikroTikDiscoveryResult.ActionTaken.NONE
        )
        result.finished_at = timezone.now()
        result.save(update_fields=[
            'status',
            'reason_code',
            'detail_message',
            'seen_identity',
            'seen_version',
            'seen_board',
            'seen_platform',
            'action_taken',
            'finished_at',
        ])

        if target is not None:
            _update_target_discovery_state(
                target,
                status=status,
                reason_code=reason_code,
                detail_message=detail_message,
                seen_identity=result.seen_identity,
                seen_version=result.seen_version,
                seen_board=result.seen_board,
                seen_platform=result.seen_platform,
            )

    return {'status': status, 'action_taken': result.action_taken}


def _apply_discovery_success(outcome: dict) -> dict:
    with transaction.atomic():
        result = MikroTikDiscoveryResult.objects.select_for_update().get(
            pk=outcome['result_id']
        )
        target = MikroTikTarget.objects.select_for_update().get(pk=outcome['target_id'])

        seen_identity = outcome.get('seen_identity', '')
        seen_version = outcome.get('seen_version', '')
        seen_board = outcome.get('seen_board', '')
        seen_platform = outcome.get('seen_platform', '')

        conflicting_device = None
        if seen_identity:
            conflicting_device = (
                MikroTikDevice.objects.filter(last_seen_identity=seen_identity)
                .exclude(host=target.host)
                .order_by('pk')
                .first()
            )

        if conflicting_device is not None:
            message = sanitize_detail_message(
                f'Identity "{seen_identity}" already belongs to device {conflicting_device.host}. '
                'Manual resolution required before retrying discovery.'
            )

            result.status = MikroTikDiscoveryResult.Status.BLOCKED
            result.reason_code = MikroTikDiscoveryResult.ReasonCode.IDENTITY_CONFLICT
            result.detail_message = message
            result.seen_identity = seen_identity
            result.seen_version = seen_version
            result.seen_board = seen_board
            result.seen_platform = seen_platform
            result.action_taken = MikroTikDiscoveryResult.ActionTaken.BLOCKED_MANUAL_RESOLUTION
            result.conflicting_device = conflicting_device
            result.finished_at = timezone.now()
            result.save(update_fields=[
                'status',
                'reason_code',
                'detail_message',
                'seen_identity',
                'seen_version',
                'seen_board',
                'seen_platform',
                'action_taken',
                'conflicting_device',
                'finished_at',
            ])

            _update_target_discovery_state(
                target,
                status=MikroTikTarget.LastStatus.BLOCKED,
                reason_code=MikroTikDiscoveryResult.ReasonCode.IDENTITY_CONFLICT,
                detail_message=message,
                seen_identity=seen_identity,
                seen_version=seen_version,
                seen_board=seen_board,
                seen_platform=seen_platform,
            )

            return {
                'status': MikroTikDiscoveryResult.Status.BLOCKED,
                'action_taken': MikroTikDiscoveryResult.ActionTaken.BLOCKED_MANUAL_RESOLUTION,
            }

        device = MikroTikDevice.objects.filter(host=target.host).first()
        created = device is None
        changed = False

        if device is None:
            device = MikroTikDevice(
                host=target.host,
                scheme=target.scheme,
                port=target.port,
                verify_tls=target.verify_tls,
                enabled=True,
                sync_enabled=True,
            )
            changed = True
        else:
            if device.scheme != target.scheme:
                device.scheme = target.scheme
                changed = True
            if device.port != target.port:
                device.port = target.port
                changed = True
            if device.verify_tls != target.verify_tls:
                device.verify_tls = target.verify_tls
                changed = True
            if not device.enabled:
                device.enabled = True
                changed = True
            if not device.sync_enabled:
                device.sync_enabled = True
                changed = True

        if not device.name:
            desired_name = seen_identity or target.host
            if device.name != desired_name:
                device.name = desired_name
                changed = True

        for field_name, value in (
            ('last_seen_identity', seen_identity),
            ('last_seen_version', seen_version),
            ('last_seen_board', seen_board),
            ('last_seen_platform', seen_platform),
        ):
            if getattr(device, field_name) != value:
                setattr(device, field_name, value)
                changed = True

        if device.last_error:
            device.last_error = ''
            changed = True

        if created or changed:
            device.save()

        action_taken = MikroTikDiscoveryResult.ActionTaken.REUSED_EXISTING_DEVICE
        if created:
            action_taken = MikroTikDiscoveryResult.ActionTaken.CREATED_DEVICE
        elif changed:
            action_taken = MikroTikDiscoveryResult.ActionTaken.UPDATED_EXISTING_DEVICE

        target.linked_device = device
        target.save(update_fields=['linked_device'])
        _update_target_discovery_state(
            target,
            status=MikroTikTarget.LastStatus.SUCCESS,
            reason_code='',
            detail_message='Discovery succeeded.',
            seen_identity=seen_identity,
            seen_version=seen_version,
            seen_board=seen_board,
            seen_platform=seen_platform,
        )

        result.status = MikroTikDiscoveryResult.Status.SUCCESS
        result.reason_code = ''
        result.detail_message = 'Discovery succeeded.'
        result.seen_identity = seen_identity
        result.seen_version = seen_version
        result.seen_board = seen_board
        result.seen_platform = seen_platform
        result.action_taken = action_taken
        result.linked_device = device
        result.conflicting_device = None
        result.finished_at = timezone.now()
        result.save(update_fields=[
            'status',
            'reason_code',
            'detail_message',
            'seen_identity',
            'seen_version',
            'seen_board',
            'seen_platform',
            'action_taken',
            'linked_device',
            'conflicting_device',
            'finished_at',
        ])

    schedule_mikrotik_sync_now(device)
    return {'status': result.status, 'action_taken': action_taken}


def _increment_discovery_counters(run: MikroTikDiscoveryRun, status: str, action_taken: str) -> None:
    if status == MikroTikDiscoveryResult.Status.SUCCESS:
        run.success_count += 1
    elif status == MikroTikDiscoveryResult.Status.BLOCKED:
        run.blocked_count += 1
    else:
        run.failed_count += 1

    if action_taken == MikroTikDiscoveryResult.ActionTaken.CREATED_DEVICE:
        run.created_count += 1
    elif action_taken == MikroTikDiscoveryResult.ActionTaken.UPDATED_EXISTING_DEVICE:
        run.updated_count += 1
    elif action_taken == MikroTikDiscoveryResult.ActionTaken.REUSED_EXISTING_DEVICE:
        run.reused_count += 1

    run.save(update_fields=[
        'success_count',
        'failed_count',
        'blocked_count',
        'created_count',
        'updated_count',
        'reused_count',
    ])


def _finalize_discovery_run(run: MikroTikDiscoveryRun) -> None:
    if run.success_count == run.total_targets and run.total_targets > 0:
        run.status = MikroTikDiscoveryRun.Status.SUCCESS
    elif run.success_count > 0:
        run.status = MikroTikDiscoveryRun.Status.PARTIAL
    else:
        run.status = MikroTikDiscoveryRun.Status.FAILED

    run.finished_at = timezone.now()
    run.save(update_fields=['status', 'finished_at'])


@task
def run_mikrotik_discovery(discovery_run_id: int):
    with transaction.atomic():
        run = MikroTikDiscoveryRun.objects.select_for_update().get(pk=discovery_run_id)
        if run.status != MikroTikDiscoveryRun.Status.SCHEDULED:
            return

        run.status = MikroTikDiscoveryRun.Status.RUNNING
        run.started_at = timezone.now()
        run.save(update_fields=['status', 'started_at'])

    results = list(
        MikroTikDiscoveryResult.objects.filter(run=run)
        .select_related('target')
        .order_by('pk')
    )

    if not results:
        run.refresh_from_db()
        run.status = MikroTikDiscoveryRun.Status.FAILED
        run.finished_at = timezone.now()
        run.save(update_fields=['status', 'finished_at'])
        return

    try:
        username, password = load_global_credentials()
    except Exception as exc:  # noqa: BLE001 - normalized below
        reason_code, detail_message = _map_discovery_exception(exc)
        for result in results:
            outcome = {
                'result_id': result.pk,
                'target_id': result.target_id,
                'reason_code': reason_code,
                'detail_message': detail_message,
                'status': MikroTikDiscoveryResult.Status.FAILED,
                'action_taken': MikroTikDiscoveryResult.ActionTaken.NONE,
            }
            counters = _apply_discovery_failure(outcome)
            run.refresh_from_db(fields=[
                'success_count',
                'failed_count',
                'blocked_count',
                'created_count',
                'updated_count',
                'reused_count',
            ])
            _increment_discovery_counters(
                run,
                counters['status'],
                counters['action_taken'],
            )

        run.refresh_from_db()
        _finalize_discovery_run(run)
        return

    futures = {}
    max_workers = min(MIKROTIK_DISCOVERY_MAX_WORKERS, len(results))

    with ThreadPoolExecutor(max_workers=max_workers) as executor:
        for result in results:
            target = result.target
            if target is None:
                counters = _apply_discovery_failure(
                    {
                        'result_id': result.pk,
                        'target_id': result.target_id,
                        'reason_code': MikroTikDiscoveryResult.ReasonCode.INVALID_TARGET,
                        'detail_message': 'Target was deleted before discovery started.',
                        'status': MikroTikDiscoveryResult.Status.FAILED,
                        'action_taken': MikroTikDiscoveryResult.ActionTaken.NONE,
                    }
                )
                run.refresh_from_db(fields=[
                    'success_count',
                    'failed_count',
                    'blocked_count',
                    'created_count',
                    'updated_count',
                    'reused_count',
                ])
                _increment_discovery_counters(run, counters['status'], counters['action_taken'])
                continue

            result.status = MikroTikDiscoveryResult.Status.RUNNING
            result.started_at = timezone.now()
            result.save(update_fields=['status', 'started_at'])

            future = executor.submit(
                _probe_target,
                result_id=result.pk,
                target_id=target.pk,
                host=target.host,
                scheme=target.scheme,
                port=target.port,
                verify_tls=target.verify_tls,
                username=username,
                password=password,
            )
            futures[future] = result.pk

        for future in as_completed(futures):
            try:
                outcome = future.result()
            except Exception as exc:  # noqa: BLE001 - worker safety
                reason_code, detail_message = _map_discovery_exception(exc)
                outcome = {
                    'result_id': futures[future],
                    'target_id': None,
                    'reason_code': reason_code,
                    'detail_message': detail_message,
                    'status': MikroTikDiscoveryResult.Status.FAILED,
                    'action_taken': MikroTikDiscoveryResult.ActionTaken.NONE,
                }

            if outcome.get('ok'):
                counters = _apply_discovery_success(outcome)
            else:
                counters = _apply_discovery_failure(outcome)

            run.refresh_from_db(fields=[
                'success_count',
                'failed_count',
                'blocked_count',
                'created_count',
                'updated_count',
                'reused_count',
            ])
            _increment_discovery_counters(
                run,
                counters['status'],
                counters['action_taken'],
            )

    run.refresh_from_db()
    _finalize_discovery_run(run)


# =====================================================================
# MikroTik sync scheduling
# =====================================================================

def schedule_mikrotik_sync_now(device):
    """Schedule an immediate sync for the given device."""
    device_id = device.pk if isinstance(device, MikroTikDevice) else int(device)
    return _schedule_mikrotik_sync_at(
        device_id=device_id,
        scheduled_for=_normalize_schedule_time(timezone.now()),
    )


def _schedule_mikrotik_sync_at(device_id, scheduled_for):
    with transaction.atomic():
        device = MikroTikDevice.objects.select_for_update().get(pk=device_id)
        if not device.enabled:
            return None

        existing = (
            MikroTikSyncRun.objects.select_for_update()
            .filter(
                device=device,
                status__in=MIKROTIK_SYNC_ACTIVE_STATUSES,
            )
            .order_by('scheduled_for')
            .first()
        )
        if existing is not None:
            return _enqueue_mikrotik_sync_run(existing)

        sync_run = MikroTikSyncRun.objects.create(
            device=device,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=scheduled_for,
        )
        return _enqueue_mikrotik_sync_run(sync_run)


def schedule_next_mikrotik_sync(device, finished_at):
    with transaction.atomic():
        device = MikroTikDevice.objects.select_for_update().get(pk=device.pk)
        if not device.enabled or not device.sync_enabled:
            return None

        existing = (
            MikroTikSyncRun.objects.select_for_update()
            .filter(
                device=device,
                status__in=MIKROTIK_SYNC_ACTIVE_STATUSES,
            )
            .order_by('scheduled_for')
            .first()
        )
        if existing is not None:
            return _enqueue_mikrotik_sync_run(existing)

        scheduled_for = _normalize_schedule_time(
            finished_at + timedelta(minutes=device.sync_interval_minutes)
        )
        sync_run = MikroTikSyncRun.objects.create(
            device=device,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=scheduled_for,
        )
        return _enqueue_mikrotik_sync_run(sync_run)


def _enqueue_mikrotik_sync_run(sync_run):
    if sync_run.task_ref:
        return sync_run

    task_result = run_mikrotik_sync.using(
        run_after=sync_run.scheduled_for,
    ).enqueue(sync_run_id=sync_run.pk)
    sync_run.task_ref = str(task_result.id)
    sync_run.save(update_fields=('task_ref',))
    return sync_run


def _fail_mikrotik_run(sync_run, error_message):
    sync_run.status = MikroTikSyncRun.Status.FAILED
    sync_run.error_message = sanitize_detail_message(error_message, max_len=2000)
    sync_run.finished_at = timezone.now()
    sync_run.save(update_fields=('status', 'error_message', 'finished_at'))


@task
def run_mikrotik_sync(sync_run_id: int):
    with transaction.atomic():
        sync_run = MikroTikSyncRun.objects.select_for_update().get(pk=sync_run_id)
        if sync_run.status != MikroTikSyncRun.Status.SCHEDULED:
            return

    device = sync_run.device
    if device is None:
        _fail_mikrotik_run(
            sync_run,
            'Device was deleted before the synchronization started.',
        )
        return

    try:
        run_device_sync(device, sync_run)
        sync_run.refresh_from_db()
        if sync_run.status == MikroTikSyncRun.Status.SUCCESS:
            schedule_next_mikrotik_sync(device, sync_run.finished_at)
    except Exception:
        sync_run.refresh_from_db()
        if device.sync_enabled and device.enabled:
            schedule_next_mikrotik_sync(
                device,
                sync_run.finished_at or timezone.now(),
            )
        raise
