<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmDeletionRequest;
use App\Http\Requests\SubnetRequest;
use App\Models\Company;
use App\Models\ExcludedRange;
use App\Models\IpStatus;
use App\Models\Site;
use App\Models\Subnet;
use App\Support\DeletionImpact;
use App\Support\Ipv4Cidr;
use App\Support\SubnetAddressTable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SubnetController extends Controller
{
    public function index(Company $company, Site $site): Response
    {
        return Inertia::render('subnets/index', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'vlans' => $site->vlans()->orderBy('tag')->get(['id', 'tag', 'name']),
            'subnets' => $site->subnets()->orderBy('network_address')
                ->get(['id', 'cidr', 'name', 'description', 'vlan_id']),
        ]);
    }

    public function store(SubnetRequest $request, Company $company, Site $site): RedirectResponse
    {
        return $this->save($request, $site);
    }

    public function show(Request $request, Company $company, Site $site, Subnet $subnet): Response
    {
        $options = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:ip,hostname,mac_address,description,status,tags'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:85899346'],
            'ip_address' => ['sometimes', 'integer', 'min:1'],
        ]);
        $search = $options['search'] ?? '';
        $sort = $options['sort'] ?? null;
        $direction = $sort === null ? 'asc' : ($options['direction'] ?? 'asc');

        $subnet->load(['excludedRanges' => fn ($query) => $query->orderBy('start_address')]);
        $selected = isset($options['ip_address'])
            ? $subnet->ipAddresses()->with(['status:id,name', 'tags:id,name,color'])->find($options['ip_address'])
            : null;

        return Inertia::render('subnets/show', [
            'company' => $company->only('id', 'name'),
            'site' => $site->only('id', 'name'),
            'subnet' => $subnet->only('id', 'cidr', 'name', 'description', 'vlan_id'),
            'vlan' => $subnet->vlan?->only('id', 'tag', 'name'),
            'summaryEntries' => $subnet->summaryEntries()->orderBy('id')->get(['id', 'name', 'values']),
            'excludedRanges' => $subnet->excludedRanges
                ->map(fn (ExcludedRange $range) => [
                    'id' => $range->id,
                    'start_ip' => long2ip($range->start_address),
                    'end_ip' => long2ip($range->end_address),
                ]),
            'firstProposableIp' => $subnet->firstProposableIp(),
            'query' => compact('search', 'sort', 'direction'),
            'addressCounts' => $subnet->addressCounts(),
            'statuses' => IpStatus::query()->orderBy('name')->get(['id', 'name']),
            'tags' => $company->tags()->orderBy('name')->get(['id', 'name', 'color']),
            'ipAddresses' => SubnetAddressTable::paginate($subnet, $search, $sort, $direction, (int) ($options['page'] ?? 1)),
            'initialIpAddress' => $selected ? [
                ...$selected->only('id', 'hostname', 'mac_address', 'description'),
                'ip' => long2ip($selected->address),
                'status' => $selected->status?->only('id', 'name'),
                'tags' => $selected->tags->map->only('id', 'name', 'color'),
                'excluded' => $subnet->isExcluded($selected->address),
            ] : null,
        ]);
    }

    public function update(SubnetRequest $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $site, $subnet);
    }

    public function deletionImpact(Company $company, Site $site, Subnet $subnet): JsonResponse
    {
        return response()->json(DB::transaction(fn () => DeletionImpact::preview(DeletionImpact::networks($company, $site, $subnet)), 3));
    }

    public function destroy(ConfirmDeletionRequest $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        DB::transaction(function () use ($request, $company, $site, $subnet) {
            $impact = DeletionImpact::networks($company, $site, $subnet);
            $request->ensureUnchanged($impact);
            DeletionImpact::deleteSubnets([$subnet->id]);
        }, 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Subnet e documentazione associate eliminate.']);

        return to_route('companies.sites.subnets.index', [$company, $site]);
    }

    private function save(SubnetRequest $request, Site $site, ?Subnet $subnet = null): RedirectResponse
    {
        $data = $request->validated();
        $range = Ipv4Cidr::parse($data['cidr']);

        try {
            DB::transaction(function () use ($data, $range, $site, $subnet) {
                // Lock the existing parent even when it has no subnets yet.
                $site = Site::query()->lockForUpdate()->findOrFail($site->id);
                $record = $subnet ? $site->subnets()->lockForUpdate()->findOrFail($subnet->id) : $site->subnets()->make();
                if ($subnet && $record->excludedRanges()->where(function ($query) use ($range) {
                    $query->where('start_address', '<', $range['network_address'])
                        ->orWhere('end_address', '>', $range['broadcast_address']);
                })->exists()) {
                    throw ValidationException::withMessages([
                        'cidr' => 'Il nuovo CIDR lascerebbe fuori intervalli esclusi. Rimuovi prima le esclusioni interessate.',
                    ]);
                }
                if ($subnet && $record->ipAddresses()->where(function ($query) use ($range) {
                    $query->where('address', '<', $range['network_address'])
                        ->orWhere('address', '>', $range['broadcast_address']);
                })->exists()) {
                    throw ValidationException::withMessages([
                        'cidr' => 'Il nuovo CIDR lascerebbe fuori IP documentati. Modifica o libera prima gli indirizzi interessati.',
                    ]);
                }
                $overlap = $site->subnets()
                    ->when($subnet, fn ($query) => $query->where('id', '!=', $subnet->id))
                    ->where('network_address', '<=', $range['broadcast_address'])
                    ->where('broadcast_address', '>=', $range['network_address'])
                    ->first();

                if ($overlap) {
                    throw ValidationException::withMessages([
                        'cidr' => "La rete {$range['cidr']} si sovrappone a {$overlap->cidr} in questa sede, anche se la VLAN è diversa.",
                    ]);
                }

                $vlanId = $data['vlan_id'] ?? null;
                if ($vlanId !== null && ! $site->vlans()->whereKey($vlanId)->exists()) {
                    throw ValidationException::withMessages(['vlan_id' => 'Scegli una VLAN appartenente a questa sede.']);
                }

                if (isset($data['new_vlan'])) {
                    $vlanId = $site->vlans()->create($data['new_vlan'])->id;
                }

                $record->fill([
                    ...$range,
                    'name' => $data['name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'vlan_id' => $vlanId,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'new_vlan.tag' => 'Questo ID VLAN è già presente in questa sede. Seleziona la VLAN esistente.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ($subnet ? 'Subnet aggiornata: ' : 'Subnet creata: ').$range['cidr'].'.',
        ]);

        return back();
    }
}
