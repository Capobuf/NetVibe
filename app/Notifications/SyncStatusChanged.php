<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SyncStatusChanged extends Notification
{
    /** @param array{source: string, failed: bool, title: string, subject: string, message: ?string, url: string} $data */
    public function __construct(private array $data) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
