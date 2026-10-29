<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\IpAddress;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IpSearchController extends Controller
{
    public function index(Request $request, Company $company, Site $site): Response
    {
        $columns = [
            'ip' => 'ip_addresses.address',
            'hostname' => 'ip_addresses.hostname',
            'mac_address' => 'ip_addresses.mac_address',
            'site' => 'sites.name',
            'subnet' => 'subnets.network_address',
        ];
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
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        $results = IpAddress::query()
            ->join('subnets', 'subnets.id', '=', 'ip_addresses.subnet_id')
            ->join('sites', 'sites.id', '=', 'subnets.site_id')
            ->where('sites.company_id', $company->id)
            ->select([
                'ip_addresses.id', 'ip_addresses.address', 'ip_addresses.hostname', 'ip_addresses.mac_address',
                'sites.id as site_id', 'sites.name as site',
                'subnets.id as subnet_id', 'subnets.cidr as subnet',
            ])
            ->when($search === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->where(function ($query) use ($pattern) {
                $query->whereRaw("INET_NTOA(ip_addresses.address) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("ip_addresses.hostname LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("ip_addresses.mac_address LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->when($group === 'site', fn ($query) => $query
                ->orderBy('sites.name', $sort === 'site' ? $direction : 'asc')->orderBy('sites.id'))
            ->orderBy($columns[$sort ?? 'ip'], $direction)->orderBy('ip_addresses.id')
            ->paginate(20)->withQueryString()
            ->through(fn (IpAddress $record) => [
                ...$record->makeHidden('address')->toArray(),
                'ip' => long2ip($record->address),
            ]);

        return Inertia::render('search/index', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'results' => $results,
            'query' => compact('search', 'sort', 'direction', 'group'),
        ]);
    }
}
