<?php

namespace App\Http\Livewire;

use App\Models\CableType;
use App\Models\Device;
use App\Models\Port;
use App\Services\Physical\ManageLinkService;
use Livewire\Component;

class RackPortModal extends Component
{
    public ?int $portId = null;
    public bool $show = false;

    public ?int $target_device_id = null;
    public ?int $target_port_id = null;
    public ?int $cable_type_id = null;

    public array $devices = [];
    public array $targetPorts = [];

    protected $listeners = ['openConnectModal' => 'open'];

    public function open(int $portId): void
    {
        $this->portId = $portId;
        $this->show = true;

        $this->loadDevices();
    }

    protected function loadDevices(): void
    {
        $port = Port::find($this->portId);
        if (! $port) {
            $this->devices = [];
            return;
        }

        $this->devices = Device::where('id', '!=', $port->device_id)
            ->whereHas('ports', fn($q) => $q->where('status', 'down'))
            ->withCount(['ports as free_ports_count' => fn($q) => $q->where('status', 'down')])
            ->orderBy('hostname')
            ->get()
            ->mapWithKeys(fn($d) => [$d->id => $d->hostname . ' (' . ($d->free_ports_count ?? 0) . ' free)'])
            ->toArray();
    }

    public function updatedTargetDeviceId($value): void
    {
        $this->target_port_id = null;
        if (! $value) {
            $this->targetPorts = [];
            return;
        }

        $this->targetPorts = Port::where('device_id', $value)
            ->where('status', 'down')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn($p) => [$p->id => $p->name])
            ->toArray();
    }

    public function connect(): void
    {
        $port = Port::find($this->portId);
        $target = Port::find($this->target_port_id);

        if (! $port || ! $target) {
            $this->dispatchBrowserEvent('notify', ['type' => 'danger', 'message' => 'Invalid port selection']);
            return;
        }

        try {
            app(ManageLinkService::class)->connect($port, $target, $this->cable_type_id ?? null);
            $this->dispatchBrowserEvent('notify', ['type' => 'success', 'message' => 'Ports connected']);
            $this->resetModal();
            $this->emit('refreshLivewireTable');
        } catch (\Throwable $e) {
            $this->dispatchBrowserEvent('notify', ['type' => 'danger', 'message' => $e->getMessage()]);
        }
    }

    protected function resetModalInternal(): void
    {
        $this->show = false;
        $this->portId = null;
        $this->target_device_id = null;
        $this->target_port_id = null;
        $this->cable_type_id = null;
        $this->devices = [];
        $this->targetPorts = [];
    }

    public function resetModal(): void
    {
        $this->resetModalInternal();
    }

    public function render()
    {
        return view('livewire.rack-port-modal', [
            'cableTypes' => CableType::orderBy('name')->pluck('name', 'id')->toArray(),
        ]);
    }
}
