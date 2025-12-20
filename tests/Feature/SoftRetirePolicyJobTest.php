<?php

use App\Enums\LifecycleStatus;
use App\Jobs\ApplySoftRetirePolicyJob;
use App\Models\IpAddress;
use App\Settings\DiscoverySettings;
use Illuminate\Support\Carbon;

it('marks ip addresses stale and retired based on last_seen', function () {
    Carbon::setTestNow(Carbon::parse('2025-12-19 10:00:00'));

    $settings = app(DiscoverySettings::class);
    $settings->inventory_soft_retire_enabled = true;
    $settings->inventory_stale_after_days = 15;
    $settings->inventory_retire_after_days = 45;
    $settings->save();

    $activeIp = IpAddress::factory()->create([
        'last_seen' => Carbon::now()->subDays(2),
        'lifecycle_status' => LifecycleStatus::Active,
    ]);

    $staleIp = IpAddress::factory()->create([
        'last_seen' => Carbon::now()->subDays(20),
        'lifecycle_status' => LifecycleStatus::Active,
    ]);

    $retiredIp = IpAddress::factory()->create([
        'last_seen' => Carbon::now()->subDays(60),
        'lifecycle_status' => LifecycleStatus::Active,
    ]);

    (new ApplySoftRetirePolicyJob())->handle(app(DiscoverySettings::class));

    $this->assertDatabaseHas('ip_addresses', [
        'id' => $activeIp->id,
        'lifecycle_status' => LifecycleStatus::Active->value,
    ]);

    $this->assertDatabaseHas('ip_addresses', [
        'id' => $staleIp->id,
        'lifecycle_status' => LifecycleStatus::Stale->value,
    ]);

    $this->assertDatabaseHas('ip_addresses', [
        'id' => $retiredIp->id,
        'lifecycle_status' => LifecycleStatus::Retired->value,
    ]);

    Carbon::setTestNow();
});
