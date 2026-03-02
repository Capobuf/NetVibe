import signal
import subprocess
import sys
import time

from django.conf import settings
from django.core.management.base import BaseCommand

from ...models import IPAMSettings


class Command(BaseCommand):
    help = 'Manage db_worker subprocesses using the current IPAM settings.'

    poll_interval_seconds = 15
    worker_interval_seconds = 5
    shutdown_timeout_seconds = 10

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._stop_requested = False
        self._worker_index = 0
        self._workers = {}

    def handle(self, *args, **options):
        signal.signal(signal.SIGTERM, self._request_stop)
        signal.signal(signal.SIGINT, self._request_stop)

        try:
            while not self._stop_requested:
                self._reap_workers()
                self._reconcile_workers()
                time.sleep(self.poll_interval_seconds)
        finally:
            self._stop_all_workers()

    def _request_stop(self, signum, frame):
        self._stop_requested = True

    def _desired_worker_count(self):
        settings_obj = IPAMSettings.load()
        if settings_obj.worker_mode == IPAMSettings.WorkerMode.SEQUENTIAL:
            return 1
        return max(settings_obj.worker_concurrency, 1)

    def _reconcile_workers(self):
        desired_count = self._desired_worker_count()

        while len(self._workers) < desired_count:
            worker_name = self._spawn_worker()
            self.stdout.write(
                self.style.SUCCESS(f'Started background worker {worker_name}.')
            )

        while len(self._workers) > desired_count:
            worker_name = sorted(self._workers)[-1]
            self._stop_worker(worker_name)

    def _spawn_worker(self):
        self._worker_index += 1
        worker_name = f'ipam-worker-{self._worker_index}'
        # A tiny supervisor is enough here because the desired worker count is
        # small and driven entirely by one database row.
        process = subprocess.Popen(
            [
                sys.executable,
                'manage.py',
                'db_worker',
                '--queue-name',
                'default',
                '--interval',
                str(self.worker_interval_seconds),
                '--no-reload',
                '--worker-id',
                worker_name,
            ],
            cwd=str(settings.BASE_DIR),
        )
        self._workers[worker_name] = process
        return worker_name

    def _reap_workers(self):
        for worker_name, process in list(self._workers.items()):
            if process.poll() is not None:
                del self._workers[worker_name]
                self.stdout.write(
                    self.style.WARNING(
                        f'Background worker {worker_name} exited with code {process.returncode}.'
                    )
                )

    def _stop_worker(self, worker_name):
        process = self._workers.pop(worker_name)
        process.terminate()
        try:
            process.wait(timeout=self.shutdown_timeout_seconds)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()

        self.stdout.write(
            self.style.WARNING(f'Stopped background worker {worker_name}.')
        )

    def _stop_all_workers(self):
        for worker_name in list(self._workers):
            self._stop_worker(worker_name)
