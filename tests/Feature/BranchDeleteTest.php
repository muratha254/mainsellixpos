<?php

namespace Tests\Feature;

use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class BranchDeleteTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_the_branch_list_delete_link_keeps_the_branch_name_out_of_the_click_code(): void
    {
        $this->actingAsAdmin()
            ->get(route('settings.branches'))
            ->assertOk()
            ->assertSee('sx-delete-branch', false)
            ->assertSee('data-name="WAREHOUSE"', false)
            ->assertDontSee('onclick="event.preventDefault(); sxDeleteBranch', false);
    }

    public function test_a_branch_with_staff_is_deleted_and_those_staff_move_to_the_default_branch(): void
    {
        $this->cashier->update(['branch_id' => $this->secondaryBranch->id]);

        $this->actingAsAdmin()
            ->from(route('settings.branches'))
            ->delete(route('settings.branches.destroy', $this->secondaryBranch), [
                'staff_action' => 'move',
            ])
            ->assertRedirect(route('settings.branches'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($this->secondaryBranch);
        $this->assertSame((int) $this->branch->id, (int) $this->cashier->fresh()->branch_id);
    }

    public function test_the_default_branch_stays_and_the_page_explains_why(): void
    {
        $this->actingAsAdmin()
            ->delete(route('settings.branches.destroy', $this->branch))
            ->assertRedirect(route('settings.branches'))
            ->assertSessionHas('error');

        $this->assertNull($this->branch->fresh()->deleted_at);
    }

    public function test_staff_stay_put_until_the_user_chooses_to_move_or_turn_them_off(): void
    {
        $this->cashier->update(['branch_id' => $this->secondaryBranch->id]);

        $this->actingAsAdmin()
            ->delete(route('settings.branches.destroy', $this->secondaryBranch))
            ->assertRedirect(route('settings.branches'))
            ->assertSessionHas('error');

        $this->assertNull($this->secondaryBranch->fresh()->deleted_at);
        $this->assertSame((int) $this->secondaryBranch->id, (int) $this->cashier->fresh()->branch_id);
        $this->assertTrue((bool) $this->cashier->fresh()->is_active);

        $this->actingAsAdmin()
            ->delete(route('settings.branches.destroy', $this->secondaryBranch), [
                'staff_action' => 'remove',
            ])
            ->assertRedirect(route('settings.branches'))
            ->assertSessionHas('success');

        $cashier = $this->cashier->fresh();
        $this->assertSoftDeleted($this->secondaryBranch);
        $this->assertFalse((bool) $cashier->is_active);
        $this->assertNull($cashier->branch_id);
    }
}
