<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleDriveException;
use App\Models\Company;
use App\Models\GoogleDriveAccount;
use App\Models\GoogleDriveSync;
use App\Support\GoogleDrive;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GoogleDriveController extends Controller
{
    public function __construct(private GoogleDrive $drive) {}

    /** Every company sync in administration; only the company's own sync in its settings. */
    public function index(?Company $company = null): Response
    {
        $syncs = GoogleDriveSync::query()->when($company, fn ($query) => $query->whereBelongsTo($company))
            ->with('company:id,name', 'account:id,email')->get()->sortBy('company.name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return Inertia::render('integrations/google-drive', [
            'company' => $company?->only('id', 'name', 'observation_retention_days'),
            'configured' => GoogleDrive::configured(),
            'picker' => GoogleDrive::configured() ? ['api_key' => config('services.google.api_key'), 'app_id' => config('services.google.app_id')] : null,
            'accounts' => GoogleDriveAccount::withCount('syncs')->orderBy('email')->get(['id', 'email']),
            'syncs' => $syncs->map(fn (GoogleDriveSync $sync) => [
                ...$sync->only('id', 'folder_id', 'folder_name', 'interval', 'file_url', 'synced_at', 'error'),
                'company' => $sync->company->only('id', 'name'),
                'account' => $sync->account?->only('id', 'email'),
            ]),
            'companies' => $company ? [] : Company::whereDoesntHave('googleDriveSync')->orderBy('name')->get(['id', 'name']),
            'intervals' => GoogleDriveSync::INTERVALS,
        ]);
    }

    /** Starts the Google consent screen; the callback returns to the page that asked for it. */
    public function connect(Request $request): SymfonyResponse
    {
        if (! GoogleDrive::configured()) {
            return $this->toast('error', 'Configurazione Google mancante sul server.');
        }
        $state = Str::random(40);
        $request->session()->put('google-drive', ['state' => $state, 'return' => url()->previous()]);

        return Inertia::location($this->drive->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('google-drive');
        $return = $pending['return'] ?? route('administration.integrations.google-drive.index');
        try {
            if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state'))) {
                throw new GoogleDriveException('Collegamento Google scaduto: riprova.');
            }
            if ($request->query('error') || ! $request->query('code')) {
                throw new GoogleDriveException('Collegamento Google annullato.');
            }
            $account = $this->drive->connect((string) $request->query('code'));
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Account '.$account->email.' collegato.']);
        } catch (GoogleDriveException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        } catch (ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Google non raggiungibile: riprova più tardi.']);
        }

        return redirect($return);
    }

    /** Access token for the Google Picker running in the administrator's browser. */
    public function token(GoogleDriveAccount $googleDriveAccount): JsonResponse
    {
        try {
            return response()->json(['access_token' => $this->drive->accessToken($googleDriveAccount)]);
        } catch (GoogleDriveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ConnectionException) {
            return response()->json(['message' => 'Google non raggiungibile: riprova più tardi.'], 503);
        }
    }

    public function disconnect(GoogleDriveAccount $googleDriveAccount): RedirectResponse
    {
        $this->drive->revoke($googleDriveAccount);
        DB::transaction(function () use ($googleDriveAccount) {
            $googleDriveAccount->syncs()->update(['error' => 'Nessun account Google collegato: scegline uno.']);
            $googleDriveAccount->delete();
        });

        return $this->toast('success', 'Account '.$googleDriveAccount->email.' scollegato.');
    }

    /** One account and frequency for every selected company, each with its own folder; the first copy is written right away. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('google_drive_accounts', 'id')],
            'interval' => ['required', 'integer', Rule::in(GoogleDriveSync::INTERVALS)],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.company_id' => ['required', 'integer', 'distinct', Rule::exists('companies', 'id'), Rule::unique('google_drive_syncs', 'company_id')],
            ...$this->folderRules('targets.*.'),
        ], [
            'targets.required' => 'Seleziona almeno un’azienda.',
            'targets.*.company_id.unique' => 'Questa azienda è già sincronizzata.',
            'targets.*.folder_id.required' => 'Scegli una cartella per ogni azienda selezionata.',
        ]);
        $syncs = DB::transaction(fn () => collect($data['targets'])->map(fn ($target) => GoogleDriveSync::create([
            ...$target, 'account_id' => $data['account_id'], 'interval' => $data['interval'],
        ])));
        $failed = $syncs->filter(fn (GoogleDriveSync $sync) => $this->drive->sync($sync) === null)->count();

        return $failed
            ? $this->toast('error', $failed === 1 ? 'Sincronizzazione salvata, ma la prima scrittura non è riuscita.' : "Sincronizzazioni salvate, ma {$failed} prime scritture non sono riuscite.")
            : $this->toast('success', $syncs->count() === 1 ? 'Sincronizzazione attivata: il foglio è su Drive.' : 'Sincronizzazioni attivate: i fogli sono su Drive.');
    }

    /** A new account or folder starts a new sheet there; the previous one stays on Drive untouched. */
    public function update(Request $request, GoogleDriveSync $googleDriveSync): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('google_drive_accounts', 'id')],
            'interval' => ['required', 'integer', Rule::in(GoogleDriveSync::INTERVALS)],
            ...$this->folderRules(),
        ], ['folder_id.required' => 'Scegli una cartella.']);
        $googleDriveSync->fill($data);
        if ($googleDriveSync->isDirty('account_id', 'folder_id')) {
            $googleDriveSync->fill(['file_id' => null, 'file_url' => null, 'synced_at' => null]);
        }
        $googleDriveSync->save();

        return $this->toast('success', 'Sincronizzazione aggiornata.');
    }

    public function destroy(GoogleDriveSync $googleDriveSync): RedirectResponse
    {
        $googleDriveSync->delete();

        return $this->toast('success', 'Sincronizzazione rimossa. Il foglio resta su Drive.');
    }

    public function run(GoogleDriveSync $googleDriveSync): RedirectResponse
    {
        return match ($this->drive->sync($googleDriveSync)) {
            null => $this->toast('error', $googleDriveSync->error),
            'recreated' => $this->toast('success', 'Il foglio non era più su Drive ed è stato ricreato.'),
            default => $this->toast('success', 'Foglio aggiornato su Drive.'),
        };
    }

    private function folderRules(string $prefix = ''): array
    {
        return [
            $prefix.'folder_id' => ['required', 'string', 'max:255'],
            $prefix.'folder_name' => ['required', 'string', 'max:255'],
        ];
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
