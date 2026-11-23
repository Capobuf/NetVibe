<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Notifications\SyncStatusChanged;
use Illuminate\Support\Facades\Notification;

class SyncNotifier
{
    private const SOURCES = ['snipe_it' => 'Snipe-IT', 'mikrotik' => 'MikroTik', 'unifi' => 'UniFi', 'google_drive' => 'Google Drive'];

    /** Notifies only transitions: healthy → failing, or failing → ok. Repeated failures stay silent. */
    public static function transition(string $source, bool $wasFailing, bool $isFailing, bool $isOk, Company $company, string $subject, string $url, ?string $message): void
    {
        if ($wasFailing === $isFailing || (! $isFailing && ! $isOk)) {
            return;
        }
        $users = User::query()->where('notify_'.$source, true)
            ->where(fn ($query) => $query->where('is_superadmin', true)
                ->orWhereHas('companies', fn ($companies) => $companies->whereKey($company->id)))
            ->get();
        Notification::send($users, new SyncStatusChanged([
            'source' => $source,
            'failed' => $isFailing,
            'title' => 'Sincronizzazione '.self::SOURCES[$source].($isFailing ? ' non riuscita' : ' ripristinata'),
            'subject' => $subject,
            'message' => $isFailing ? $message : null,
            'url' => $url,
        ]));
    }
}
