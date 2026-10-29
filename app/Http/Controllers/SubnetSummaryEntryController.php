<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\SubnetSummaryEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubnetSummaryEntryController extends Controller
{
    public function store(Request $request, Company $company, Site $site, Subnet $subnet): RedirectResponse
    {
        return $this->save($request, $subnet);
    }

    public function update(Request $request, Company $company, Site $site, Subnet $subnet, SubnetSummaryEntry $summaryEntry): RedirectResponse
    {
        return $this->save($request, $subnet, $summaryEntry);
    }

    private function save(Request $request, Subnet $subnet, ?SubnetSummaryEntry $summaryEntry = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'values' => ['required', 'array', 'list', 'min:1'],
            'values.*' => ['required', 'string', 'max:2000'],
        ], [], ['name' => 'nome', 'values' => 'valori', 'values.*' => 'valore']);

        $record = $summaryEntry ?? $subnet->summaryEntries()->make();
        $record->fill($data)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $summaryEntry ? 'Voce aggiornata.' : 'Voce aggiunta.']);

        return back();
    }

    public function destroy(Request $request, Company $company, Site $site, Subnet $subnet, SubnetSummaryEntry $summaryEntry): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']], [], ['confirmed' => 'conferma eliminazione']);
        $summaryEntry->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Voce eliminata.']);

        return back();
    }
}
