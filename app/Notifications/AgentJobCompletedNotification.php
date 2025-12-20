<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\AgentJob;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Actions\Action;

class AgentJobCompletedNotification extends Notification
{
    use Queueable;

    private AgentJob $job;

    public function __construct(AgentJob $job)
    {
        $this->job = $job;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $device = $this->job->device;
        $deviceLabel = $device?->hostname ?? ('Device #' . $this->job->device_id);
        $status = $this->job->status ?? 'completed';
        $url = $device ? route('filament.admin.resources.devices.edit', ['record' => $device->id]) : null;

        $notification = FilamentNotification::make()
            ->title("Job {$this->job->type} → {$status}")
            ->body("{$deviceLabel} · Job #{$this->job->id}")
            ->status(match ($status) {
                'completed' => 'success',
                'failed' => 'danger',
                'timeout' => 'warning',
                default => 'info',
            });

        if ($url) {
            $notification->actions([
                Action::make('open_device')
                    ->label('Apri device')
                    ->url($url, shouldOpenInNewTab: false),
            ]);
        }

        // Use Filament's database serialization to ensure the expected shape (includes 'name' keys).
        $data = $notification->getDatabaseMessage();
        $data['actions'] = $data['actions'] ?? [];

        // Keep some extra context for server-side audits
        $data['job_id'] = $this->job->id;
        $data['device_id'] = $this->job->device_id;
        $data['type'] = $this->job->type;
        $data['status'] = $status;

        return $data;
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
