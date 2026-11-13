<?php

namespace App\Support;

use App\Models\Company;
use App\Models\MikrotikSource;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\UnifiObservation;
use App\Models\Vlan;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Selects and maps the persisted IPAM of an authorized scope once, for every export format and destination.
 * Only documented records and currently occupying observations become rows: free addresses stay counts.
 */
class IpamExport
{
    public const COLUMNS = ['IP', 'Tipo', 'Hostname', 'MAC Address', 'Dispositivo / Interfaccia', 'Descrizione', 'Stato', 'Tag', 'Fonti'];

    /** @var list<array{site: Site, vlan: ?Vlan, subnets: Collection<int, Subnet>}>|null */
    private ?array $groups = null;

    /** @var array<int, array{documented: int, excluded: int, external: int, proposable: int}> */
    private array $counts = [];

    /** @param Collection<int, Site> $sites */
    private function __construct(
        public readonly Company $company,
        public readonly Collection $sites,
        public readonly bool $includeExcluded,
        public readonly ?Subnet $subnet,
        private readonly bool $companyMultiSite,
        public readonly CarbonInterface $generatedAt,
    ) {}

    /** @param Collection<int, Site> $sites */
    public static function sites(Company $company, Collection $sites, bool $includeExcluded): self
    {
        return new self($company, $sites->values(), $includeExcluded, null, $company->sites()->count() > 1, now());
    }

    public static function subnet(Company $company, Site $site, Subnet $subnet): self
    {
        return new self($company, collect([$site]), true, $subnet, $company->sites()->count() > 1, now());
    }

    /** Sheets need the site in their name only when the same VLAN tag can appear twice. */
    public function multiSite(): bool
    {
        return $this->sites->count() > 1;
    }

    public function context(): string
    {
        $site = match (true) {
            $this->multiSite() => 'Tutte le sedi',
            $this->companyMultiSite => $this->sites->first()->name,
            default => null,
        };

        return collect([$this->company->name, $site])->filter()->implode(' · ');
    }

    public function siteName(Site $site): string
    {
        return $site->name ?? $this->company->name;
    }

    public function filename(string $extension): string
    {
        $parts = ['ipam', $this->company->name];
        if ($this->multiSite()) {
            $parts[] = 'tutte le sedi';
        } elseif ($this->companyMultiSite) {
            $parts[] = $this->sites->first()->name;
        }
        if ($this->subnet) {
            $vlan = $this->subnet->vlan;
            $parts[] = $vlan ? "vlan {$vlan->tag}" : 'senza vlan';
            $parts[] = str_replace('/', '-', $this->subnet->cidr);
        }
        $parts[] = $this->generatedAt->format('Y-m-d');

        return collect($parts)->map(fn ($part) => Str::slug((string) $part))->filter()->implode('_').'.'.$extension;
    }

    /**
     * VLANs of every site, empty ones included, followed by the site's «Senza VLAN» subnets.
     *
     * @return list<array{site: Site, vlan: ?Vlan, subnets: Collection<int, Subnet>}>
     */
    public function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }
        if ($this->subnet) {
            return $this->groups = [['site' => $this->sites->first(), 'vlan' => $this->subnet->vlan, 'subnets' => collect([$this->subnet])]];
        }
        $this->groups = [];
        foreach ($this->sites as $site) {
            $subnets = $site->subnets()->when(! $this->includeExcluded, fn ($query) => $query->visible())
                ->orderBy('network_address')->get()->groupBy(fn (Subnet $subnet) => $subnet->vlan_id ?? 0);
            $vlans = $site->vlans()->when(! $this->includeExcluded, fn ($query) => $query->visible())->orderBy('tag')->get();
            foreach ($vlans as $vlan) {
                $this->groups[] = ['site' => $site, 'vlan' => $vlan, 'subnets' => $subnets->get($vlan->id, collect())];
            }
            if ($subnets->has(0)) {
                $this->groups[] = ['site' => $site, 'vlan' => null, 'subnets' => $subnets->get(0)];
            }
        }

        return $this->groups;
    }

    public function isExcluded(?Vlan $vlan, ?Subnet $subnet = null): bool
    {
        return $vlan?->excluded_at !== null || $subnet?->excluded_at !== null;
    }

    /** @return array{usable: int, documented: int, excluded: int, external: int, proposable: int} */
    public function counts(Subnet $subnet): array
    {
        $counts = $this->counts[$subnet->id] ??= $subnet->addressCounts();

        return ['usable' => array_sum($counts), ...$counts];
    }

    /**
     * Documented records keep only documented values; addresses seen solely by integrations are marked
     * «Rilevato» and show what the sources report, never a status or an assignment.
     *
     * @return list<list<string>>
     */
    public function rows(Subnet $subnet): array
    {
        [$first, $last] = Ipv4Ranges::usableBounds($subnet->network_address, $subnet->broadcast_address);
        $evidence = MikrotikSource::blocking($subnet->site_id)->whereBetween('address', [$first, $last])->get(['address', 'data'])
            ->map(fn (MikrotikSource $source) => [
                'address' => $source->address, 'source' => 'MikroTik', 'mac' => $source->data['mac'] ?? null,
                'hostname' => $source->data['hostname_observed'] ?? $source->data['identity'] ?? null,
            ])
            ->concat(UnifiObservation::blocking($subnet->site_id)->whereBetween('address', [$first, $last])->get(['address', 'data'])
                ->map(fn (UnifiObservation $observation) => [
                    'address' => $observation->address, 'source' => 'UniFi', 'mac' => $observation->data['mac'] ?? null,
                    'hostname' => $observation->data['hostname_observed'] ?? null,
                ]))
            ->groupBy('address');
        $records = $subnet->ipAddresses()->select('ip_addresses.*')->selectRaw(DeviceAssignment::labelSql().' as assignment_label')
            ->with(['status:id,name', 'tags:id,name', 'snipeItAsset:id,ip_address_id'])->orderBy('address')->get();

        $rows = [];
        foreach ($records as $record) {
            $sources = collect($record->snipeItAsset ? ['Snipe-IT'] : [])
                ->concat($evidence->get($record->address, collect())->pluck('source'));
            $rows[$record->address] = [
                long2ip($record->address), 'Documentato', $record->hostname, $record->mac_address, $record->assignment_label,
                $record->description, $record->status?->name, $record->tags->pluck('name')->implode(', '), $sources->unique()->implode(', '),
            ];
        }
        foreach ($evidence as $address => $items) {
            if (isset($rows[$address])) {
                continue;
            }
            $values = fn (string $field) => $items->pluck($field)->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn ($value) => $field === 'mac' ? strtoupper(trim($value)) : trim($value))
                // UniFi names an unnamed client after its MAC: that is not a hostname.
                ->reject(fn ($value) => $field === 'hostname' && MikrotikSnapshot::mac(strtoupper($value)) !== null)
                ->unique()->implode(', ');
            $rows[$address] = [long2ip($address), 'Rilevato', $values('hostname'), $values('mac'), null, null, null, null, $items->pluck('source')->unique()->implode(', ')];
        }
        ksort($rows, SORT_NUMERIC);

        return array_map(fn ($row) => array_map(fn ($value) => (string) $value, $row), array_values($rows));
    }

    /**
     * One flat table: every IP keeps its company, site, VLAN and subnet; empty subnets and VLANs keep a row of their own.
     *
     * @return list<list<string>>
     */
    public function csvRows(): array
    {
        if ($this->subnet) {
            return [self::COLUMNS, ...$this->rows($this->subnet)];
        }
        $context = ['Azienda', 'Sede', 'VLAN', 'Nome VLAN', 'Subnet', 'CIDR', ...($this->includeExcluded ? ['Esclusa'] : [])];
        $lines = [[...$context, ...self::COLUMNS]];
        $blank = array_fill(0, count(self::COLUMNS), '');
        foreach ($this->groups() as ['site' => $site, 'vlan' => $vlan, 'subnets' => $subnets]) {
            foreach ($subnets->isEmpty() ? [null] : $subnets as $subnet) {
                $prefix = [
                    $this->company->name, (string) $site->name, $vlan ? (string) $vlan->tag : 'Senza VLAN', (string) $vlan?->name,
                    (string) $subnet?->name, (string) $subnet?->cidr,
                    ...($this->includeExcluded ? [$this->isExcluded($vlan, $subnet) ? 'Sì' : ''] : []),
                ];
                foreach (($subnet ? $this->rows($subnet) : []) ?: [$blank] as $row) {
                    $lines[] = [...$prefix, ...$row];
                }
            }
        }

        return $lines;
    }
}
