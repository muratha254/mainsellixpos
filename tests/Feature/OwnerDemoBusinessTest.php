<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class OwnerDemoBusinessTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_owner_can_create_a_client_demo_and_the_client_can_choose_a_tour(): void
    {
        $owner = $this->makeSystemOwner();
        $this->actingAs($owner->fresh(['role']));

        $this->get(route('owner.businesses.index'))->assertOk()->assertSee('New demo');
        $this->get(route('owner.businesses.demo.create'))->assertOk()->assertSee('New demo for a client');

        $this->followingRedirects()->post(route('owner.businesses.demo.store'), [
            'name' => 'Acme Traders Demo',
            'client_name' => 'Asha Client',
            'username' => 'asha.demo',
            'password' => 'client-pass',
            'password_confirmation' => 'client-pass',
            'days' => 14,
        ])->assertOk()->assertSee('client-pass')->assertSee('asha.demo')->assertSee('Start Guided Tour');

        $company = Company::query()->where('name', 'Acme Traders Demo')->firstOrFail();
        $this->assertTrue((bool) $company->is_demo);
        $this->assertNotSame($this->company->id, $company->id);

        $client = User::query()->where('username', 'asha.demo')->firstOrFail();
        $this->assertSame($company->id, (int) $client->company_id);
        $this->assertFalse($client->isSystemOwner());
        $this->assertNotNull(Product::query()->withoutGlobalScope('company')->where('company_id', $company->id)->where('sku', 'DEMO-MILK')->first());
        $this->assertSame(0, Product::query()->withoutGlobalScope('company')->where('company_id', $this->company->id)->where('sku', 'DEMO-MILK')->count());

        auth()->logout();
        $this->post('/login', ['email' => 'asha.demo', 'password' => 'client-pass'])
            ->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Welcome to Your Interactive POS Demo!')
            ->assertSee('Start Guided Tour')
            ->assertSee('Explore on My Own');
        $this->get(route('products.show', $this->product))->assertNotFound();
        $this->get(route('owner.businesses.index'))->assertForbidden();
    }

    public function test_a_business_user_cannot_create_a_demo(): void
    {
        $this->actingAs($this->admin->fresh(['role']));
        $this->get(route('owner.businesses.demo.create'))->assertForbidden();
        $this->post(route('owner.businesses.demo.store'), [
            'name' => 'Blocked Demo',
            'client_name' => 'Blocked',
            'username' => 'blocked.demo',
            'days' => 7,
        ])->assertForbidden();
        $this->assertNull(Company::query()->where('name', 'Blocked Demo')->first());
    }

    public function test_a_new_password_replaces_the_old_one_and_reset_stays_inside_the_demo(): void
    {
        $owner = $this->makeSystemOwner();
        $this->actingAs($owner->fresh(['role']));
        $this->post(route('owner.businesses.demo.store'), [
            'name' => 'Reset Demo Shop',
            'client_name' => 'Reset Client',
            'username' => 'reset.demo',
            'password' => 'old-pass',
            'password_confirmation' => 'old-pass',
            'days' => 10,
        ])->assertRedirect();

        $company = Company::query()->where('name', 'Reset Demo Shop')->firstOrFail();
        $this->post(route('owner.businesses.demo-password', $company))->assertRedirect(route('owner.businesses.show', $company));
        $issued = session('demo_credentials');
        $this->assertSame('reset.demo', $issued['username']);
        $this->assertNotSame('old-pass', $issued['password']);

        auth()->logout();
        $this->from(route('login'))->post('/login', ['email' => 'reset.demo', 'password' => 'old-pass'])->assertRedirect(route('login'));
        $this->post('/login', ['email' => 'reset.demo', 'password' => $issued['password']])->assertRedirect(route('dashboard'));

        $this->post(route('products.store'), [
            'name' => 'TEMP CLIENT ITEM',
            'category_id' => \App\Models\ProductCategory::query()->withoutGlobalScope('company')->where('company_id', $company->id)->value('id'),
            'unit_id' => \App\Models\Unit::query()->withoutGlobalScope('company')->where('company_id', $company->id)->value('id'),
            'tax_id' => \App\Models\Tax::query()->withoutGlobalScope('company')->where('company_id', $company->id)->value('id'),
            'purchase_price' => 10,
            'selling_price' => 20,
        ])->assertRedirect();

        $this->post(route('demo.reset'))->assertRedirect(route('dashboard'));
        $this->assertNull(Product::query()->withoutGlobalScope('company')->where('name', 'TEMP CLIENT ITEM')->first());
        $this->assertNotNull(Product::query()->withoutGlobalScope('company')->where('company_id', $company->id)->where('sku', 'DEMO-MILK')->first());
        $this->assertNotNull(Product::query()->withoutGlobalScope('company')->where('company_id', $this->company->id)->where('name', 'Test Widget')->first());
    }

    protected function makeSystemOwner(): User
    {
        $role = Role::query()->withoutGlobalScope('company')->firstOrCreate(
            ['name' => PermissionCatalog::SYSTEM_OWNER, 'company_id' => null],
            [
                'display_name' => 'System Owner',
                'description' => 'Platform',
                'is_system' => true,
            ]
        );

        return User::query()->create([
            'company_id' => null,
            'branch_id' => null,
            'role_id' => $role->id,
            'name' => 'Demo Owner',
            'email' => 'owner-demo@test.local',
            'username' => 'ownerdemo',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
    }
}
