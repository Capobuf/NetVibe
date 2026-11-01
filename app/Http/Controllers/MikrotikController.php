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
use App\Support\MikrotikSync;
use App\Support\NetworkExclusions;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MikrotikController extends Controller
{
    public function setupCommand(Request $request, Company $company, Site $site, Device $device): JsonResponse
    {
        abort_unless($device->manufacturer?->isMikrotik(), 404);
        $data = $request->validate(['port' => ['required', 'integer', 'between:1,65535']], [], ['port' => 'porta']);
        try {
            $response = Http::accept('text/plain')->connectTimeout(3)->timeout(5)->withoutRedirecting()
                ->withOptions(['proxy' => '', 'force_ip_resolve' => 'v4'])->get('https://api.ipify.org');
            $publicIp = $response->successful() ? trim($response->body()) : '';
        } catch (ConnectionException) {
            $publicIp = '';
        }
        if (! filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)
            || ip2long($publicIp) >= ip2long('224.0.0.0')) {
            throw ValidationException::withMessages(['setup' => 'Impossibile rilevare l’IPv4 pubblico di NetVibe. Riprova.']);
        }
        $username = 'netvibe-rest-api';
        $password = Str::password(48, symbols: false);
        $port = (int) $data['port'];
        $rule = '/ip firewall filter add chain=input action=accept protocol=tcp dst-port='.$port.' src-address='.$publicIp.'/32 comment="NetVibe REST API"';
        $command = <<<ROS
        {
            :if ([:len [/user find where name="$username"]] > 0) do={ :error "Utente $username gia esistente: nessuna modifica eseguita"; };
            :if ([:len [/user group find where name="$username"]] > 0) do={ :error "Gruppo $username gia esistente: nessuna modifica eseguita"; };
            /user group add name="$username" policy=read,api,rest-api;
            /user add name="$username" group="$username" password="$password" address=$publicIp/32 comment="NetVibe REST API";
            :local firstRule [:pick [/ip firewall filter find where dynamic=no] 0];
            :if ([:len \$firstRule] > 0) do={
                $rule place-before=\$firstRule;
            } else={
                $rule;
            };
            /ip service set [find where name="www-ssl"] disabled=no port=$port;
        }
        ROS;

        return response()->json([
            'username' => $username, 'password' => $password, 'port' => $port,
            'public_ip' => $publicIp, 'command' => $command,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(MikrotikRequest $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $data = $request->connectionData();
        $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) use ($data) {
            $password = $data['password'] ?? null;
            if ($password === null && (! $connection || $connection->server_url !== $data['server_url'] || $connection->username !== $data['username'])) {
                throw ValidationException::withMessages(['password' => 'Inserisci la password per questo indirizzo e username.']);
            }
            if ($connection && $connection->server_url !== $data['server_url']) {
                $device->mikrotikSources()->where('state', 'current')->update(['state' => 'unknown']);
                $connection->fill(['activated_at' => null, 'source_generation' => null, 'sync_identity' => null, 'sync_status' => 'suspended', 'sync_log' => null]);
            }
            $connection ??= $device->mikrotikConnection()->make();
            $connection->fill([
                'server_url' => $data['server_url'], 'username' => $data['username'],
                'allow_unverified_certificate' => $data['allow_unverified_certificate'],
                'revision' => (string) Str::uuid(), 'checked_at' => null, 'check_status' => null,
                'identity' => null, 'analyzed_at' => null, 'analysis' => null, 'check_log' => null,
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
        $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) {
            $device->mikrotikSources()->where('state', 'current')->update(['state' => 'unknown']);
            $connection?->delete();
        });

        return $this->saved($company, $site, $device, 'Connessione rimossa.');
    }

    public function activate(Request $request, Company $company, Site $site, Device $device, MikrotikSync $sync): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);
        $connection = $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) {
            if (! $connection) {
                throw ValidationException::withMessages(['connection' => 'Salva prima la connessione.']);
            }
            if ($connection->check_status !== 'ok') {
                throw ValidationException::withMessages(['connection' => 'Verifica la connessione prima di sincronizzare.']);
            }
            if (! $connection->activated_at) {
                $device->mikrotikSources()->where('state', 'current')->update(['state' => 'unknown']);
                $connection->fill(['activated_at' => now(), 'source_generation' => (string) Str::uuid(), 'sync_identity' => null])->save();
            }

            return $connection;
        });
        $sync->refresh($connection);

        return $this->saved($company, $site, $device, 'Sincronizzazione attivata e prima lettura completata.');
    }

    public function sync(Request $request, Company $company, Site $site, Device $device, MikrotikSync $sync): RedirectResponse
    {
        $connection = $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) {
            if (! $connection) {
                throw ValidationException::withMessages(['connection' => 'Salva prima la connessione.']);
            }
            if ($connection->check_status !== 'ok') {
                throw ValidationException::withMessages(['connection' => 'Verifica la connessione prima di sincronizzare.']);
            }

            return $connection;
        });
        $sync->refresh($connection);

        return $this->saved($company, $site, $device, 'Sincronizzazione completata.');
    }

    public function associate(Request $request, Company $company, Site $site, Device $device): RedirectResponse
    {
        $data = $request->validate(['source_id' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $company, $site, $device, $data) {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $site = Site::query()->lockForUpdate()->findOrFail($site->id);
            $this->locked($request, $device, function (Device $device, ?MikrotikConnection $connection) use ($site, $data) {
                if (! $connection?->activated_at || $connection->sync_status !== 'ok') {
                    throw ValidationException::withMessages(['connection' => 'Completa una sincronizzazione prima di confermare l’associazione.']);
                }
                $source = $device->mikrotikSources()->where('generation', $connection->source_generation)->where('state', 'current')->lockForUpdate()->findOrFail($data['source_id']);
                if ((new NetworkExclusions($site->id))->target($source)) {
                    throw ValidationException::withMessages(['connection' => 'Reincludi la rete prima di associarla.']);
                }
                if ($source->kind === 'vlan' && $source->problem === 'VLAN riportata da un’altra sorgente: conferma che il contesto L2 sia lo stesso.') {
                    $vlan = $site->vlans()->where('tag', $source->data['tag'])->firstOrFail();
                    $source->fill(['vlan_id' => $vlan->id, 'linked' => true, 'confirmed' => true, 'problem' => null])->save();
                } elseif ($source->kind === 'subnet' && $source->subnet_id && $source->data['tag'] !== null
                    && $source->problem === 'VLAN documentata diversa dalla fonte. Verifica l’associazione della subnet.') {
                    $subnet = $site->subnets()->lockForUpdate()->findOrFail($source->subnet_id);
                    $vlan = $site->vlans()->where('tag', $source->data['tag'])->firstOrFail();
                    if ($subnet->vlan_id !== null || $subnet->cidr !== $source->data['cidr']) {
                        throw ValidationException::withMessages(['connection' => 'La subnet ha già una VLAN o il CIDR è cambiato. Verifica il dato documentato.']);
                    }
                    $subnet->update(['vlan_id' => $vlan->id]);
                    $source->fill(['vlan_id' => $vlan->id, 'linked' => true, 'confirmed' => true, 'problem' => null])->save();
                } else {
                    throw ValidationException::withMessages(['connection' => 'Questo caso richiede una verifica della configurazione o del record documentato.']);
                }
            });
        }, 3);

        return $this->saved($company, $site, $device, 'Associazione confermata. Sincronizza per aggiornare gli elementi dipendenti.');
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
            $this->locked($request, $device, function (Device $device, ?MikrotikConnection $current) use ($connection, $result, $analyze, $client) {
                if ($current?->id !== $connection->id) {
                    throw ValidationException::withMessages(['connection' => 'La connessione è cambiata. Ricarica la pagina.']);
                }
                $current->fill(['checked_at' => now(), 'check_status' => $result['status'], 'identity' => $result['identity'], 'check_log' => $client->diagnosticLog()]);
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

        return to_route('companies.sites.devices.show', [$company, $site, $device, 'manage' => 'mikrotik']);
    }
}
