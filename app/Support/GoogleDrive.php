<?php

namespace App\Support;

use App\Exceptions\GoogleDriveException;
use App\Exports\IpamWorkbook;
use App\Models\GoogleDriveAccount;
use App\Models\GoogleDriveSetting;
use App\Models\GoogleDriveSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One-way NetVibe → Drive copy of a company IPAM. The workbook of the manual export is uploaded
 * and converted to a native Google Sheet; later runs overwrite the same file, so Drive keeps the revisions.
 */
class GoogleDrive
{
    /** drive.file only reaches folders picked by the user and files NetVibe created: no Google verification needed. */
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/drive.file'];

    private const FILES = 'https://www.googleapis.com/drive/v3/files';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const SHEET = 'application/vnd.google-apps.spreadsheet';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function configured(): bool
    {
        return GoogleDriveSetting::current() !== null;
    }

    public static function redirectUri(): string
    {
        return route('administration.integrations.google-drive.callback');
    }

    /**
     * A fake authorization code tells a wrong client (invalid_client) apart from a valid one (invalid_grant)
     * without asking anyone to sign in.
     */
    public function verifyClient(string $clientId, string $clientSecret): bool
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => 'netvibe-check', 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirectUri(),
            'client_id' => $clientId, 'client_secret' => $clientSecret,
        ]);

        return ! in_array($response->json('error'), ['invalid_client', 'unauthorized_client'], true);
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => GoogleDriveSetting::current()?->client_id,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            // Offline access with forced consent always returns a refresh token, also when reconnecting.
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Exchanges the authorization code and stores (or refreshes) the account it belongs to. */
    public function connect(string $code): GoogleDriveAccount
    {
        $token = $this->tokenRequest(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirectUri()]);
        if (! in_array('https://www.googleapis.com/auth/drive.file', explode(' ', $token['scope'] ?? ''), true)) {
            throw new GoogleDriveException('Accesso a Google Drive non concesso: collega di nuovo l’account e consenti l’accesso ai file.');
        }
        if (blank($token['refresh_token'] ?? null)) {
            throw new GoogleDriveException('Google non ha restituito un accesso permanente: collega di nuovo l’account.');
        }
        $email = $this->request($token['access_token'])->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');
        if (! is_string($email) || $email === '') {
            throw new GoogleDriveException('Impossibile leggere l’indirizzo dell’account Google.');
        }
        $account = GoogleDriveAccount::updateOrCreate(['email' => Str::lower($email)], ['refresh_token' => $token['refresh_token']]);
        Cache::put($this->cacheKey($account), $token['access_token'], now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600) - 60)));

        return $account;
    }

    /** Short-lived access token, also handed to the browser for the folder picker. */
    public function accessToken(GoogleDriveAccount $account): string
    {
        if ($cached = Cache::get($this->cacheKey($account))) {
            return $cached;
        }
        $token = $this->tokenRequest(['refresh_token' => $account->refresh_token, 'grant_type' => 'refresh_token']);
        Cache::put($this->cacheKey($account), $token['access_token'], now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600) - 60)));

        return $token['access_token'];
    }

    /** Best effort: the account is removed from NetVibe even if Google cannot be reached. */
    public function revoke(GoogleDriveAccount $account): void
    {
        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $account->refresh_token]);
        } catch (ConnectionException) {
        }
        Cache::forget($this->cacheKey($account));
    }

    /**
     * Writes the company IPAM and records the outcome. Returns 'updated', 'created' or 'recreated'
     * (the previous sheet was deleted on Drive), or null when the sync failed.
     */
    public function sync(GoogleDriveSync $sync): ?string
    {
        $result = null;
        try {
            if (! $sync->account) {
                throw new GoogleDriveException('Nessun account Google collegato: scegline uno.');
            }
            $token = $this->accessToken($sync->account);
            $company = $sync->company;
            $export = IpamExport::sites($company, $company->sites()->orderBy('id')->get(), false);
            // One read snapshot keeps counts and rows of every sheet consistent, as in the manual export.
            $content = DB::transaction(fn () => Excel::raw(new IpamWorkbook($export), ExcelWriter::XLSX));
            [$file, $result] = $this->write($token, $sync, 'NetVibe - IPAM - '.$company->name, $content);
            $sync->fill(['file_id' => $file['id'], 'file_url' => $file['webViewLink'] ?? null, 'error' => null]);
        } catch (GoogleDriveException|\OverflowException $exception) {
            $sync->error = $exception->getMessage();
        } catch (ConnectionException) {
            $sync->error = 'Google Drive non raggiungibile: riprova più tardi.';
        } finally {
            $sync->synced_at = now();
            $sync->save();
        }

        return $sync->error === null ? $result : null;
    }

    /** @return array{0: array{id: string, webViewLink?: string}, 1: string} */
    private function write(string $token, GoogleDriveSync $sync, string $name, string $content): array
    {
        if ($sync->file_id) {
            $current = $this->request($token)->get(self::FILES.'/'.$sync->file_id, ['fields' => 'id,trashed']);
            if ($current->successful() && ! $current->json('trashed')) {
                $updated = $this->request($token)->withBody($content, self::XLSX)
                    ->patch(self::UPLOAD.'/'.$sync->file_id.'?uploadType=media&fields=id,webViewLink');

                return [$this->json($updated), 'updated'];
            }
            if ($current->status() !== 404 && ! $current->successful()) {
                $this->fail($current);
            }
        }
        // Drive accepts metadata and content together only as multipart/related.
        $boundary = 'netvibe'.Str::random(24);
        $metadata = json_encode(['name' => $name, 'mimeType' => self::SHEET, 'parents' => [$sync->folder_id]], JSON_UNESCAPED_UNICODE);
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$metadata}\r\n"
            ."--{$boundary}\r\nContent-Type: ".self::XLSX."\r\n\r\n{$content}\r\n--{$boundary}--";
        $created = $this->request($token)->withBody($body, 'multipart/related; boundary='.$boundary)
            ->post(self::UPLOAD.'?uploadType=multipart&fields=id,webViewLink');

        return [$this->json($created), $sync->file_id ? 'recreated' : 'created'];
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(60);
    }

    private function tokenRequest(array $data): array
    {
        $setting = GoogleDriveSetting::current() ?? throw new GoogleDriveException('Progetto Google non configurato.');
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            ...$data,
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
        ]);
        if ($response->json('error') === 'invalid_client') {
            throw new GoogleDriveException('Client OAuth non valido: verifica il progetto Google.');
        }
        if ($response->json('error') === 'invalid_grant') {
            throw new GoogleDriveException('Accesso Google revocato o scaduto: collega di nuovo l’account.');
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new GoogleDriveException('Google ha rifiutato l’accesso (HTTP '.$response->status().'): verifica la configurazione OAuth.');
        }

        return $response->json();
    }

    private function json(Response $response): array
    {
        if (! $response->successful() || ! is_string($response->json('id'))) {
            $this->fail($response);
        }

        return $response->json();
    }

    private function fail(Response $response): never
    {
        $reason = $response->json('error.errors.0.reason');
        throw new GoogleDriveException(match (true) {
            $response->status() === 401 => 'Accesso Google revocato o scaduto: collega di nuovo l’account.',
            $reason === 'storageQuotaExceeded' => 'Spazio Google Drive esaurito.',
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true), $response->status() === 429 => 'Google Drive ha limitato le richieste: riprova più tardi.',
            $response->status() === 403, $response->status() === 404 => 'Cartella non trovata o non più accessibile: sceglila di nuovo.',
            default => 'Google Drive ha risposto con un errore (HTTP '.$response->status().').',
        });
    }

    private function cacheKey(GoogleDriveAccount $account): string
    {
        return 'google-drive-token:'.$account->id;
    }
}
