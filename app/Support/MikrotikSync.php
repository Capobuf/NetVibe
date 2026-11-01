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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MikrotikSync
{
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
            try {
                $snapshot = app(MikrotikClient::class)->snapshot($connection);
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
            DB::transaction(function () use ($connection, $device, $attempted, $status, $objects, $identity) {
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
                $current->fill(['sync_attempted_at' => $attempted, 'sync_status' => $status]);
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
            $source->fill([...$object, 'generation' => $connection->source_generation, 'state' => 'current', 'seen_at' => now()]);
            $plannedIp = $source->ip_address_id ? IpAddress::find($source->ip_address_id) : null;
            $expectedAddress = $plannedIp?->mikrotik_edit_mode === 'planned'
                && in_array($source->id, $plannedIp->mikrotik_expected['source_ids'] ?? [], true)
                && isset($plannedIp->mikrotik_expected['values']['address'])
                && $plannedIp->mikrotik_expected['values']['address'] === $object['address']
                && array_diff_key($source->getOriginal('anchor') ?? [], ['address' => true]) == array_diff_key($object['anchor'], ['address' => true]);
            $expectedMatch = $source->edit_mode === 'planned' && $source->expected !== null
                && array_intersect_key($object['data'], $source->expected) == $source->expected
                && ($source->getOriginal('anchor')['context_key'] ?? null) === ($object['anchor']['context_key'] ?? null);
            if ($expectedMatch) {
                $source->matched_at = now();
            }
            if ($anchorChanged && ! $expectedMatch && ! $expectedAddress) {
                // RouterOS .id can be recycled after a restore. Never transfer a linked object's identity.
                $source->anchor = $previous[$key]->getOriginal('anchor');
                $source->confirmed = false;
                $source->problem = 'La configurazione o identità dell’oggetto sorgente è cambiata. Verifica l’associazione esistente.';
            }
            if (! $source->problem) {
                [$new, $linked] = $this->associate($site, $device, $source, $sources);
                $created += $new;
                $associated += $linked;
            }
            $source->save();
            $sources[$key] = $source;
        }
        $device->mikrotikSources()->where('generation', $connection->source_generation)
            ->whereNotIn('id', array_map(fn ($source) => $source->id, $sources))->update(['state' => 'missing']);
        MikrotikIpam::recognizeExpected($site->id, $device->id);

        return ['created' => $created, 'associated' => $associated, 'updated' => $updated, 'read' => count($sources),
            'problems' => count(array_filter($sources, fn ($source) => $source->problem !== null))];
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
            if ($source->linked && (! $ip || $ip->id !== $source->ip_address_id)) {
                $source->problem = 'Il record IP documentato è stato modificato o rimosso.';

                return [0, 0];
            }
            $source->subnet_id = $subnet->id;
            $new = false;
            if (! $ip && $source->kind === 'address') {
                $ip = $subnet->ipAddresses()->create(['address' => $source->address, 'device_id' => $device->id]);
                $new = true;
            }
            // DHCP comments and observed hostnames never satisfy I4 by being copied into manual fields.
            if ($ip) {
                if ($source->kind === 'address' && $ip->device_id !== $device->id) {
                    $source->problem = 'Dispositivo documentato diverso dal MikroTik che configura questo indirizzo.';
                }
                $source->ip_address_id = $ip->id;
                $linked = ! $source->linked;
                $source->linked = true;

                return [(int) $new, (int) $linked];
            }
        }

        return [0, 0];
    }
}
