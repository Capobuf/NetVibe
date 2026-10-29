<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_users_can_manage_their_sites_and_ipam_but_cannot_cross_company_or_administration_boundaries(): void
    {
        $company = Company::create(['name' => 'Alfa']);
        $site = $company->sites()->create(['name' => null]);
        $other = Company::create(['name' => 'Beta']);
        $otherSite = $other->sites()->create(['name' => null]);
        $user = User::factory()->create();
        $user->companies()->attach($company);
        $this->actingAs($user);

        $this->get(route('companies.index'))->assertInertia(fn (Assert $page) => $page
            ->where('companies.total', 1)->where('companies.data.0.id', $company->id)
            ->has('companyNavigation.companies', 1)->where('companyNavigation.companies.0.id', $company->id));
        $this->get(route('companies.sites.subnets.index', [$company, $site]))
            ->assertInertia(fn (Assert $page) => $page->where('auth.canEditIpam', true));
        $this->post(route('companies.sites.subnets.store', [$company, $site]), ['cidr' => '192.168.1.0/24'])->assertSessionHasNoErrors();
        $subnet = $site->subnets()->sole();
        $this->post(route('companies.sites.subnets.ip-addresses.store', [$company, $site, $subnet]), [
            'ip' => '192.168.1.1', 'hostname' => 'gateway',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ip_addresses', ['subnet_id' => $subnet->id, 'hostname' => 'gateway']);
        $this->get(route('companies.sites.search.index', [$company, $site, 'search' => 'gateway']))
            ->assertInertia(fn (Assert $page) => $page->where('results.total', 1));

        $this->post(route('companies.sites.store', $company), ['name' => 'Roma', 'existing_site_name' => 'Torino'])
            ->assertRedirect(route('companies.settings.show', $company))->assertSessionHasNoErrors();
        $newSite = $company->sites()->where('name', 'Roma')->sole();
        $this->patch(route('companies.sites.update', [$company, $newSite]), ['name' => 'Milano'])->assertSessionHasNoErrors();
        $token = $this->getJson(route('companies.sites.deletion-impact', [$company, $newSite]))->assertOk()->json('impact_token');
        $this->delete(route('companies.sites.destroy', [$company, $newSite]), ['confirmed' => true, 'impact_token' => $token])->assertSessionHasNoErrors();
        $this->assertModelMissing($newSite);

        $this->get(route('companies.show', $other))->assertForbidden();
        $this->get(route('companies.sites.search.index', [$other, $otherSite, 'search' => 'gateway']))->assertForbidden();
        $this->post(route('companies.sites.subnets.store', [$other, $otherSite]), ['cidr' => '10.0.0.0/24'])->assertForbidden();
        $this->patch(route('companies.sites.update', [$company, $otherSite]), ['name' => 'Changed'])->assertNotFound();
        $this->get(route('administration.users.index'))->assertForbidden();
        $this->post(route('administration.users.store'), ['email' => 'admin@example.com'])->assertForbidden();
        $this->get(route('companies.settings.users.index', $company))->assertForbidden();
        $this->post(route('companies.settings.users.store', $company), ['email' => 'other@example.com'])->assertForbidden();
        $this->delete(route('companies.settings.users.destroy', [$company, $user]), ['confirmed' => true])->assertForbidden();
        $this->assertSame(1, $company->sites()->count());
        $this->assertSame(0, $otherSite->subnets()->count());
    }

    public function test_company_invitation_uses_one_account_and_the_existing_password_broker_without_granting_global_access(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_superadmin' => true]);
        $company = Company::create(['name' => 'Alfa']);
        $other = Company::create(['name' => 'Beta']);
        $this->actingAs($admin);
        $this->post(route('companies.settings.users.store', $company), ['email' => 'INVITED@example.com', 'is_superadmin' => true])
            ->assertSessionHasNoErrors();
        $invited = User::where('email', 'invited@example.com')->sole();
        $this->assertFalse($invited->is_superadmin);
        $this->assertFalse($invited->hasVerifiedEmail());
        $this->assertSame([$company->id], $invited->companies()->pluck('companies.id')->all());
        Notification::assertSentTo($invited, UserInvitation::class, fn ($notification) => Password::tokenExists($invited, $notification->token));

        $this->post(route('companies.settings.users.store', $other), ['email' => $invited->email])->assertSessionHasNoErrors();
        $this->post(route('companies.settings.users.store', $other), ['email' => $invited->email])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(2, $invited->companies()->count());
        Notification::assertSentToTimes($invited, UserInvitation::class, 1);

        $token = Notification::sent($invited, UserInvitation::class)->sole()->token;
        $this->post(route('logout'));
        $this->post(route('password.update'), ['token' => $token, 'email' => $invited->email, 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $invited->fresh()->password));
    }

    public function test_superadmin_invites_do_not_promote_existing_company_accounts_or_create_company_associations(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $companyUser = User::factory()->create();
        $company = Company::create(['name' => 'Alfa']);
        $this->post(route('administration.users.store'), ['email' => $companyUser->email])->assertSessionHasErrors('email');
        $this->assertFalse($companyUser->fresh()->is_superadmin);
        $this->post(route('administration.users.store'), ['email' => 'second@example.com'])->assertSessionHasNoErrors();
        $admin = User::where('email', 'second@example.com')->sole();
        $this->assertTrue($admin->is_superadmin);
        Notification::assertSentTo($admin, UserInvitation::class);
        $this->post(route('companies.settings.users.store', $company), ['email' => $admin->email])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('company_user', 0);
        $this->assertTrue($admin->can('access-company', $company));
        $futureCompany = Company::create(['name' => 'Futura']);
        $this->assertTrue($admin->can('access-company', $futureCompany));
    }

    public function test_deleting_from_a_company_removes_the_entire_account_and_tokens_but_preserves_ipam(): void
    {
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $user = User::factory()->create();
        $company = Company::create(['name' => 'Alfa']);
        $other = Company::create(['name' => 'Beta']);
        $site = $company->sites()->create(['name' => null]);
        $this->post(route('companies.sites.subnets.store', [$company, $site]), ['cidr' => '192.168.1.0/24'])->assertSessionHasNoErrors();
        $subnet = $site->subnets()->sole();
        $ip = $subnet->ipAddresses()->create(['address' => ip2long('192.168.1.1'), 'hostname' => 'gateway']);
        $user->companies()->attach([$company->id, $other->id]);
        Password::createToken($user);
        $this->delete(route('companies.settings.users.destroy', [$company, $user]))->assertSessionHasErrors('confirmed');
        $this->delete(route('companies.settings.users.destroy', [$company, $user]), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertModelMissing($user);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertModelExists($subnet);
        $this->assertModelExists($ip);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_last_superadmin_is_protected_in_administration_and_self_deletion(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $this->actingAs($admin);
        $this->delete(route('administration.users.destroy', $admin), ['confirmed' => true])->assertSessionHasErrors('user');
        $this->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('user');
        $this->assertModelExists($admin);
        $this->assertAuthenticatedAs($admin);
        $second = User::factory()->create(['is_superadmin' => true]);
        $this->delete(route('administration.users.destroy', $second), ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertModelMissing($second);
        $this->assertModelExists($admin);
    }
}
