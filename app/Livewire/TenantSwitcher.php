<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Component;
use App\Models\Customer;

class TenantSwitcher extends Component
{
    // Use camelCase for Livewire property naming so blade can bind with wire:model.live
    public ?int $tenantId = null;

    // Do not preload tenants into a public property to avoid large Livewire payloads.

    public function mount(): void
    {
        // Initialize the component from the persisted session value so the component
        // preserves the selected tenant across full page loads.
        $tid = session('tenant_id');
        $this->tenantId = $tid === null ? null : (int) $tid;
    }

    /**
     * Activate a tenant selection via explicit action calls from the UI.
     *
     * We accept null (or empty string) to indicate "All Customers" and
     * normalize the value in the session accordingly. After changing the
     * selection we redirect back to the referring page to preserve the
     * current admin view context.
     *
     * Note: we intentionally avoid two-way binding in the dropdown UI and
     * instead expose a method that the dropdown items call with wire:click.
     */
    public function activateTenant(?string $id)
    {
        if ($id === null || $id === '') {
            session()->forget('tenant_id');
            $this->tenantId = null;
        } else {
            $val = (int) $id;
            session(['tenant_id' => $val]);
            $this->tenantId = $val;
        }

        // Redirect back to the page that initiated the change
        $referer = request()->header('Referer') ?? url()->current();
        return redirect($referer);
    }

    public function render()
    {
        // Load tenants lazily on render to avoid large Livewire public payloads.
        $options = Customer::query()->orderBy('name')->pluck('name', 'id')->toArray();

        return view('livewire.tenant-switcher', [
            'options' => $options,
        ]);
    }
}
