<?php

namespace App\Support;

use App\Exceptions\MikrotikException;
use App\Models\Company;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\MikrotikConnection;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\Subnet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MikrotikSync
{
    private ?CarbonInterface $cutoff = null;

    public function refresh(MikrotikConnection $connection): void
    {
        $lock = Cache::lock('mikrotik-device:'.$connection->device_id, 90);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['connection' => 'Una lettura è già in corso. Attendi e riprova.']);
        }
        try {
            $fresh = $connection->fresh();
            if (! $fresh || $fresh->revision !== $connection->revision || $fresh->source_generation !== $connection->source_generation) {
                throw ValidationException::withMessages(['connection' => 'La connessione è cambiata. Ricarica la pagina.']);
            }
            $connection = $fresh;
            if (! $connection->activated_at || ! $connection->source_generation) {
                throw ValidationException::withMessages(['connection' => 'Attiva prima la sincronizzazione per questa sorgente.']);
            }
            $attempted = now();
            $objects = [];
            $identity = null;
            $client = app(MikrotikClient::class);
            try {
                $snapshot = $client->snapshot($connection);
                if ($snapshot['status'] !== 'ok') {
                    throw new MikrotikException($snapshot['status']);
                }
                $objects = (new MikrotikSnapshot)->normalize($snapshot['tables']);
                $identity = array_intersect_key($snapshot['identity'], array_flip(['name', 'board', 'architecture']));
                if ($connection->sync_identity !== null && $connection->sync_identity != $identity) {
                    throw new MikrotikException('source_changed');
                }
                $status = 'ok';
            } catch (MikrotikException $exception) {
                $status = $exception->status;
            }
            $device = Device::with('site')->findOrFail($connection->device_id);
            DB::transaction(function () use ($connection, $device, $attempted, $status, $objects, $identity, $client) {
                Company::query()->lockForUpdate()->findOrFail($device->site->company_id);
                $site = Site::query()->lockForUpdate()->findOrFail($device->site_id);
                $device = $site->devices()->lockForUpdate()->findOrFail($device->id);
                $current = $device->mikrotikConnection()->lockForUpdate()->first();
                if ($current?->id !== $connection->id || $current->revision !== $connection->revision
                    || ! $current->activated_at || $current->source_generation !== $connection->source_generation) {
                    throw ValidationException::withMessages(['connection' => 'La sorgente è cambiata durante la lettura. Ricarica la pagina.']);
                }
                if ($current->sync_attempted_at?->greaterThan($attempted)) {
                    throw ValidationException::withMessages(['connection' => 'È già disponibile un tentativo più recente. Ricarica la pagina.']);
                }
                $current->fill(['sync_attempted_at' => $attempted, 'sync_status' => $status, 'sync_log' => $client->diagnosticLog()]);
                if ($status === 'source_changed') {
                    $current->activated_at = null;
                    $device->mikrotikSources()->where('generation', $current->source_generation)->where('state', 'current')->update(['state' => 'unknown']);
                }
                if ($status === 'ok') {
                    $counts = $this->apply($site, $device, $current, $objects);
                    $current->fill(['synced_at' => $attempted, 'sync_identity' => $identity, 'sync_summary' => $counts]);
                }
                $current->save();
            }, 3);
            if ($status !== 'ok') {
                throw ValidationException::withMessages(['connection' => MikrotikException::messageFor($status)]);
            }
        } finally {
            $lock->release();
        }
    }

    private function apply(Site $site, Device $device, MikrotikConnection $connection, array $objects): array
    {
        $exclusions = new NetworkExclusions($site->id);
        $this->cutoff = IpamEvidence::cutoff($site->id);
        $previous = $device->mikrotikSources()->where('generation', $connection->source_generation)->lockForUpdate()->get()->keyBy(fn ($source) => $source->kind.':'.$source->source_key);
        $sources = [];
        $created = $associated = $updated = 0;
        foreach ($objects as $object) {
            $key = $object['kind'].':'.$object['source_key'];
            if (isset($sources[$key])) {
                throw new MikrotikException('format');
            }
            $source = $previous->get($key) ?? $device->mikrotikSources()->make();
            $updated += (int) ($source->exists && ($source->data != $object['data'] || $source->state !== 'current'));
            $source->site_id = $site->id;
            $anchorChanged = $source->exists && $source->anchor != $object['anchor'];
            if (($object['data']['menu'] ?? null) === '/ip/neighbor') {
                if ($object['data']['lldp_read'] ?? false) {
                    $object['data']['lldp_seen_at'] = $object['data']['lldp'] ? now()->toIso8601String() : null;
                } elseif (($source->data['mac'] ?? null) === ($object['data']['mac'] ?? null)
                    && ($source->data['interface'] ?? null) === ($object['data']['interface'] ?? null)
                    && ($source->data['identity'] ?? null) === ($object['data']['identity'] ?? null)) {
                    // An unavailable optional menu must not erase the last known LLDP details.
                    $object['data']['lldp'] = $source->data['lldp'] ?? null;
                    $object['data']['lldp_seen_at'] = $source->data['lldp_seen_at'] ?? null;
                }
            }
            $present = $this->presence($object, $source);
            $source->fill([...$object, 'generation' => $connection->source_generation, 'state' => 'current', 'seen_at' => now()]);
            if ($present) {
                $source->present_at = now();
            }
            $plannedIp = $source->ip_address_id ? IpAddress::find($source->ip_address_id) : null;
            $expectedAddress = $plannedIp?->mikrotik_edit_mode === 'planned'
                && in_array($source->id, $plannedIp->mikrotik_expected['source_ids'] ?? [], true)
                && isset($plannedIp->mikrotik_expected['values']['address'])
                && $plannedIp->mikrotik_expected['values']['address'] === $object['address']
                && array_diff_key($source->getOriginal('anchor') ?? [], ['address' => true]) == array_diff_key($object['anchor'], ['address' => true]);
            $expectedMatch = $source->edit_mode === 'planned' && $source->expected !== null
                && array_intersect_key($object['data'], $source->expected) == $source->expected
                && ($source->getOriginal('anchor')['context_key'] ?? null) === ($object['anchor']['context_key'] ?? null);
            if ($expectedMatch && ! $exclusions->target($source)) {
                $source->matched_at = now();
            }
            if ($anchorChanged && ! $expectedMatch && ! $expectedAddress) {
                // RouterOS .id can be recycled after a restore. Never transfer a linked object's identity.
                $source->anchor = $previous[$key]->getOriginal('anchor');
                $source->confirmed = false;
                $source->problem = 'La configurazione o identità dell’oggetto sorgente è cambiata. Verifica l’associazione esistente.';
            }
            if (! $source->problem && ! $exclusions->target($source)) {
                [$new, $linked] = $this->associate($site, $device, $source, $sources);
                $created += $new;
                $associated += $linked;
            }
            $source->save();
            $sources[$key] = $source;
        }
        $device->mikrotikSources()->where('generation', $connection->source_generation)
            ->whereNotIn('id', array_map(fn ($source) => $source->id, $sources))->update(['state' => 'missing']);
        $associated += $this->associateInterfaces($device, $sources);
        MikrotikIpam::recognizeExpected($site->id, $device->id);
        IpamEvidence::populate($site->id);
        IpamEvidence::expire($site->id);

        return ['created' => $created, 'associated' => $associated, 'updated' => $updated, 'read' => count($sources),
            'problems' => count(array_filter($sources, fn ($source) => $source->displayProblem() !== null && ! $exclusions->target($source)))];
    }

    /**
     * Last reliable presence, kept apart from the read time: a configured address is present on the
     * router that answered, a lease only while bound, and an ARP entry only when reachable or when it
     * first appears. A lingering stale entry must not extend the retention period.
     */
    private function presence(array $object, MikrotikSource $previous): bool
    {
        $data = $object['data'];
        $status = $data['status'] ?? null;

        return match (true) {
            $object['kind'] === 'address' => $data['active'] === true,
            $data['menu'] === '/ip/dhcp-server/lease' => $status === 'bound',
            $data['menu'] === '/ip/arp' => $data['mac'] !== null && ! in_array($status, ['failed', 'incomplete'], true)
                && ($status === 'reachable' || ! $previous->exists || $previous->state !== 'current'),
            $data['menu'] === '/ip/neighbor' => $object['address'] !== null,
            default => false,
        };
    }

    // The caller holds the device lock in the model-save transaction.
    public function applySavedInterfaceMap(Device $device): void
    {
        $connection = $device->mikrotikConnection()->lockForUpdate()->first();
        if (! $connection?->activated_at || $connection->sync_status !== 'ok' || ! $connection->source_generation) {
            return;
        }
        $sources = $device->mikrotikSources()->where('kind', 'interface')
            ->where('generation', $connection->source_generation)->where('state', 'current')
            ->lockForUpdate()->get()->all();
        $this->associateInterfaces($device, $sources);
    }

    private function associateInterfaces(Device $device, array $sources): int
    {
        $ports = collect($device->model?->connectors() ?? [])->keyBy('id');
        if ($ports->isEmpty()) {
            return 0;
        }
        $physical = collect($sources)->where('kind', 'interface');
        $matches = MikrotikPortMap::resolve($ports->values()->all(), $physical->pluck('data.default_name')->filter()->all());
        $interfaces = $device->interfaces()->setEagerLoads([])->where('kind', 'physical')->lockForUpdate()->get()->keyBy('id');
        foreach ($physical as $source) {
            $interface = $interfaces->get($source->device_interface_id);
            $port = $interface ? $ports->get($interface->connector_id) : null;
            if (! $source->interface_from_model || ! $port || $source->problem) {
                continue;
            }
            // Apply model corrections only to model-managed links; retain observations on missing/ambiguous reads.
            $explicit = array_key_exists('mikrotikName', $port);
            $wanted = $explicit ? $port['mikrotikName'] : ($matches[$port['id']] ?? null);
            $target = array_search($source->data['default_name'], $matches, true);
            if ((($explicit || $wanted !== null) && $wanted !== $source->data['default_name'])
                || ($target !== false && $target !== $port['id'])) {
                $source->device_interface_id = null;
                $source->interface_from_model = false;
                $source->save();
            }
        }
        $available = $device->interfaces()->setEagerLoads([])->where('kind', 'physical')
            ->whereDoesntHave('mikrotikSource')->get()->keyBy('connector_id');
        $byName = $physical->groupBy(fn ($source) => $source->data['default_name'] ?? '');
        $associated = 0;
        foreach ($matches as $connectorId => $name) {
            $source = $byName[$name]->first();
            $interface = $available->get($connectorId);
            if (! $interface || $source->problem || $source->device_interface_id) {
                continue;
            }
            $source->device_interface_id = $interface->id;
            $source->interface_from_model = true;
            $source->save();
            $associated++;
        }

        return $associated;
    }

    private function associate(Site $site, Device $device, MikrotikSource $source, array $sources): array
    {
        $data = $source->data;
        if ($source->kind === 'vlan') {
            $vlan = $site->vlans()->where('tag', $data['tag'])->first();
            if ($source->linked && (! $vlan || $vlan->id !== $source->vlan_id)) {
                $source->problem = 'La VLAN documentata è stata modificata o rimossa.';

                return [0, 0];
            }
            $other = MikrotikSource::where('site_id', $site->id)->where('kind', 'vlan')->where('state', '!=', 'missing')
                ->where('data->tag', $data['tag'])->where(fn ($query) => $query->where('device_id', '!=', $device->id)
                ->orWhere('generation', '!=', $source->generation))->exists();
            if ($other && ! $source->confirmed && ! $source->linked) {
                $source->problem = 'VLAN riportata da un’altra sorgente: conferma che il contesto L2 sia lo stesso.';

                return [0, 0];
            }
            $new = $vlan === null;
            $vlan ??= $site->vlans()->create(['tag' => $data['tag']]);
            $source->vlan_id = $vlan->id;
            $linked = ! $source->linked;
            $source->linked = true;

            return [(int) $new, (int) $linked];
        }
        if ($source->kind === 'subnet') {
            $vlanId = null;
            if ($data['tag'] !== null) {
                $vlanSources = array_filter($sources, fn ($item) => $item->kind === 'vlan' && ($item->data['tag'] ?? null) === $data['tag']
                    && ($item->data['context_key'] ?? null) === $data['context_key'] && ! $item->problem && $item->vlan_id);
                if (! $vlanSources) {
                    $source->problem = 'Associazione VLAN da verificare prima della subnet.';

                    return [0, 0];
                }
                $vlanId = reset($vlanSources)->vlan_id;
            }
            $range = Ipv4Cidr::parse($data['cidr']);
            $overlap = $site->subnets()->where('network_address', '<=', $range['broadcast_address'])
                ->where('broadcast_address', '>=', $range['network_address'])->lockForUpdate()->get();
            if ($overlap->contains(fn ($subnet) => $subnet->cidr !== $range['cidr'])) {
                $source->problem = 'Sovrapposizione con '.$overlap->pluck('cidr')->join(', ').'.';

                return [0, 0];
            }
            $subnet = $overlap->first();
            if ($source->linked && (! $subnet || $subnet->id !== $source->subnet_id)) {
                $source->problem = 'La subnet documentata è stata modificata o rimossa.';

                return [0, 0];
            }
            if ($subnet && $subnet->vlan_id !== $vlanId) {
                $source->subnet_id = $subnet->id;
                $source->problem = 'VLAN documentata diversa dalla fonte. Verifica l’associazione della subnet.';

                return [0, 0];
            }
            $new = $subnet === null;
            $subnet ??= $site->subnets()->create([...$range, 'vlan_id' => $vlanId]);
            $source->subnet_id = $subnet->id;
            $source->vlan_id = $vlanId;
            $linked = ! $source->linked;
            $source->linked = true;

            return [(int) $new, (int) $linked];
        }
        if (in_array($source->kind, ['address', 'lease'], true) && $source->address !== null) {
            $network = $sources['subnet:'.($data['network_key'] ?? '')] ?? null;
            if (! $network || $network->problem || ! $network->subnet_id) {
                $source->problem = 'Subnet sorgente da verificare prima dell’indirizzo.';

                return [0, 0];
            }
            $subnet = Subnet::where('site_id', $site->id)->lockForUpdate()->findOrFail($network->subnet_id);
            [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
            if ($source->address < $first || $source->address > $last) {
                $source->problem = 'Indirizzo non utilizzabile come host in questa subnet.';

                return [0, 0];
            }
            $ip = $subnet->ipAddresses()->where('address', $source->address)->lockForUpdate()->first();
            // A record moved by the technician keeps its link; an explicitly deleted one is documented again.
            if ($source->linked && $source->ip_address_id !== null && $ip?->id !== $source->ip_address_id) {
                $source->problem = 'Il record IP documentato è stato modificato o rimosso.';

                return [0, 0];
            }
            $source->subnet_id = $subnet->id;
            [$ip, $new] = IpamEvidence::document($subnet, $source->address, $source->kind === 'address' ? $device->id : null);
            if ($source->kind === 'address' && $ip->device_id !== $device->id) {
                $source->problem = 'Dispositivo documentato diverso dal MikroTik che configura questo indirizzo.';
            }
            $source->ip_address_id = $ip->id;
            $linked = ! $source->linked;
            $source->linked = true;

            return [(int) $new, (int) $linked];
        }
        if ($source->kind === 'observation' && $source->address !== null && in_array($data['menu'], ['/ip/arp', '/ip/dhcp-server/lease', '/ip/neighbor'], true)) {
            // Temporary evidence never moves a record: it follows the address and needs a MAC and a recent presence.
            $subnet = IpamEvidence::subnetFor($site->id, $source->address);
            $ip = $subnet?->ipAddresses()->where('address', $source->address)->first();
            $new = false;
            if ($subnet && ! $ip && ! empty($data['mac']) && $source->present_at?->gte($this->cutoff)) {
                [$ip, $new] = IpamEvidence::document($subnet, $source->address);
            }
            $source->subnet_id = $ip ? $subnet->id : null;
            $source->ip_address_id = $ip?->id;

            return [(int) $new, 0];
        }

        return [0, 0];
    }
}
