<?php

namespace App\Http\Controllers;

use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\ModelType;
use App\Models\RackItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceModelController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'tab' => ['sometimes', 'in:models,manufacturers,types'],
            'manufacturer' => ['nullable', 'integer', 'exists:manufacturers,id'],
        ]);

        return Inertia::render('devices/models', [
            'tab' => $filters['tab'] ?? 'models',
            'manufacturerId' => isset($filters['manufacturer']) ? (int) $filters['manufacturer'] : null,
            'types' => ModelType::query()->withCount('models')->orderBy('name')->get(),
            'manufacturers' => Manufacturer::query()->withCount(['models', 'devices'])->orderBy('name')->get(),
            'models' => DeviceModel::query()->with(['manufacturer:id,name', 'modelType:id,name'])->withCount('devices')->orderBy('name')->get()
                ->map(fn ($model) => [...$model->toArray(), 'assetUrls' => $model->assetUrls()]),
        ]);
    }

    public function store(Request $request, Manufacturer $manufacturer): RedirectResponse
    {
        return $this->save($request, $manufacturer);
    }

    public function update(Request $request, Manufacturer $manufacturer, DeviceModel $model): RedirectResponse
    {
        return $this->save($request, $manufacturer, $model);
    }

    private function save(Request $request, Manufacturer $manufacturer, ?DeviceModel $model = null): RedirectResponse
    {
        $request->mergeIfMissing(['manufacturer_id' => $manufacturer->id]);
        $data = $request->validate(['without_rack_ears' => ['sometimes', 'boolean'], 'rack_height_u' => ['nullable', 'integer', 'between:1,100'], 'manufacturer_id' => ['required', 'integer', 'exists:manufacturers,id'], 'model_type_id' => ['nullable', 'integer', 'exists:model_types,id'], 'name' => ['required', 'string', 'max:255',
            Rule::unique('device_models')->where('manufacturer_id', $request->input('manufacturer_id'))->ignore($model),
        ]], [], ['name' => 'nome', 'manufacturer_id' => 'produttore', 'model_type_id' => 'tipo', 'rack_height_u' => 'altezza rack', 'without_rack_ears' => 'senza staffe rack']);
        try {
            $record = DB::transaction(function () use ($model, $data) {
                if (! empty($data['model_type_id'])) {
                    ModelType::query()->sharedLock()->findOrFail($data['model_type_id']);
                }
                $manufacturer = Manufacturer::query()->sharedLock()->whereKey($data['manufacturer_id'])->firstOrFail();
                if ($model) {
                    $model = DeviceModel::query()->lockForUpdate()->findOrFail($model->id);
                    $model->fill($data);
                    if ($model->isDirty(['rack_height_u', 'without_rack_ears']) && RackItem::query()->whereIn('device_id', $model->devices()->select('id'))->exists()) {
                        $field = $model->isDirty('rack_height_u') ? 'rack_height_u' : 'without_rack_ears';
                        throw ValidationException::withMessages([$field => 'Il modello è montato in un rack. Rimuovi prima i dispositivi dal rack per modificarne l’altezza o il fissaggio.']);
                    }
                    $manufacturerChanged = $model->isDirty('manufacturer_id');
                    $model->save();
                    if ($manufacturerChanged) {
                        $model->devices()->update(['manufacturer_id' => $manufacturer->id]);
                    }

                    return $model;
                }

                return $manufacturer->models()->create($data);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Questo modello esiste già per il produttore.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Modello salvato.']);
        if (! $model && $request->boolean('inline')) {
            Inertia::flash('createdModel', $record->only('id', 'name', 'manufacturer_id'));

            return back();
        }

        return $model ? back() : to_route('manufacturers.models.edit', [$record->manufacturer_id, $record]);
    }

    public function edit(Manufacturer $manufacturer, DeviceModel $model): Response
    {
        return Inertia::render('administration/hardware-editor', [
            'catalogModel' => [...$model->toArray(), 'manufacturer' => $manufacturer, 'assetUrls' => $model->assetUrls()],
        ]);
    }

    public function destroy(Request $request, Manufacturer $manufacturer, DeviceModel $model): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $assets = DB::transaction(function () use ($model) {
            $model = DeviceModel::query()->lockForUpdate()->findOrFail($model->id);
            if ($model->devices()->exists()) {
                throw ValidationException::withMessages(['confirmed' => 'Il modello è usato da dispositivi. Cambia prima il loro modello o elimina esplicitamente i dispositivi.']);
            }
            $assets = array_values($model->assets ?? []);
            $model->delete();

            return $assets;
        }, 3);
        Storage::disk('local')->delete($assets);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Modello eliminato.']);

        return back();
    }

    public function asset(DeviceModel $model, string $asset): StreamedResponse
    {
        $path = ($model->assets ?? [])[$asset] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-cache']);
    }
}
