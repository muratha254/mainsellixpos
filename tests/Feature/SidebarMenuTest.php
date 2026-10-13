<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class SidebarMenuTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_super_admin_sees_the_modules_their_role_allows(): void
    {
        $this->actingAsAdmin()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('menu-label">Dashboard', false)
            ->assertSee('menu-label">Items/Products', false)
            ->assertSee('menu-label">Users Management', false)
            ->assertSee('menu-label">Human Resource', false)
            ->assertSee('menu-label">Reports Manager', false)
            ->assertSee('Sales Reports', false);
    }

    public function test_cashier_sidebar_hides_modules_outside_the_role(): void
    {
        $this->actingAsCashier()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('menu-label">Dashboard', false)
            ->assertSee('>POS<', false)
            ->assertSee('Sales List', false)
            ->assertSee('Change Password', false)
            ->assertDontSee('menu-label">Purchase', false)
            ->assertDontSee('Users Management', false)
            ->assertDontSee('Chart of Accounts', false)
            ->assertDontSee('Human Resource', false)
            ->assertDontSee('Reports Manager', false);

        $this->get(route('purchases.index'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_inventory_manager_sees_only_the_report_groups_they_can_open(): void
    {
        $this->actingAsInventoryManager()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('menu-label">Reports Manager', false)
            ->assertSee('Stock Report', false)
            ->assertSee('Purchase Report', false)
            ->assertDontSee('Employee Clearance', false)
            ->assertDontSee('Tax Report', false)
            ->assertDontSee('Users Management', false);

        $this->get(route('reports.sales.sales'))->assertForbidden();
        $this->get(route('reports.stock.stock'))->assertOk();
    }

    public function test_user_without_a_role_only_sees_password_and_sign_out(): void
    {
        $user = User::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'role_id' => null,
            'name' => 'No Role',
            'email' => 'norole@test.local',
            'username' => 'norole',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_branch_id' => $this->branch->id])
            ->get(route('settings.password'))
            ->assertOk()
            ->assertSee('>Change Password', false)
            ->assertSee('menu-label">Sign out', false)
            ->assertDontSee('menu-label">Dashboard', false)
            ->assertDontSee('menu-label">Items/Products', false)
            ->assertDontSee('Users Management', false)
            ->assertDontSee('Company Profile', false);

        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('products.index'))->assertForbidden();
    }
}
