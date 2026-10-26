<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class OwnerBusinessActivityTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_owner_sees_last_login_active_branches_and_activity(): void
    {
        $when = now()->startOfMinute();
        $this->secondaryBranch->update(['is_active' => false, 'name' => 'CLOSED SHOP']);
        $this->admin->forceFill(['last_login_at' => $when])->save();

        AuditLog::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'action' => 'create',
            'module' => 'products',
            'after_json' => ['name' => 'Chrome Vodka'],
            'created_at' => $when,
        ]);

        $this->actingAs($this->makeSystemOwner()->fresh(['role']));

        $this->get(route('owner.activity'))
            ->assertOk()
            ->assertSee('Test Sellix Co')
            ->assertSee($when->format('d M Y H:i'))
            ->assertSee('MAIN')
            ->assertSee('Products Create')
            ->assertSee('Chrome Vodka')
            ->assertDontSee('CLOSED SHOP');

        $this->get(route('owner.businesses.index'))
            ->assertOk()
            ->assertSee($when->format('d M Y H:i'))
            ->assertSee('MAIN')
            ->assertDontSee('CLOSED SHOP');

        $this->get(route('owner.businesses.show', $this->company))
            ->assertOk()
            ->assertSee($when->format('d M Y H:i'))
            ->assertSee('MAIN')
            ->assertSee('CLOSED SHOP')
            ->assertSee('Inactive')
            ->assertSee('Chrome Vodka');

        $this->get(route('owner.dashboard'))
            ->assertOk()
            ->assertSee('Latest sign-ins')
            ->assertSee('Admin');
    }

    public function test_company_admin_cannot_open_owner_activity(): void
    {
        $this->actingAsAdmin()
            ->get(route('owner.activity'))
            ->assertForbidden();
    }

    public function test_login_is_stored_against_the_business(): void
    {
        $this->from(route('login'))
            ->post('/login', [
                'email' => 'admin@test.local',
                'password' => 'password',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'action' => 'login',
            'module' => 'auth',
        ]);
        $this->assertNotNull($this->admin->fresh()->last_login_at);
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
            'name' => 'Cycle Owner',
            'email' => 'owner-activity@test.local',
            'username' => 'owneractivity',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ]);
    }
}
