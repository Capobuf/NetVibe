<?php

namespace App\Http\Controllers;

use App\Models\IpStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IpStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ip-statuses/index', [
            'statuses' => IpStatus::query()->select('id', 'name')->withCount('ipAddresses')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, IpStatus $ipStatus): RedirectResponse
    {
        return $this->save($request, $ipStatus);
    }

    private function save(Request $request, ?IpStatus $ipStatus = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']], [], ['name' => 'nome']);

        try {
            $record = $ipStatus ?? new IpStatus;
            $record->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Esiste già uno stato con questo nome.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $ipStatus ? 'Stato aggiornato.' : 'Stato creato.']);

        return back();
    }
}
