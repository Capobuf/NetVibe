<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExcludedRangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_replaces_the_range_and_merges_overlaps_without_losing_documented_ips(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.0.0/24'));
        $range = $subnet->excludedRanges()->create([
            'start_address' => ip2long('10.0.0.100'), 'end_address' => ip2long('10.0.0.150'),
        ]);
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('10.0.0.105'), 'description' => 'Server']);
        $url = route('companies.sites.subnets.excluded-ranges.update', [$company, $site, $subnet, $range]);
        $show = route('companies.sites.subnets.show', [$company, $site, $subnet]);

        $this->from($show)->patch($url, ['start_ip' => '10.0.0.110', 'end_ip' => '10.0.0.120'])
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('excluded_ranges', [
            'id' => $range->id, 'start_address' => ip2long('10.0.0.110'), 'end_address' => ip2long('10.0.0.120'),
        ]);
        $this->assertFalse($subnet->isExcluded(ip2long('10.0.0.105')));
        $this->assertFalse($subnet->isExcluded(ip2long('10.0.0.130')));
        $this->assertModelExists($ip);

        $this->patch($url, ['start_ip' => '10.0.0.110', 'end_ip' => '10.0.1.0'])->assertSessionHasErrors('start_ip');
        $this->patch($url, ['start_ip' => '10.0.0.120', 'end_ip' => '10.0.0.110'])->assertSessionHasErrors('end_ip');
        $this->assertSame(ip2long('10.0.0.120'), $range->fresh()->end_address);

        $subnet->excludedRanges()->create([
            'start_address' => ip2long('10.0.0.140'), 'end_address' => ip2long('10.0.0.180'),
        ]);
        $this->patch($url, ['start_ip' => '10.0.0.115', 'end_ip' => '10.0.0.150'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('excluded_ranges', 1);
        $this->assertDatabaseHas('excluded_ranges', [
            'id' => $range->id, 'start_address' => ip2long('10.0.0.115'), 'end_address' => ip2long('10.0.0.180'),
        ]);

        $otherSubnet = $site->subnets()->create(Ipv4Cidr::parse('10.0.1.0/24'));
        $this->patch(route('companies.sites.subnets.excluded-ranges.update', [$company, $site, $otherSubnet, $range]), [
            'start_ip' => '10.0.1.100', 'end_ip' => '10.0.1.150',
        ])->assertNotFound();
        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->patch($url, ['start_ip' => '10.0.0.1', 'end_ip' => '10.0.0.2'])->assertForbidden();
        $this->assertSame(ip2long('10.0.0.115'), $range->fresh()->start_address);
        $this->assertModelExists($ip);
    }
}
