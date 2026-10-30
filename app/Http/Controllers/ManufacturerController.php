<?php

namespace App\Http\Controllers;

use App\Models\Manufacturer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ManufacturerController extends Controller
{
    public function index(): RedirectResponse
    {
        return to_route('device-models.index', ['tab' => 'manufacturers']);
    }

    public function show(Manufacturer $manufacturer): RedirectResponse
    {
        return to_route('device-models.index', ['manufacturer' => $manufacturer->id]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, Manufacturer $manufacturer): JsonResponse|RedirectResponse
    {
        return $this->save($request, $manufacturer);
    }

    private function save(Request $request, ?Manufacturer $manufacturer = null): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('manufacturers')->ignore($manufacturer)]], [], ['name' => 'nome']);
        $creating = $manufacturer === null;
        try {
            if ($manufacturer) {
                $manufacturer->update($data);
            } else {
                $manufacturer = Manufacturer::create($data);
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Questo produttore esiste già.']);
        }
        if ($request->expectsJson()) {
            return response()->json($manufacturer->only('id', 'name'), $creating ? 201 : 200);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produttore salvato.']);

        return back();
    }

    public function destroy(Request $request, Manufacturer $manufacturer): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        DB::transaction(function () use ($manufacturer) {
            $manufacturer = Manufacturer::query()->lockForUpdate()->findOrFail($manufacturer->id);
            if ($manufacturer->devices()->exists() || $manufacturer->models()->exists()) {
                throw ValidationException::withMessages(['confirmed' => 'Rimuovi prima i modelli e cambia produttore ai dispositivi associati. Nessun dato è stato eliminato.']);
            }
            $manufacturer->delete();
        }, 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produttore eliminato.']);

        return to_route('manufacturers.index');
    }
}
