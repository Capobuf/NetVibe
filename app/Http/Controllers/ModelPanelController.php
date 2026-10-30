<?php

namespace App\Http\Controllers;

use App\Http\Requests\PanelRequest;
use App\Models\DeviceInterface;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ModelPanelController extends Controller
{
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
                $bound = DeviceInterface::query()->whereHas('device', fn ($q) => $q->where('device_model_id', $model->id))
                    ->whereNotNull('connector_id')->pluck('connector_id')->all();
                foreach ($model->connectors() as $old) {
                    if (in_array($old['id'], $bound, true) && (! $ports->has($old['id']) || $ports[$old['id']]['type'] !== $old['type'])) {
                        throw ValidationException::withMessages(['document' => 'La porta «'.($old['label'] ?: $old['id']).'» è associata a interfacce di dispositivi. Conservane ID e tipologia: puoi modificarne la geometria.']);
                    }
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
}
