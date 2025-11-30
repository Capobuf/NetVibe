<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages;

use App\Filament\Clusters\Discovery\Resources\ScanAgents\ScanAgentResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use App\Models\ScanAgent;

class EditScanAgent extends EditRecord
{
    protected static string $resource = ScanAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateApiKey')
                ->label('Generate API Key')
                ->color('warning')
                ->icon('heroicon-o-key')
                ->requiresConfirmation()
                ->action(function (ScanAgent $record) {
                    // Revoca tutte le chiavi esistenti per questo agente
                    $record->tokens()->delete();

                    // Crea un nuovo token permanente per l'agente con lo scope richiesto
                    $token = $record->createToken('remote-agent-token', ['ipam:ingest']);

                    // Mostra il valore in chiaro una sola volta via Notification (persistente)
                    Notification::make()
                        ->title('API Key Generated (Copy Now!)')
                        ->body($token->plainTextToken)
                        ->warning()
                        ->persistent()
                        ->send();
                }),
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Scan Agent')
                ->modalDescription('This will permanently delete this scan agent and any associated scan history. This action cannot be undone.')
                ->color('danger'),
        ];
    }
}
