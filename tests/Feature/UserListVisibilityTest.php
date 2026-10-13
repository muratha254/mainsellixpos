<?php

namespace Tests\Feature;

use App\Support\PermissionCatalog;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class UserListVisibilityTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_branch_manager_does_not_see_super_admin_on_the_user_list(): void
    {
        $manager = $this->makeUser('Branch Manager', 'manager@test.local', 'manager', PermissionCatalog::BRANCH_MANAGER);

        $this->actingAs($manager->fresh(['role.permissions']))
            ->withSession(['current_branch_id' => $this->branch->id])
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('cashier@test.local', false)
            ->assertDontSee('admin@test.local', false)
            ->assertDontSee('Super Admin', false);
    }

    public function test_super_admin_still_sees_super_admin_on_the_user_list(): void
    {
        $this->actingAsAdmin()
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('admin@test.local', false)
            ->assertSee('Super Admin', false);
    }
}
