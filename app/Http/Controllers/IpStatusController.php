<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Models\IpStatus;
use App\Support\DeletionImpact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function deletionImpact(IpStatus $ipStatus): JsonResponse
    {
        return response()->json(DB::transaction(fn () => [
            ...DeletionImpact::preview(DeletionImpact::status($ipStatus)),
            'statuses' => IpStatus::query()->whereKeyNot($ipStatus->id)->orderBy('name')->get(['id', 'name']),
        ], 3));
    }

    public function destroy(ConfirmDeletionRequest $request, IpStatus $ipStatus): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:clear,reassign,delete_ips'],
            'replacement_status_id' => ['exclude_unless:mode,reassign', 'required', 'integer', 'not_in:'.$ipStatus->id],
        ]);

        DB::transaction(function () use ($request, $ipStatus, $data) {
            $statuses = IpStatus::query()->whereKey([$ipStatus->id, $data['replacement_status_id'] ?? $ipStatus->id])
                ->orderBy('id')->lockForUpdate()->get();
            if ($data['mode'] === 'reassign' && ! $statuses->contains('id', $data['replacement_status_id'])) {
                throw ValidationException::withMessages(['replacement_status_id' => 'Lo stato scelto non esiste più. Aggiorna l’anteprima.']);
            }
            $impact = DeletionImpact::status($ipStatus);
            $request->ensureUnchanged($impact);
            if ($data['mode'] === 'delete_ips') {
                $ipStatus->ipAddresses()->delete();
            } else {
                $ipStatus->ipAddresses()->update(['ip_status_id' => $data['replacement_status_id'] ?? null]);
            }
            $ipStatus->delete();
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($data['mode']) {
            'reassign' => 'Stato eliminato e IP riassegnati.',
            'delete_ips' => 'Stato e record IP associati eliminati.',
            default => 'Stato eliminato. Gli IP sono stati conservati senza stato.',
        }]);

        return to_route('administration.ip-statuses.index');
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
