<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\UnifiObservation;
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
        ]);
        $search = $options['search'] ?? '';
        $sort = $options['sort'] ?? null;
        $group = $options['group'] ?? 'none';
        $direction = $sort === null ? 'asc' : ($options['direction'] ?? 'asc');
        $results = $this->matches($company, $search)
            ->when($group === 'site', fn ($query) => $query->orderBy('site', $sort === 'site' ? $direction : 'asc')->orderBy('site_id'))
            ->orderBy($columns[$sort ?? 'ip'], $direction)->orderBy('subnet_id')->orderBy('address')->paginate(20)->withQueryString();
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
        ]);
    }

    public function suggestions(Request $request, Company $company, Site $site): JsonResponse
    {
        $search = $request->validate(['search' => ['required', 'string', 'max:255']])['search'];
        $matches = $this->matches($company, $search);

        return response()->json([
            'total' => (clone $matches)->count(),
            'results' => $matches->orderBy('address')->orderBy('subnet_id')->limit(6)->get()->map(fn ($row) => [
                'id' => $row->id, 'ip' => long2ip($row->address), 'hostname' => $row->hostname,
                'mac_address' => $row->mac_address, 'assignment_label' => $row->assignment_label,
                'site_id' => $row->site_id, 'site' => $row->site, 'subnet_id' => $row->subnet_id, 'subnet' => $row->subnet,
            ]),
        ]);
    }

    /** Documented addresses and external UniFi observations of the company that match the search text. */
    private function matches(Company $company, string $search): Builder
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
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
                    ->orWhereRaw("ip_addresses.mac_address LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw(DeviceAssignment::labelSql()." LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereExists((clone $observations)->selectRaw('1')
                        ->whereColumn('unifi_observations.address', 'ip_addresses.address')->whereColumn('subnets.id', 'ip_addresses.subnet_id')->toBase())
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
