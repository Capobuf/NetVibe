<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Ipv4Cidr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubnetSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_text_values_remain_local_when_entries_are_created_updated_and_deleted(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $this->actingAs($admin);
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $subnet = $site->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'));
        $otherCompany = Company::create(['name' => 'Beta']);
        $otherSite = $otherCompany->sites()->create(['name' => null]);
        $otherSubnet = $otherSite->subnets()->create(Ipv4Cidr::parse('192.168.1.0/24'));
        $otherEntry = $otherSubnet->summaryEntries()->create(['name' => 'DNS', 'values' => ['Altro cliente']]);
        $context = [$company, $site, $subnet];
        $store = route('companies.sites.subnets.summary-entries.store', $context);
        $values = ['1.1.1.1', "Nota libera, senza formato imposto\nSeconda riga"];

        $this->post($store, ['name' => 'DNS', 'values' => $values, 'subnet_id' => $otherSubnet->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $entry = $subnet->summaryEntries()->sole();
        $this->assertSame($values, $entry->values);
        $this->get(route('companies.sites.subnets.show', $context))
            ->assertInertia(fn (Assert $page) => $page->component('subnets/show')
                ->has('summaryEntries', 1)->where('summaryEntries.0.name', 'DNS')
                ->where('summaryEntries.0.values', $values));

        $update = route('companies.sites.subnets.summary-entries.update', [...$context, $entry]);
        $this->patch($update, ['name' => 'Note', 'values' => ['']])->assertSessionHasErrors('values.0');
        $this->assertSame($values, $entry->fresh()->values);
        $this->patch($update, ['name' => 'Note', 'values' => ['Testo aggiornato']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Note', $entry->fresh()->name);
        $this->assertSame(['Testo aggiornato'], $entry->fresh()->values);

        $this->patch(route('companies.sites.subnets.summary-entries.update', [...$context, $otherEntry]), [
            'name' => 'Modifica estranea', 'values' => ['Non salvare'],
        ])->assertNotFound();
        $this->delete(route('companies.sites.subnets.summary-entries.destroy', [...$context, $otherEntry]), [
            'confirmed' => true,
        ])->assertNotFound();

        $this->actingAs(User::factory()->create(['is_superadmin' => false]));
        $this->patch($update, ['name' => 'Non consentito', 'values' => ['Non salvare']])->assertForbidden();
        $this->assertSame('Note', $entry->fresh()->name);

        $this->actingAs($admin);
        $destroy = route('companies.sites.subnets.summary-entries.destroy', [...$context, $entry]);
        $this->delete($destroy)->assertSessionHasErrors('confirmed');
        $this->assertModelExists($entry);
        $this->delete($destroy, ['confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertModelMissing($entry);
        $this->assertSame(['Altro cliente'], $otherEntry->fresh()->values);
    }
}
