<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GoogleDriveAccount;
use App\Models\GoogleDriveSetting;
use App\Models\GoogleDriveSync;
use App\Models\User;
use App\Support\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_users_cannot_configure_drive_or_its_syncs(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));

        $this->get(route('administration.integrations.google-drive.index'))->assertForbidden();
        $this->put(route('administration.integrations.google-drive.settings'), [
            'client_id' => '1-abc.apps.googleusercontent.com', 'client_secret' => 's', 'api_key' => 'k',
        ])->assertForbidden();
        $this->assertDatabaseCount('google_drive_settings', 0);
    }

    public function test_sync_creates_a_native_sheet_then_overwrites_the_same_file_and_recreates_it_when_trashed(): void
    {
        $sync = $this->sync();
        $sync->company->sites()->create(['name' => 'Torino']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
            'www.googleapis.com/drive/v3/files/sheet-1*' => Http::sequence()->push(['id' => 'sheet-1', 'trashed' => false])->push(['id' => 'sheet-1', 'trashed' => true]),
            'www.googleapis.com/upload/drive/v3/files/sheet-1*' => Http::response(['id' => 'sheet-1', 'webViewLink' => 'https://docs.google.com/spreadsheets/d/sheet-1']),
            'www.googleapis.com/upload/drive/v3/files?*' => Http::sequence()
                ->push(['id' => 'sheet-1', 'webViewLink' => 'https://docs.google.com/spreadsheets/d/sheet-1'])
                ->push(['id' => 'sheet-2', 'webViewLink' => 'https://docs.google.com/spreadsheets/d/sheet-2']),
        ]);
        $drive = app(GoogleDrive::class);

        $this->assertSame('created', $drive->sync($sync));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'uploadType=multipart')
            && str_contains($request->body(), 'google-apps.spreadsheet')
            && str_contains($request->body(), '"parents":["folder"]'));
        $this->assertSame('updated', $drive->sync($sync->refresh()));
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), 'files/sheet-1?uploadType=media'));
        $this->assertSame('recreated', $drive->sync($sync->refresh()));

        $this->assertDatabaseHas('google_drive_syncs', ['id' => $sync->id, 'file_id' => 'sheet-2', 'error' => null]);
    }

    public function test_revoked_access_is_recorded_on_the_sync(): void
    {
        $sync = $this->sync();
        Http::preventStrayRequests();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertNull(app(GoogleDrive::class)->sync($sync));

        $this->assertSame('Accesso Google revocato o scaduto: collega di nuovo l’account.', $sync->refresh()->error);
    }

    private function sync(): GoogleDriveSync
    {
        GoogleDriveSetting::create(['id' => 1, 'client_id' => '1-abc.apps.googleusercontent.com', 'client_secret' => 's', 'api_key' => 'k']);
        $account = GoogleDriveAccount::create(['email' => 'ops@example.com', 'refresh_token' => 'refresh']);

        return GoogleDriveSync::create([
            'company_id' => Company::create(['name' => 'Alfa'])->id, 'account_id' => $account->id,
            'folder_id' => 'folder', 'folder_name' => 'IPAM', 'interval' => 60,
        ]);
    }
}
