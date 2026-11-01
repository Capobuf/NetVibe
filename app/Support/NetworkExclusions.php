<?php

namespace App\Support;

use App\Models\MikrotikSource;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Support\Collection;

class NetworkExclusions
{
    private Collection $vlans;

    private Collection $subnets;

    private Collection $vlanSources;

    private Collection $excludedSources;

    private Collection $externalSubnets;

    public function __construct(int $siteId)
    {
        $this->vlans = Vlan::where('site_id', $siteId)->whereNotNull('excluded_at')->get()->keyBy('id');
        $this->subnets = Subnet::where('site_id', $siteId)->excluded()->get()->keyBy('id');
        $this->vlanSources = MikrotikSource::where('site_id', $siteId)->where('kind', 'vlan')
            ->whereIn('vlan_id', $this->vlans->keys())->get();
        $this->excludedSources = MikrotikSource::where('site_id', $siteId)->whereNotNull('excluded_at')->get();
        $this->externalSubnets = $this->excludedSources->isEmpty() ? collect()
            : MikrotikSource::where('site_id', $siteId)->where('kind', 'subnet')->get();
    }

    public function target(MikrotikSource $source): ?array
    {
        $vlan = $this->vlans->get($source->vlan_id);
        if (! $vlan && isset($source->data['tag'], $source->data['context_key'])) {
            // Only a previously associated L2 context can inherit a VLAN exclusion.
            $parent = $this->vlanSources->first(fn ($parent) => $parent->device_id === $source->device_id
                && $parent->generation === $source->generation
                && ($parent->anchor['tag'] ?? null) === $source->data['tag']
                && ($parent->anchor['context_key'] ?? null) === $source->data['context_key']);
            $vlan = $parent ? $this->vlans->get($parent->vlan_id) : null;
        }
        $subnet = $this->subnets->get($source->subnet_id)
            ?? $this->subnets->first(fn ($subnet) => ($source->data['cidr'] ?? null) === $subnet->cidr
                || ($source->address !== null && $source->address >= $subnet->network_address && $source->address <= $subnet->broadcast_address));
        $vlan ??= $subnet ? $this->vlans->get($subnet->vlan_id) : null;

        if ($vlan || $subnet) {
            return $vlan ? ['kind' => 'vlan', 'id' => $vlan->id, 'label' => 'VLAN '.$vlan->tag]
                : ['kind' => 'subnet', 'id' => $subnet->id, 'label' => $subnet->cidr];
        }
        foreach ($this->excludedSources as $excluded) {
            if ($source->device_id !== $excluded->device_id || $source->generation !== $excluded->generation) {
                continue;
            }
            $matches = $source->id === $excluded->id || $this->matchesExternal($source, $excluded);
            if (! $matches && $source->address !== null) {
                $matches = $this->externalSubnets->contains(fn ($network) => $network->device_id === $source->device_id
                    && $network->generation === $source->generation && $this->matchesExternal($network, $excluded)
                    && $this->containsAddress($network->data['cidr'], $source->address));
            }
            if ($matches) {
                return ['kind' => 'source', 'id' => $excluded->id, 'device' => $excluded->device_id,
                    'label' => $excluded->kind === 'vlan' ? 'VLAN '.$excluded->anchor['tag'] : $excluded->anchor['cidr']];
            }
        }

        return null;
    }

    private function matchesExternal(MikrotikSource $source, MikrotikSource $excluded): bool
    {
        if ($excluded->kind === 'subnet') {
            return ($source->data['cidr'] ?? null) === $excluded->anchor['cidr']
                || ($source->address !== null && $this->containsAddress($excluded->anchor['cidr'], $source->address));
        }

        return isset($source->data['context_key'], $excluded->anchor['context_key'])
            && ($source->data['tag'] ?? null) === $excluded->anchor['tag']
            && $source->data['context_key'] === $excluded->anchor['context_key'];
    }

    private function containsAddress(string $cidr, int $address): bool
    {
        $range = Ipv4Cidr::parse($cidr);

        return $range && $address >= $range['network_address'] && $address <= $range['broadcast_address'];
    }

    public function display(Collection $sources): Collection
    {
        return $sources->map(function (MikrotikSource $source) {
            $exclusion = $this->target($source);
            $display = $source->display();

            return [...$display, 'exclusion' => $exclusion, 'excluded_at' => $source->excluded_at,
                'can_associate' => ! $exclusion && $display['can_associate']];
        });
    }
}
