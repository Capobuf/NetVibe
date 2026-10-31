<?php

namespace App\Http\Controllers;

use App\Exceptions\MikrotikException;
use App\Http\Requests\MikrotikRequest;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceModel;
use App\Models\Manufacturer;
use App\Models\MikrotikConnection;
use App\Models\Site;
use App\Support\MikrotikClient;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MikrotikController extends Controller
{
    public function update(MikrotikRequest $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $data = $request->connectionData();
        $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) use ($data) {
            $password = $data['password'] ?? null;
            if ($password === null && (! $connection || $connection->server_url !== $data['server_url'] || $connection->username !== $data['username'])) {
                throw ValidationException::withMessages(['password' => 'Inserisci la password per questo indirizzo e username.']);
            }
            $connection ??= $device->mikrotikConnection()->make();
            $connection->fill([
                'server_url' => $data['server_url'], 'username' => $data['username'],
                'allow_unverified_certificate' => $data['allow_unverified_certificate'],
                'revision' => (string) Str::uuid(), 'checked_at' => null, 'check_status' => null,
                'identity' => null, 'analyzed_at' => null, 'analysis' => null,
            ]);
            if ($password !== null) {
                $connection->password = $password;
            }
            $connection->save();
        });

        return $this->saved($company, $site, $device, 'Connessione salvata.');
    }

    public function destroy(Request $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $this->locked($request, $device, fn (Device $device, ?MikrotikConnection $connection) => $connection?->delete());

        return $this->saved($company, $site, $device, 'Connessione rimossa.');
    }

    public function verify(Request $request, Company $company, Site $site, Device $device, MikrotikClient $client): RedirectResponse
    {
        return $this->inspect($request, $company, $site, $device, $client, false);
    }

    public function analyze(Request $request, Company $company, Site $site, Device $device, MikrotikClient $client): RedirectResponse
    {
        return $this->inspect($request, $company, $site, $device, $client, true);
    }

    private function inspect(Request $request, Company $company, Site $site, Device $device, MikrotikClient $client, bool $analyze): RedirectResponse
    {
        $lock = Cache::lock('mikrotik-device:'.$device->id, 90);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'Una lettura è già in corso. Attendi e riprova.']);
        }
        try {
            $connection = $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) {
                if (! $connection) {
                    throw ValidationException::withMessages(['connection' => 'Salva prima la connessione.']);
                }

                return $connection;
            });
            try {
                $result = $client->inspect($connection, $analyze);
            } catch (MikrotikException $exception) {
                $result = ['status' => $exception->status, 'identity' => null, 'analysis' => null];
            }
            $this->locked($request, $device, function (Device $device, ?MikrotikConnection $current) use ($connection, $result, $analyze) {
                if ($current?->id !== $connection->id) {
                    throw ValidationException::withMessages(['connection' => 'La connessione è cambiata. Ricarica la pagina.']);
                }
                $current->fill(['checked_at' => now(), 'check_status' => $result['status'], 'identity' => $result['identity']]);
                if ($analyze && $result['analysis'] !== null) {
                    $current->fill(['analyzed_at' => now(), 'analysis' => $result['analysis']]);
                }
                $current->save();
            });
        } finally {
            $lock->release();
        }

        return $this->saved($company, $site, $device,
            $result['status'] === 'ok' && $analyze ? 'Ricognizione completata.' : MikrotikException::messageFor($result['status']),
            $result['status'] === 'ok' ? 'success' : 'error');
    }

    private function locked(Request $request, Device $device, Closure $action): mixed
    {
        $data = $request->validate(['revision' => ['present', 'nullable', 'uuid']]);

        return DB::transaction(function () use ($device, $data, $action) {
            // Catalog locks serialize connection creation with manufacturer/model changes.
            $manufacturer = Manufacturer::query()->sharedLock()->findOrFail($device->manufacturer_id);
            if ($device->device_model_id !== null) {
                DeviceModel::query()->sharedLock()->findOrFail($device->device_model_id);
            }
            $current = Device::query()->lockForUpdate()->findOrFail($device->id);
            abort_unless($manufacturer->isMikrotik(), 404);
            if ($current->manufacturer_id !== $device->manufacturer_id || $current->device_model_id !== $device->device_model_id) {
                throw ValidationException::withMessages(['connection' => 'Il dispositivo è cambiato. Ricarica la pagina.']);
            }
            $connection = $current->mikrotikConnection()->first();
            if ($connection?->revision !== $data['revision']) {
                throw ValidationException::withMessages(['connection' => 'La connessione è cambiata. Ricarica la pagina prima di continuare.']);
            }

            return $action($current, $connection);
        }, 3);
    }

    private function saved(Company $company, Site $site, Device $device, string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('companies.sites.devices.show', [$company, $site, $device, 'tab' => 'mikrotik']);
    }
}
