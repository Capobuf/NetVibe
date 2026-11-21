<?php

namespace App\Models;

use App\Exceptions\MikrotikException;
use App\Support\SyncNotifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['server_url', 'username', 'password', 'allow_unverified_certificate', 'revision', 'checked_at', 'check_status', 'identity', 'analyzed_at', 'analysis', 'source_generation', 'activated_at', 'sync_attempted_at', 'synced_at', 'sync_status', 'sync_identity', 'sync_summary', 'check_log', 'sync_log'])]
#[Hidden(['username', 'password', 'check_log', 'sync_log'])]
class MikrotikConnection extends Model
{
    protected static function booted(): void
    {
        static::updated(function (self $connection) {
            if (! $connection->wasChanged('sync_status')) {
                return;
            }
            // null means never synced and suspended means deactivated by the user: neither is a failure.
            $failing = fn (?string $status) => ! in_array($status, [null, 'ok', 'suspended'], true);
            $device = Device::with('site.company')->find($connection->device_id);
            if ($device) {
                SyncNotifier::transition('mikrotik', $failing($connection->getOriginal('sync_status')), $failing($connection->sync_status), $connection->sync_status === 'ok',
                    $device->site->company, implode(' · ', array_filter([$device->site->company->name, $device->site->name, $device->name])), route('companies.settings.integrations.mikrotik', $device->site->company),
                    $failing($connection->sync_status) ? MikrotikException::messageFor($connection->sync_status) : null);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'username' => 'encrypted', 'password' => 'encrypted',
            'allow_unverified_certificate' => 'boolean',
            'checked_at' => 'datetime', 'analyzed_at' => 'datetime',
            'identity' => 'array', 'analysis' => 'array',
            'activated_at' => 'datetime', 'sync_attempted_at' => 'datetime', 'synced_at' => 'datetime',
            'sync_identity' => 'array', 'sync_summary' => 'array',
        ];
    }

    public function summary(): array
    {
        return [
            ...$this->only('server_url', 'revision', 'allow_unverified_certificate', 'checked_at', 'check_status', 'identity', 'analyzed_at', 'analysis'),
            'username' => $this->username, 'has_password' => true,
            'check_log' => $this->check_log, 'sync_log' => $this->sync_log,
            ...$this->only('activated_at', 'sync_attempted_at', 'synced_at', 'sync_status', 'sync_summary'),
            'sync_message' => $this->sync_status ? ($this->sync_status === 'ok' ? 'Sincronizzazione completata.' : MikrotikException::messageFor($this->sync_status)) : null,
            'check_message' => $this->check_status ? MikrotikException::messageFor($this->check_status) : null,
        ];
    }
}
