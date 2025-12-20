<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Ipam\Resources\IpAddresses\Pages;

use App\Filament\Clusters\Ipam\Resources\IpAddresses\IpAddressResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIpAddress extends CreateRecord
{
    protected static string $resource = IpAddressResource::class;

    public function mount(): void
    {
        // Prefill form state from query params for faster create flow from the visual grid
        $subnetId = request()->query('subnet_id');
        $ipAddr = request()->query('ip_addr');

        $state = [];
        if ($subnetId) {
            $state['subnet_id'] = $subnetId;
        }
        if ($ipAddr) {
            $state['ip_addr'] = $ipAddr;
        }

        if (! empty($state)) {
            $this->form->fill($state);
        }
    }

    /**
     * Prefer returning to the previous URL (referrer) after creation when available.
     */
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? parent::getRedirectUrl();
    }
}
