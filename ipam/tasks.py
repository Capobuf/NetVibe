from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import timedelta
import re
import socket
import subprocess

from django.db import transaction
from django.tasks import task
from django.utils import timezone

from .models import IPAMSettings, IPAddress, JobRun, MikroTikDevice, MikroTikSyncRun, Subnet


ACTIVE_JOB_STATUSES = (
    JobRun.Status.SCHEDULED,
    JobRun.Status.RUNNING,
)


def _normalize_schedule_time(value):
    return value.replace(microsecond=0)


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
            # We keep rescheduling here so oversized subnets remain visible as
            # intentionally skipped until an operator fixes or disables them.
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
# MikroTik sync scheduling
# =====================================================================

MIKROTIK_ACTIVE_STATUSES = (
    MikroTikSyncRun.Status.SCHEDULED,
    MikroTikSyncRun.Status.RUNNING,
)


def schedule_mikrotik_sync_now(device):
    """Schedule an immediate sync for the given device."""
    device_id = device.pk if isinstance(device, MikroTikDevice) else int(device)
    return _schedule_mikrotik_sync_at(
        device_id=device_id,
        scheduled_for=_normalize_schedule_time(timezone.now()),
    )


def _schedule_mikrotik_sync_at(device_id, scheduled_for):
    """Create a MikroTikSyncRun at a specific time, guarding against duplicates."""
    with transaction.atomic():
        device = MikroTikDevice.objects.select_for_update().get(pk=device_id)
        if not device.enabled:
            return None

        existing = (
            MikroTikSyncRun.objects.select_for_update()
            .filter(
                device=device,
                mode=MikroTikSyncRun.Mode.SYNC,
                status__in=MIKROTIK_ACTIVE_STATUSES,
            )
            .order_by('scheduled_for')
            .first()
        )
        if existing is not None:
            return _enqueue_mikrotik_sync_run(existing)

        sync_run = MikroTikSyncRun.objects.create(
            device=device,
            mode=MikroTikSyncRun.Mode.SYNC,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=scheduled_for,
        )
        return _enqueue_mikrotik_sync_run(sync_run)


def schedule_next_mikrotik_sync(device, finished_at):
    """Schedule the next periodic sync after a completed run."""
    with transaction.atomic():
        device = MikroTikDevice.objects.select_for_update().get(pk=device.pk)
        if not device.enabled or not device.sync_enabled:
            return None

        existing = (
            MikroTikSyncRun.objects.select_for_update()
            .filter(
                device=device,
                mode=MikroTikSyncRun.Mode.SYNC,
                status__in=MIKROTIK_ACTIVE_STATUSES,
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
            mode=MikroTikSyncRun.Mode.SYNC,
            status=MikroTikSyncRun.Status.SCHEDULED,
            scheduled_for=scheduled_for,
        )
        return _enqueue_mikrotik_sync_run(sync_run)


def _enqueue_mikrotik_sync_run(sync_run):
    """Attach a django-tasks task to the sync run if not already enqueued."""
    if sync_run.task_ref:
        return sync_run

    task_result = run_mikrotik_sync.using(
        run_after=sync_run.scheduled_for,
    ).enqueue(sync_run_id=sync_run.pk)
    sync_run.task_ref = str(task_result.id)
    sync_run.save(update_fields=('task_ref',))
    return sync_run


@task
def run_mikrotik_sync(sync_run_id: int):
    """Execute a MikroTik sync run."""
    from .integrations.mikrotik_sync import run_device_sync

    with transaction.atomic():
        sync_run = MikroTikSyncRun.objects.select_for_update().get(pk=sync_run_id)
        if sync_run.status != MikroTikSyncRun.Status.SCHEDULED:
            return

    device = sync_run.device

    try:
        run_device_sync(device, sync_run)
        sync_run.refresh_from_db()
        if sync_run.status == MikroTikSyncRun.Status.SUCCESS:
            schedule_next_mikrotik_sync(device, sync_run.finished_at)
    except Exception:
        sync_run.refresh_from_db()
        if device.sync_enabled and device.enabled:
            schedule_next_mikrotik_sync(
                device, sync_run.finished_at or timezone.now()
            )
        raise
