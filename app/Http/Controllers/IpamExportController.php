<?php

namespace App\Http\Controllers;

use App\Exports\IpamCsv;
use App\Exports\IpamWorkbook;
use App\Models\Company;
use App\Models\Site;
use App\Models\Subnet;
use App\Support\IpamExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IpamExportController extends Controller
{
    /** The current site or, on request, every site of the company: never data of other companies. */
    public function site(Request $request, Company $company, Site $site): BinaryFileResponse|RedirectResponse
    {
        $options = $request->validate([
            'format' => ['required', 'in:xlsx,csv'],
            'scope' => ['sometimes', 'in:site,company'],
            'excluded' => ['sometimes', 'boolean'],
        ]);
        $sites = ($options['scope'] ?? 'site') === 'company' ? $company->sites()->orderBy('id')->get() : collect([$site]);

        return $this->download(IpamExport::sites($company, $sites, (bool) ($options['excluded'] ?? false)), $options['format']);
    }

    public function subnet(Request $request, Company $company, Site $site, Subnet $subnet): BinaryFileResponse|RedirectResponse
    {
        $format = $request->validate(['format' => ['required', 'in:xlsx,csv']])['format'];

        return $this->download(IpamExport::subnet($company, $site, $subnet), $format);
    }

    private function download(IpamExport $export, string $format): BinaryFileResponse|RedirectResponse
    {
        try {
            // One read snapshot keeps counts and rows of every sheet consistent with each other.
            return DB::transaction(fn () => Excel::download(
                $format === 'csv' ? new IpamCsv($export) : new IpamWorkbook($export),
                $export->filename($format),
                $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX,
                $format === 'csv' ? ['Content-Type' => 'text/csv; charset=UTF-8'] : [],
            ));
        } catch (\OverflowException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }
    }
}
