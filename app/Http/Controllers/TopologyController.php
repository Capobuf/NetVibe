<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Site;
use App\Support\Topology;
use Inertia\Inertia;
use Inertia\Response;

class TopologyController extends Controller
{
    public function index(Company $company, Site $site, Topology $topology): Response
    {
        return Inertia::render('topology/index', [
            'company' => $company->only('id', 'name'), 'site' => $site->only('id', 'name'),
            'topology' => $topology->forSite($site),
            'racks' => $site->racks()->with('items')->orderBy('name')->get(['id', 'name', 'height_u', 'numbering']),
        ]);
    }
}
