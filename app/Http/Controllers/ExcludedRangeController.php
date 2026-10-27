<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExcludedRangeRequest;
use App\Models\Company;
use App\Models\ExcludedRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Support\Ipv4Ranges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ExcludedRangeController extends Controller
{
    public function store(ExcludedRangeRequest $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $subnet, false);
    }

    public function destroy(ExcludedRangeRequest $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $subnet, true);
    }

    public function update(ExcludedRangeRequest $request, Company $company, Site $site, Subnet $subnet, ExcludedRange $excludedRange): RedirectResponse
    {
        return $this->save($request, $subnet, false, $excludedRange);
    }

    private function save(ExcludedRangeRequest $request, Subnet $subnet, bool $removing, ?ExcludedRange $excludedRange = null): RedirectResponse
    {
        $data = $request->validated();
        $start = ip2long($data['start_ip']);
        $end = ip2long($data['end_ip']);
        if ($start > $end) {
            throw ValidationException::withMessages(['end_ip' => 'L’IP finale deve essere uguale o successivo all’IP iniziale.']);
        }

        $message = DB::transaction(function () use ($subnet, $start, $end, $removing, $excludedRange) {
            // Serialize interval changes with each other and with CIDR edits.
            $subnet = Subnet::query()->lockForUpdate()->findOrFail($subnet->id);
            $record = $excludedRange ? $subnet->excludedRanges()->findOrFail($excludedRange->id) : null;
            if ($start < $subnet->network_address || $end > $subnet->broadcast_address) {
                throw ValidationException::withMessages(['start_ip' => "L’intero intervallo deve appartenere alla subnet {$subnet->cidr}."]);
            }

            $overlapping = $subnet->excludedRanges()->where('start_address', '<=', $end)
                ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                ->where('end_address', '>=', $start)->orderBy('start_address')->get();
            $ranges = $overlapping->map(fn ($range) => [$range->start_address, $range->end_address])->all();
            $replacement = $removing ? Ipv4Ranges::subtract($ranges, $start, $end)
                : Ipv4Ranges::merge([...$ranges, [$start, $end]]);

            $subnet->excludedRanges()->whereKey($overlapping->modelKeys())->delete();
            foreach ($replacement as [$left, $right]) {
                ($record ?? $subnet->excludedRanges()->make())->fill(['start_address' => $left, 'end_address' => $right])->save();
            }

            if ($removing) {
                return 'Esclusione rimossa per '.long2ip($start).' – '.long2ip($end).'. Gli IP documentati sono stati conservati.';
            }

            return ($overlapping->isEmpty() ? ($record ? 'Intervallo aggiornato: ' : 'Intervallo escluso: ') : 'Intervalli sovrapposti uniti: ')
                .long2ip($replacement[0][0]).' – '.long2ip($replacement[0][1]).'.';
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
