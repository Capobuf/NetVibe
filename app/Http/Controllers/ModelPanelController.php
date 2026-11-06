<?php

namespace App\Http\Controllers;

use App\Http\Requests\PanelRequest;
use App\Models\CableEnd;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\MikrotikSource;
use App\Support\MikrotikPortMap;
use App\Support\MikrotikSync;
use App\Support\UnifiInventory;
use App\Support\UnifiPortMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ModelPanelController extends Controller
{
    public function unifiPorts(Request $request, Manufacturer $manufacturer, DeviceModel $model): JsonResponse
    {
        abort_unless(mb_strtolower(trim($manufacturer->name)) === 'ubiquiti', 404);
        $data = $request->validate([
            'ports' => ['present', 'array', 'max:2048'],
            'ports.*' => ['required', 'array:id,type,label,unifiPort'],
            'ports.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'ports.*.type' => ['required', 'in:rj45,sfp,qsfp'],
            'ports.*.label' => ['present', 'nullable', 'string', 'max:255'],
            'ports.*.unifiPort' => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
        ]);
        $ports = array_map(fn ($port) => [...$port, 'label' => $port['label'] ?? ''], $data['ports']);
        $observed = UnifiPortMap::observed($model);

        return response()->json([
            'observed' => count($observed) > 0,
            'interfaces' => array_map(fn ($port) => ['name' => (string) $port['idx'], 'type' => UnifiPortMap::type($port['connector'] ?? null)], $observed),
            'matches' => (object) array_map('strval', UnifiPortMap::resolve($ports, $observed)),
        ]);
    }

    public function mikrotikPorts(Request $request, Manufacturer $manufacturer, DeviceModel $model): JsonResponse
    {
        abort_unless($manufacturer->isMikrotik(), 404);
        $data = $request->validate([
            'ports' => ['present', 'array', 'max:2048'],
            'ports.*' => ['required', 'array:id,type,label,mikrotikName'],
            'ports.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'ports.*.type' => ['required', 'in:rj45,sfp,qsfp'],
            'ports.*.label' => ['present', 'nullable', 'string', 'max:255'],
            'ports.*.mikrotikName' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $ports = array_map(fn ($port) => [...$port, 'label' => $port['label'] ?? ''], $data['ports']);
        $names = MikrotikSource::query()->where('kind', 'interface')->where('state', 'current')->whereNull('problem')
            ->whereHas('device', fn ($query) => $query->where('device_model_id', $model->id)
                ->whereHas('mikrotikConnection', fn ($query) => $query->whereNotNull('activated_at')->where('sync_status', 'ok')
                    ->whereColumn('source_generation', 'mikrotik_sources.generation')))
            ->get()->pluck('data.default_name')->filter()->unique()->sort(SORT_NATURAL)->values()->all();
        $observed = count($names) > 0;
        if (! $observed) {
            foreach (['rj45' => ['ether'], 'sfp' => ['sfp', 'sfp-sfpplus', 'sfp28-']] as $type => $prefixes) {
                $count = count(array_filter($ports, fn ($port) => $port['type'] === $type));
                foreach ($prefixes as $prefix) {
                    for ($number = 1; $number <= $count; $number++) {
                        $names[] = $prefix.$number;
                    }
                }
            }
        }

        return response()->json([
            'observed' => $observed,
            'interfaces' => array_map(fn ($name) => ['name' => $name, 'type' => MikrotikPortMap::type($name)], $names),
            'matches' => (object) MikrotikPortMap::resolve($ports, $names),
        ]);
    }

    public function update(PanelRequest $request, Manufacturer $manufacturer, DeviceModel $model): JsonResponse
    {
        $data = $request->validated();
        $stored = [];
        try {
            $result = DB::transaction(function () use ($request, $model, $data, &$stored) {
                $model = DeviceModel::query()->lockForUpdate()->findOrFail($model->id);
                if ($model->panel_revision !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['document' => 'Il pannello è stato modificato da un altro operatore. Riapri il modello prima di salvare.']);
                }
                $panel = $data['panel'];
                $ports = collect([...$panel['faces']['front']['ports'], ...$panel['faces']['rear']['ports']])->keyBy('id');
                $changed = collect($model->connectors())
                    ->filter(fn ($old) => ! $ports->has($old['id']) || $ports[$old['id']]['type'] !== $old['type'])->keyBy('id');
                if ($changed->isNotEmpty()) {
                    $this->releaseChangedPorts($model, $changed, $ports);
                }
                $assets = [];
                foreach (['front', 'rear'] as $side) {
                    if ($image = $panel['faces'][$side]['image']) {
                        $path = $request->file("images.$side")->store("device-models/{$model->id}", 'local');
                        if (! $path) {
                            throw new \RuntimeException('Impossibile salvare l’immagine.');
                        }
                        $stored[] = $path;
                        $assets[$image['assetId']] = $path;
                    }
                }
                $previous = array_values($model->assets ?? []);
                $model->update(['panel' => $panel, 'assets' => $assets, 'panel_revision' => $model->panel_revision + 1]);
                foreach ($model->devices()->orderBy('id')->lockForUpdate()->get() as $device) {
                    $device->addModelInterfaces($model);
                    $device->setRelation('model', $model);
                    app(MikrotikSync::class)->applySavedInterfaceMap($device);
                    app(UnifiInventory::class)->applySavedInterfaceMap($device);
                }

                return ['revision' => $model->panel_revision, 'previous' => $previous];
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($stored);
            throw $error;
        }
        Storage::disk('local')->delete($result['previous']);

        return response()->json(['revision' => $result['revision']]);
    }

    /**
     * Removed or retyped ports are free unless a device documents something on them.
     * Auto-created empty interfaces do not count; integration links re-attach by port name after saving.
     *
     * @param  Collection<string, array>  $changed
     * @param  Collection<string, array>  $ports
     */
    private function releaseChangedPorts(DeviceModel $model, Collection $changed, Collection $ports): void
    {
        $deviceIds = $model->devices()->select('id');
        $connectorIds = $changed->keys()->all();
        $cables = CableEnd::query()->whereIn('device_id', $deviceIds)->whereIn('connector_id', $connectorIds)
            ->lockForUpdate()->get(['device_id', 'connector_id']);
        $interfaces = DeviceInterface::query()->whereIn('device_id', $deviceIds)->whereIn('connector_id', $connectorIds)
            ->withCount('ipAddresses')->orderBy('id')->lockForUpdate()->get();

        $uses = [];
        foreach ($cables as $end) {
            $uses[$end->connector_id][$end->device_id][] = 'cavo';
        }
        foreach ($interfaces as $interface) {
            foreach (['IP' => $interface->ip_addresses_count > 0, 'MAC' => $interface->mac_address !== null,
                'nome interfaccia' => $interface->name !== null] as $label => $used) {
                if ($used) {
                    $uses[$interface->connector_id][$interface->device_id][] = $label;
                }
            }
        }
        if ($uses !== []) {
            $devices = Device::query()->with('site.company:id,name')->whereKey(collect($uses)->flatMap(fn ($byDevice) => array_keys($byDevice))->unique()->all())
                ->get(['id', 'name', 'site_id'])->keyBy('id');
            $lines = [];
            foreach ($uses as $connectorId => $byDevice) {
                $port = $changed[$connectorId];
                foreach ($byDevice as $deviceId => $labels) {
                    $device = $devices[$deviceId];
                    $place = implode(' · ', array_filter([$device->site->company->name, $device->site->name]));
                    $lines[] = '«'.($port['label'] ?: $port['id']).'» · '.$device->name.' ('.$place.'): '.implode(', ', $labels);
                }
            }
            $shown = array_slice($lines, 0, 5);
            $more = count($lines) - count($shown);
            throw ValidationException::withMessages(['document' => 'Porte in uso: '.implode('; ', $shown).($more > 0 ? '; e altre '.$more : '')
                .'. Liberale prima di eliminarle o cambiarne il connettore.']);
        }

        // Empty placeholders would otherwise point at ports that no longer exist or no longer carry an interface.
        $orphaned = $changed->keys()->filter(fn ($id) => ! $ports->has($id) || ! in_array($ports[$id]['type'], ['rj45', 'sfp', 'qsfp'], true));
        $removed = $interfaces->whereIn('connector_id', $orphaned->all())->modelKeys();
        // The source FK cascades: detach observations first so they re-attach to the new port instead of disappearing.
        MikrotikSource::query()->whereIn('device_interface_id', $removed)->update(['device_interface_id' => null, 'interface_from_model' => false]);
        DeviceInterface::query()->whereKey($removed)->delete();
    }
}
