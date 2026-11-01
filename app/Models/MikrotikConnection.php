<?php

namespace App\Models;

use App\Exceptions\MikrotikException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['server_url', 'username', 'password', 'allow_unverified_certificate', 'revision', 'checked_at', 'check_status', 'identity', 'analyzed_at', 'analysis', 'source_generation', 'activated_at', 'sync_attempted_at', 'synced_at', 'sync_status', 'sync_identity', 'sync_summary'])]
#[Hidden(['username', 'password'])]
class MikrotikConnection extends Model
{
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
            ...$this->only('activated_at', 'sync_attempted_at', 'synced_at', 'sync_status', 'sync_summary'),
            'sync_message' => $this->sync_status ? ($this->sync_status === 'ok' ? 'Sincronizzazione completata.' : MikrotikException::messageFor($this->sync_status)) : null,
            'check_message' => $this->check_status ? MikrotikException::messageFor($this->check_status) : null,
        ];
    }
}
