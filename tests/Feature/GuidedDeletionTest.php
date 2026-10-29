<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IpStatus;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidedDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_deletion_rechecks_documentation_and_removes_only_its_dependents(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $vlan = $site->vlans()->create(['tag' => 10]);
        $subnet = $site->subnets()->create([...Ipv4Cidr::parse('192.168.1.0/24'), 'vlan_id' => $vlan->id]);
        $status = IpStatus::create(['name' => 'Operativo']);
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('192.168.1.10'), 'hostname' => 'router', 'ip_status_id' => $status->id]);
        $range = $subnet->excludedRanges()->create(['start_address' => ip2long('192.168.1.100'), 'end_address' => ip2long('192.168.1.120')]);
        $entry = $subnet->summaryEntries()->create(['name' => 'DNS', 'values' => ['1.1.1.1']]);
        $tag = $company->tags()->create(['name' => 'Uffici', 'color' => 'blue']);
        $ip->tags()->attach($tag);
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => null]);
        $otherSubnet = $otherSite->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'));
        $otherIp = $otherSubnet->ipAddresses()->create(['address' => $ip->address, 'hostname' => 'router-beta', 'ip_status_id' => $status->id]);
        $otherTag = $otherCompany->tags()->create(['name' => 'Uffici', 'color' => 'green']);
        $otherIp->tags()->attach($otherTag);
        $previewUrl = route('administration.companies.deletion-impact', $company);
        $deleteUrl = route('administration.companies.destroy', $company);
        $token = $this->getJson($previewUrl)->assertOk()
            ->assertJsonCount(1, 'ips')->assertJsonCount(1, 'ranges')->assertJsonCount(1, 'summaries')
            ->assertJsonCount(1, 'tag_links')->json('impact_token');
        $entry->update(['values' => ['9.9.9.9']]);

        $this->delete($deleteUrl, ['confirmed' => true, 'impact_token' => $token])
            ->assertSessionHasErrors('impact_token');

        $this->assertModelExists($company);
        $this->assertModelExists($ip);
        $this->assertModelExists($entry);
        $this->assertDatabaseHas('ip_address_tag', ['ip_address_id' => $ip->id, 'tag_id' => $tag->id]);
        $token = $this->getJson($previewUrl)->assertOk()->json('impact_token');

        $this->delete($deleteUrl, ['confirmed' => true, 'impact_token' => $token])
            ->assertRedirect(route('administration.companies.index'))->assertSessionHasNoErrors();

        foreach ([$company, $site, $vlan, $subnet, $ip, $range, $entry, $tag] as $deleted) {
            $this->assertModelMissing($deleted);
        }
        $this->assertDatabaseMissing('ip_address_tag', ['ip_address_id' => $ip->id]);
        foreach ([$otherCompany, $otherSite, $otherSubnet, $otherIp, $otherTag, $status] as $preserved) {
            $this->assertModelExists($preserved);
        }
        $this->assertDatabaseHas('ip_address_tag', ['ip_address_id' => $otherIp->id, 'tag_id' => $otherTag->id]);
    }
}
