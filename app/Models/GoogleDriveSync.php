<?php

namespace App\Models;

use App\Support\SyncNotifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'account_id', 'folder_id', 'folder_name', 'interval', 'file_id', 'file_url', 'synced_at', 'error'])]
class GoogleDriveSync extends Model
{
    /** Allowed automatic sync intervals, in minutes. */
    public const INTERVALS = [60, 360, 1440, 10080];

    protected static function booted(): void
    {
        static::updated(function (self $sync) {
            if ($sync->wasChanged('error')) {
                SyncNotifier::transition('google_drive', $sync->getOriginal('error') !== null, $sync->error !== null, $sync->error === null,
                    $sync->company, $sync->company->name, route('companies.settings.integrations.google-drive.index', $sync->company), $sync->error);
            }
        });
    }

    protected function casts(): array
    {
        return ['interval' => 'integer', 'synced_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleDriveAccount::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
