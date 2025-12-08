<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Discovery\Resources\ScanAgents\Pages;

use App\Filament\Clusters\Discovery\Resources\ScanAgents\ScanAgentResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use App\Filament\Clusters\Discovery\Resources\ScanAgents\Schemas\ScanAgentConfigForm;
use Filament\Forms\ComponentContainer;
use Illuminate\Support\Js;
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
                ->label('Manage API Key')
                ->color('primary')
                ->icon('heroicon-o-key')
                ->modalHeading('Remote Agent Configuration')
                ->modalDescription('Generate and copy a one-time API key for this agent. The modal will remain open after generation so you can safely copy it; generating a new key will revoke any existing keys.')
                ->form([
                    // API endpoint (static read-only) — always visible and copyable
                    \Filament\Forms\Components\TextInput::make('api_endpoint')
                        ->label('Agent Base URL')
                        ->default(fn () => rtrim(config('app.url'), '/'))
                        ->readOnly()
                        ->dehydrated(false)
                        ->helperText('Imposta questo valore come IPAM_AGENT_API_URL nel remote agent (senza /api/v1, ci pensa il client ad aggiungere i path).')
                        ->extraAttributes(['x-ref' => 'api_endpoint'])
                        ->suffixAction(
                            Action::make('copy_endpoint')
                                ->icon('heroicon-o-clipboard')
                                ->label('Copy')
                                ->color('gray')
                                ->button()
                                ->alpineClickHandler('navigator.clipboard.writeText($refs.api_endpoint.value)')
                        ),

                    // plain_text_token is a transient field: read-only and shown only after generation.
                    \Filament\Forms\Components\TextInput::make('plain_text_token')
                        ->label('Agent API Key')
                        ->readOnly()
                        ->dehydrated(false)
                        ->visible(fn ($get) => ! empty($get('plain_text_token')))
                        ->extraAttributes(['x-ref' => 'plain_text_token'])
                        ->suffixAction(
                            Action::make('copy_plain')
                                ->icon('heroicon-o-clipboard')
                                ->label('Copy')
                                ->color('gray')
                                ->button()
                                ->alpineClickHandler('navigator.clipboard.writeText($refs.plain_text_token.value)')
                        ),

                    // internal generate button that revokes old tokens, creates a new one and fills the form
                    \Filament\Schemas\Components\Actions::make([
                        Action::make('generate')
                            ->label('Generate API Key')
                            ->color('warning')
                            ->icon('heroicon-o-key')
                            ->action(function (\App\Models\ScanAgent $record, array $data, callable $set): void {
                                // Revoke existing tokens
                                $record->tokens()->delete();

                                // Create a new permanent token for this agent
                                $token = $record->createToken('remote-agent-token', ['ipam:ingest']);

                                // Populate the transient field so it becomes visible in the modal
                                // Use $set to update the form state (best-practice — decouples from the container)
                                $set('plain_text_token', $token->plainTextToken);

                                // notify user (modal remains mounted so they can copy the key)
                                Notification::make()->title('API Key Generated')->success()->send();
                            })
                            ->button(),
                    ])->columnSpanFull(),
                ])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('lg'),
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Scan Agent')
                ->modalDescription('This will permanently delete this scan agent and any associated scan history. This action cannot be undone.')
                ->color('danger'),
        ];
    }
}
