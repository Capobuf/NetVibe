<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Infrastructure\Resources\Devices\Pages;

use App\Filament\Clusters\Infrastructure\Resources\Devices\DeviceResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Actions\Agent\DispatchDeviceJobAction;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Test Connection')
                ->icon('heroicon-o-wrench')
                ->action(function () {
                    $device = $this->record;
                    $job = app(DispatchDeviceJobAction::class)->execute($device, 'check_connectivity', null, auth()->id());

                    Notification::make()->success()->title('Job queued')->body('Job ' . $job->id . ' queued')->send();
                }),
            Action::make('full_discovery')
                ->label('Full Discovery')
                ->icon('heroicon-o-magnifying-glass')
                ->action(function () {
                    $device = $this->record;
                    $job = app(DispatchDeviceJobAction::class)->execute($device, 'discovery_deep', null, auth()->id());

                    Notification::make()->success()->title('Job queued')->body('Job ' . $job->id . ' queued')->send();
                }),
        ];
    }
}
