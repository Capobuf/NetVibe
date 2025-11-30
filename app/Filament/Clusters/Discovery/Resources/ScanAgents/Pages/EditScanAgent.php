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

    public ?string $tempNewKey = null;

    public function generateAgentKey(): void
    {
        // Revoke all existing tokens for this agent
        $this->record->tokens()->delete();

        // Create a new permanent token for the agent with required scope
        $token = $this->record->createToken('remote-agent-token', ['ipam:ingest']);

        // Store the plain text token in the Livewire property
        $this->tempNewKey = $token->plainTextToken;

        // Send success notification
        Notification::make()
            ->title('API Key Generated')
            ->success()
            ->send();
    }

    public function notify(string $message): void
    {
        Notification::make()
            ->title($message)
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setupAgent')
                ->label('Setup & Connection')
                ->color('primary')
                ->icon('heroicon-o-command-line')
                ->modalHeading('Remote Agent Configuration')
                ->modalContent(view('filament.clusters.discovery.scan-agents.actions.setup-modal', ['record' => $this->record]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('2xl'),
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Scan Agent')
                ->modalDescription('This will permanently delete this scan agent and any associated scan history. This action cannot be undone.')
                ->color('danger'),
        ];
    }
}
