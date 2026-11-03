<?php

namespace App\Http\Controllers;

use App\Models\ModelType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ModelTypeController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, ModelType $modelType): JsonResponse|RedirectResponse
    {
        return $this->save($request, $modelType);
    }

    private function save(Request $request, ?ModelType $modelType = null): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('model_types')->ignore($modelType)]], [], ['name' => 'nome']);
        $creating = $modelType === null;
        try {
            if ($modelType) {
                $modelType->update($data);
            } else {
                $modelType = ModelType::create($data);
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Questo tipo esiste già.']);
        }
        if ($request->expectsJson()) {
            return response()->json($modelType->only('id', 'name'), $creating ? 201 : 200);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo salvato.']);

        return back();
    }

    public function destroy(Request $request, ModelType $modelType): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        DB::transaction(function () use ($modelType) {
            $modelType = ModelType::query()->lockForUpdate()->findOrFail($modelType->id);
            $this->deleteModelType($modelType);
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipo eliminato.']);

        return to_route('device-models.index', ['tab' => 'types']);
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'confirmed' => ['accepted'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:model_types,id'],
        ], [], ['ids' => 'tipi selezionati', 'ids.*' => 'tipo selezionato']);

        DB::transaction(function () use ($data) {
            $types = ModelType::query()->whereKey($data['ids'])->orderBy('id')->lockForUpdate()->get();
            if ($types->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'La selezione è cambiata. Ricarica l’elenco e riprova.']);
            }
            foreach ($types as $type) {
                $this->deleteModelType($type);
            }
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tipi selezionati eliminati.']);

        return to_route('device-models.index', ['tab' => 'types']);
    }

    private function deleteModelType(ModelType $modelType): void
    {
        if ($modelType->models()->exists()) {
            throw ValidationException::withMessages(['confirmed' => '«'.$modelType->name.'»: il tipo è associato a modelli. Cambia prima il tipo nei modelli che lo utilizzano. Nessun dato è stato eliminato.']);
        }
        $modelType->delete();
    }
}
