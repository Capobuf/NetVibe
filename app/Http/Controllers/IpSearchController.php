<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\UnifiObservation;
use App\Models\Vlan;
use App\Support\CompanySearch;
use App\Support\DeviceAssignment;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IpSearchController extends Controller
{
    public function index(Request $request, Company $company, Site $site): Response
    {
        $columns = ['ip' => 'address', 'device' => 'assignment_label', 'hostname' => 'hostname',
            'mac_address' => 'mac_address', 'site' => 'site', 'subnet' => 'network_address'];
        $options = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys($columns))],
            'direction' => ['sometimes', 'in:asc,desc'],
            'group' => ['sometimes', 'in:none,site'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'tab' => ['sometimes', 'in:ip,devices,models,racks,cables'],
        ]);
        $search = $options['search'] ?? '';
        $inventory = new CompanySearch($company, $search);
        $sort = $options['sort'] ?? null;
        $group = $options['group'] ?? 'none';
        $direction = $sort === null ? 'asc' : ($options['direction'] ?? 'asc');
        $results = $this->matches($company, $search)
            ->when($group === 'site', fn ($query) => $query->orderBy('site', $sort === 'site' ? $direction : 'asc')->orderBy('site_id'))
            ->orderBy($columns[$sort ?? 'ip'], $direction)->orderBy('subnet_id')->orderBy('address')->paginate((int) ($options['per_page'] ?? 25))->withQueryString();
        $records = IpAddress::whereIn('id', collect($results->items())->pluck('id')->filter())
            ->with(['device:id,name', 'deviceInterface:id,device_id,name,kind,connector_id,mac_address'])->get()->keyBy('id');
        $sources = UnifiObservation::whereIn('site_id', collect($results->items())->pluck('site_id'))
            ->whereHas('unifiSite', fn ($query) => $query->whereColumn('unifi_sites.site_id', 'unifi_observations.site_id'))
            ->whereIn('address', collect($results->items())->pluck('address'))->with(['unifiSite.unifiConnection', 'vlan', 'subnet.vlan'])->get()
            ->groupBy(fn ($source) => $source->site_id.':'.$source->address);
        $results->through(function ($row) use ($records, $sources) {
            $record = $records->get($row->id);

            return [...collect((array) $row)->except(['address', 'network_address'])->all(), 'ip' => long2ip($row->address),
                'device_id' => $record?->device_id, 'device_interface_id' => $record?->device_interface_id,
                'device' => $record?->device, 'device_interface' => $record?->deviceInterface,
                'unifi_observations' => $sources->get($row->site_id.':'.$row->address, collect())->map->display()->all()];
        });

        return Inertia::render('search/index', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'results' => $results, 'query' => compact('search', 'sort', 'direction', 'group'),
            'tab' => $options['tab'] ?? null,
            // Inventory matches are few per company, so each list is sorted and paged in the browser.
            ...collect(['devices', 'models', 'racks', 'cables'])->mapWithKeys(fn ($kind) => [
                $kind => $search === '' ? [] : $inventory->{$kind}()['rows'],
            ]),
        ]);
    }

    /** Header search: networks as a VLAN → subnet → IP tree, then inventory and cabling sections. */
    public function suggestions(Request $request, Company $company, Site $site): JsonResponse
    {
        $search = $request->validate(['search' => ['required', 'string', 'max:255']])['search'];
        $pattern = CompanySearch::pattern($search);
        $sites = $company->sites()->pluck('name', 'id');
        $matches = $this->matches($company, $search);
        $ipTotal = (clone $matches)->count();
        $ips = $matches->orderBy('address')->orderBy('subnet_id')->limit(6)->get();
        $matchedSubnets = Subnet::visible()->whereIn('site_id', $sites->keys())
            ->where(fn ($query) => $query->whereRaw("cidr LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]))
            ->orderBy('network_address')->limit(5)->pluck('id');
        $matchedVlans = Vlan::visible()->whereIn('site_id', $sites->keys())
            ->where(fn ($query) => $query->whereRaw("CAST(tag AS CHAR) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
            ->orderBy('tag')->limit(5)->pluck('id');
        // Parents of matching rows are included so every result appears under its VLAN and subnet.
        $subnets = Subnet::whereIn('id', $matchedSubnets->merge($ips->pluck('subnet_id')))->orderBy('network_address')->get();
        $vlans = Vlan::whereIn('id', $matchedVlans->merge($subnets->pluck('vlan_id')->filter()))->orderBy('tag')->get();
        $route = fn (int $siteId) => ['company' => $company->id, 'site' => $siteId];
        $row = fn (string $kind, int|string $key, string $label, ?string $detail, string $href, int $depth, ?int $siteId = null) => [
            'key' => "{$kind}:{$key}", 'kind' => $kind, 'label' => $label, 'detail' => $detail, 'href' => $href,
            'depth' => $depth, 'site' => $depth === 0 && $siteId ? $sites->get($siteId) : null,
        ];
        $subnetRows = fn (Subnet $subnet, int $depth) => [
            $row('subnet', $subnet->id, $subnet->cidr, $subnet->name, route('companies.sites.subnets.show', [...$route($subnet->site_id), 'subnet' => $subnet->id], false), $depth, $subnet->site_id),
            ...$ips->where('subnet_id', $subnet->id)->map(fn ($ip) => $row('ip', "{$subnet->id}:{$ip->address}", long2ip($ip->address),
                collect([$ip->hostname, $ip->assignment_label])->filter()->unique()->implode(' · ') ?: null,
                route('companies.sites.subnets.show', [...$route($subnet->site_id), 'subnet' => $subnet->id, 'search' => long2ip($ip->address), 'ip_address' => $ip->id], false), $depth + 1)),
        ];
        $network = [];
        foreach ($vlans as $vlan) {
            $network[] = $row('vlan', $vlan->id, "VLAN {$vlan->tag}", $vlan->name, route('companies.sites.vlans.show', [...$route($vlan->site_id), 'vlan' => $vlan->id], false), 0, $vlan->site_id);
            foreach ($subnets->where('vlan_id', $vlan->id) as $subnet) {
                array_push($network, ...$subnetRows($subnet, 1));
            }
        }
        foreach ($subnets->whereNull('vlan_id') as $subnet) {
            array_push($network, ...$subnetRows($subnet, 0));
        }
        if ($ipTotal > $ips->count()) {
            $network[] = $row('all', 'ip', "Mostra tutti gli IP ({$ipTotal})", null, route('companies.sites.search.index', [...$route($site->id), 'search' => $search, 'tab' => 'ip'], false), 0);
        }
        $all = fn (string $tab, int $total, int $shown) => $total > $shown
            ? [$row('all', $tab, "Mostra tutti ({$total})", null, route('companies.sites.search.index', [...$route($site->id), 'search' => $search, 'tab' => $tab], false), 0)] : [];
        $section = fn (string $label, string $tab, array $result, callable $map) => [
            'label' => $label, 'rows' => [...$result['rows']->map($map), ...$all($tab, $result['total'], $result['rows']->count())],
        ];
        $inventory = new CompanySearch($company, $search);

        return response()->json(collect([
            ['label' => 'Rete', 'rows' => $network],
            $section('Dispositivi', 'devices', $inventory->devices(5), fn ($device) => $row('device', $device['id'], $device['name'],
                trim("{$device['manufacturer']} {$device['model']}") ?: null, $device['href'], 0, $device['site_id'])),
            $section('Modelli', 'models', $inventory->models(5), fn ($model) => $row('model', $model['id'], $model['name'],
                collect([$model['manufacturer'], $model['type']])->filter()->implode(' · ') ?: null, $model['href'], 0)),
            $section('Rack', 'racks', $inventory->racks(5), fn ($rack) => $row('rack', $rack['id'], $rack['name'],
                $rack['floor'], $rack['href'], 0, $rack['site_id'])),
            $section('Cablaggi', 'cables', $inventory->cables(5), fn ($cable) => $row('cable', $cable['id'], $cable['name'] ?: $cable['code'],
                "{$cable['a']} → {$cable['b']}", $cable['href'], 0, $cable['site_id'])),
        ])->filter(fn ($section) => $section['rows'] !== [])->values());
    }

    /** Documented addresses and external UniFi observations of the company that match the search text. */
    private function matches(Company $company, string $search): Builder
    {
        $pattern = CompanySearch::pattern($search);
        $observations = UnifiObservation::query()->join('subnets', fn ($join) => $join
            ->on('subnets.site_id', '=', 'unifi_observations.site_id')
            ->on('subnets.network_address', '<=', 'unifi_observations.address')
            ->on('subnets.broadcast_address', '>=', 'unifi_observations.address'))
            ->join('sites', 'sites.id', '=', 'subnets.site_id')->where('sites.company_id', $company->id)
            ->whereHas('unifiSite', fn ($query) => $query->whereColumn('unifi_sites.site_id', 'unifi_observations.site_id'))
            ->whereIn('subnets.id', Subnet::visible()->select('id'))
            ->where(fn ($query) => $query->whereRaw("INET_NTOA(unifi_observations.address) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("CAST(unifi_observations.data AS CHAR) LIKE ? ESCAPE '!'", [$pattern]));

        $documented = IpAddress::query()
            ->join('subnets', 'subnets.id', '=', 'ip_addresses.subnet_id')->join('sites', 'sites.id', '=', 'subnets.site_id')
            ->where('sites.company_id', $company->id)->whereIn('ip_addresses.subnet_id', Subnet::visible()->select('id'))
            ->select(['ip_addresses.id', 'ip_addresses.address', 'ip_addresses.hostname', 'ip_addresses.mac_address',
                'sites.id as site_id', 'sites.name as site', 'subnets.id as subnet_id', 'subnets.cidr as subnet', 'subnets.network_address'])
            ->selectRaw(DeviceAssignment::labelSql().' as assignment_label')
            ->where(function ($query) use ($pattern, $observations) {
                $query->whereRaw("INET_NTOA(ip_addresses.address) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("ip_addresses.hostname LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("ip_addresses.mac_address LIKE ? ESCAPE '!'", [str_replace('-', ':', $pattern)])
                    ->orWhereRaw(DeviceAssignment::labelSql()." LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereExists((clone $observations)->selectRaw('1')
                        ->whereColumn('unifi_observations.address', 'ip_addresses.address')->whereColumn('subnets.id', 'ip_addresses.subnet_id')->toBase())
                    // Automatically documented addresses are found by their MikroTik evidence, as in the subnet table.
                    ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('mikrotik_sources')
                        ->whereColumn('mikrotik_sources.site_id', 'subnets.site_id')->whereColumn('mikrotik_sources.address', 'ip_addresses.address')
                        ->whereRaw("CAST(mikrotik_sources.data AS CHAR) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('snipeItAsset', fn ($query) => $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.name')) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.network.mac')) LIKE ? ESCAPE '!'", [$pattern]));
            });
        $external = (clone $observations)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('ip_addresses')
            ->whereColumn('ip_addresses.subnet_id', 'subnets.id')->whereColumn('ip_addresses.address', 'unifi_observations.address'))
            ->selectRaw('CAST(NULL AS UNSIGNED) AS id, unifi_observations.address, NULL AS hostname, NULL AS mac_address, sites.id AS site_id, sites.name AS site, subnets.id AS subnet_id, subnets.cidr AS subnet, subnets.network_address, NULL AS assignment_label')->distinct();

        return DB::query()->fromSub($documented->toBase()->unionAll($external->toBase()), 'results')
            ->when($search === '', fn ($query) => $query->whereRaw('1 = 0'));
    }
}
