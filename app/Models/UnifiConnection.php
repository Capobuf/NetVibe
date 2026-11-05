<?php

namespace App\Models;

use App\Exceptions\UnifiException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'mode', 'server_url', 'api_key', 'allow_unverified_certificate', 'enabled', 'revision', 'hosts', 'selected_console_ids', 'checked_at', 'check_status', 'discovered_at'])]
#[Hidden(['api_key'])]
class UnifiConnection extends Model
{
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted', 'enabled' => 'boolean', 'allow_unverified_certificate' => 'boolean',
            'hosts' => 'array', 'selected_console_ids' => 'array',
            'checked_at' => 'datetime', 'discovered_at' => 'datetime',
        ];
    }

    public function unifiSites(): HasMany
    {
        return $this->hasMany(UnifiSite::class);
    }

    public function summary(bool $administration): array
    {
        $summary = [
            ...$this->only('id', 'name', 'mode', 'enabled', 'checked_at', 'check_status', 'discovered_at'),
            'check_message' => $this->check_status ? UnifiException::messageFor($this->check_status) : null,
            'sites' => $this->unifiSites->map(fn (UnifiSite $site) => $site->summary())->all(),
        ];
        if ($administration) {
            $summary += [
                ...$this->only('server_url', 'allow_unverified_certificate', 'revision'),
                'has_key' => true, 'selected_console_ids' => $this->selected_console_ids ?? [],
                'hosts' => collect($this->hosts ?? [])->map(fn ($host) => [...$host,
                    'message' => ($host['status'] ?? null) ? UnifiException::messageFor($host['status']) : null,
                ])->all(),
            ];
        }

        return $summary;
    }
}
