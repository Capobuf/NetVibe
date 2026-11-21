<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_status_changes_notify_users_with_access_and_the_preference_enabled(): void
    {
        $company = Company::create(['name' => 'Synthetic company']);
        $device = $company->sites()->create(['name' => 'Synthetic site'])->devices()->create(['name' => 'Synthetic router', 'manufacturer_id' => Manufacturer::create(['name' => 'MikroTik'])->id]);
        $connection = $device->mikrotikConnection()->create(['server_url' => 'https://10.2.0.1', 'username' => 'reader', 'password' => 'secret', 'revision' => (string) Str::uuid()]);
        $superadmin = User::factory()->create(['is_superadmin' => true]);
        $member = User::factory()->create();
        $member->companies()->attach($company);
        $outsider = User::factory()->create();
        $outsider->companies()->attach(Company::create(['name' => 'Other company']));
        $optedOut = User::factory()->create(['notify_mikrotik' => false]);
        $optedOut->companies()->attach($company);

        $connection->update(['sync_status' => 'ok']);
        $connection->update(['sync_status' => 'timeout']);
        $connection->update(['sync_status' => 'unauthorized']);
        $connection->update(['sync_status' => 'ok']);
        $connection->update(['sync_status' => 'suspended']);

        foreach ([$superadmin, $member] as $user) {
            $this->assertSame([false, true], $user->notifications()->get()->pluck('data.failed')->sort()->values()->all());
        }
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(0, $optedOut->notifications()->count());
        $this->assertSame('Synthetic company · Synthetic site · Synthetic router', $member->notifications()->first()->data['subject']);

        $notification = $member->notifications()->first();
        $this->actingAs($outsider)->post(route('notifications.read', $notification->id))->assertNotFound();
        $this->actingAs($member)->post(route('notifications.read', $notification->id))
            ->assertRedirect(route('companies.settings.integrations.mikrotik', $company));
        $this->assertNotNull($notification->fresh()->read_at);
    }
}
