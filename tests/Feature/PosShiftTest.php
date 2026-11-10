<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\PosShift;
use App\Models\PosShiftAdjustment;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\PermissionCatalog;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class PosShiftTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_a_cashier_must_open_one_shift_before_selling(): void
    {
        PosShift::query()->where('user_id', $this->cashier->id)->delete();

        $this->actingAsCashier()->get(route('pos.index'))
            ->assertRedirect(route('pos.shift.open'));

        $this->actingAsCashier()->postJson(route('pos.order'), $this->orderPayload(1, [
            ['method' => 'cash', 'amount' => 100],
        ]))->assertStatus(422);

        $this->actingAsCashier()->get(route('pos.shift.open'))
            ->assertOk()
            ->assertSee('Cash opening balance')
            ->assertSee($this->cashier->name);

        $this->actingAsCashier()->post(route('pos.shift.store'), [
            'opening_cash' => 5000,
            'opening_mpesa' => 10000,
            'opening_remarks' => 'Morning float',
        ])->assertRedirect(route('pos.index'));

        $this->actingAsCashier()->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Close shift');

        $this->actingAsCashier()->post(route('pos.shift.store'), [
            'opening_cash' => 1,
            'opening_mpesa' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(1, PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShift::STATUS_OPEN)->count());

        $sold = $this->sell(1, [['method' => 'cash', 'amount' => 100]]);
        $sold->assertOk();
        $shift = PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShift::STATUS_OPEN)->first();
        $this->assertSame($shift->id, (int) Sale::query()->find($sold->json('id'))->pos_shift_id);
        $this->assertEquals(15000.0, (float) $shift->opening_cash + (float) $shift->opening_mpesa);
    }

    public function test_cash_up_separates_methods_refunds_expenses_and_a_shortage(): void
    {
        ProductBranchStock::query()->where('product_id', $this->product->id)->update(['quantity' => 500]);
        $shift = $this->cashierShift();
        $shift->update(['opening_cash' => 5000, 'opening_mpesa' => 10000]);

        $this->sell(120, [['method' => 'cash', 'amount' => 12000]])->assertOk();
        $mpesa = $this->sell(80, [['method' => 'mpesa', 'amount' => 8000]]);
        $mpesa->assertOk();
        $voided = $this->sell(1, [['method' => 'cash', 'amount' => 100]]);
        $voided->assertOk();
        Sale::query()->whereKey($voided->json('id'))->update([
            'status' => Sale::STATUS_VOIDED,
            'void_refund' => true,
        ]);

        $mpesaSale = Sale::query()->find($mpesa->json('id'));
        SaleReturn::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'sale_id' => $mpesaSale->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->cashier->id,
            'number' => 'SR-SHIFT-1',
            'return_date' => now(),
            'status' => 'completed',
            'refund_method' => 'mpesa',
            'refund_amount' => 500,
            'restore_stock' => false,
        ]);
        $mpesaSale->update([
            'total' => round((float) $mpesaSale->total - 500, 2),
            'paid_amount' => round((float) $mpesaSale->paid_amount - 500, 2),
        ]);

        Expense::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'entry_type' => Expense::TYPE_DIRECT,
            'description' => 'Drawer payout',
            'amount' => 2000,
            'paid_amount' => 2000,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'paying_account' => 'cash',
            'status' => Expense::STATUS_PAID,
        ]);
        Expense::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'entry_type' => Expense::TYPE_DIRECT,
            'description' => 'Someone else paid this',
            'amount' => 900,
            'paid_amount' => 900,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'paying_account' => 'cash',
            'status' => Expense::STATUS_PAID,
        ]);

        $orphan = Sale::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'document_type' => Sale::TYPE_POS,
            'number' => 'OLD-SHIFT',
            'sale_date' => now(),
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_PAID,
            'subtotal' => 999,
            'total' => 999,
            'paid_amount' => 999,
            'balance' => 0,
        ]);
        Payment::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'number' => 'PAY-OLD-SHIFT',
            'payable_type' => Sale::class,
            'payable_id' => $orphan->id,
            'method' => 'cash',
            'amount' => 999,
            'paid_at' => now(),
        ]);

        $stock = (float) ProductBranchStock::query()->where('product_id', $this->product->id)->value('quantity');
        $sales = Sale::query()->count();

        $this->actingAsCashier()->get(route('pos.shift.close'))
            ->assertOk()
            ->assertSee('Expected closing cash')
            ->assertSee('KSh 15,000.00')
            ->assertSee('KSh 17,500.00');

        $this->actingAsCashier()->post(route('pos.shift.close.store'), [
            'actual_cash' => 14500,
            'actual_mpesa' => 17500,
            'closing_remarks' => 'Cash short by 500',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(PosShift::STATUS_PENDING, $shift->status);
        $this->assertNull($shift->is_open);
        $this->assertEquals(15000.0, (float) $shift->expected_cash);
        $this->assertEquals(17500.0, (float) $shift->expected_mpesa);
        $this->assertEquals(-500.0, (float) $shift->cash_variance);
        $this->assertEquals(0.0, (float) $shift->mpesa_variance);
        $this->assertEquals(12000.0, (float) $shift->cash_sales);
        $this->assertEquals(8000.0, (float) $shift->mpesa_sales);
        $this->assertEquals(500.0, (float) $shift->mpesa_refunds);
        $this->assertEquals(2000.0, (float) $shift->cash_expenses);
        $this->assertSame($sales, Sale::query()->count());
        $this->assertEquals($stock, (float) ProductBranchStock::query()->where('product_id', $this->product->id)->value('quantity'));

        $this->actingAsCashier()->post(route('pos.shift.close.store'), [
            'actual_cash' => 1,
            'actual_mpesa' => 1,
        ])->assertRedirect(route('pos.shift.open'));
        $this->assertEquals(14500.0, (float) $shift->fresh()->actual_cash);

        $this->actingAsCashier()->get(route('shifts.show', $shift))
            ->assertOk()
            ->assertSee('KSh -500.00')
            ->assertSee('Shortage')
            ->assertSee('Pending Approval')
            ->assertDontSee('Approve variance');

        $this->actingAsCashier()->post(route('shifts.approve', $shift))->assertForbidden();

        $reviewer = $this->makeUser('Reviewer', 'reviewer@test.local', 'reviewer', PermissionCatalog::COMPANY_ADMIN);
        $this->actingAs($reviewer->fresh(['role.permissions']))
            ->withSession(['current_branch_id' => $this->branch->id])
            ->post(route('shifts.approve', $shift), ['review_remarks' => 'Count accepted'])
            ->assertRedirect(route('shifts.show', $shift));

        $shift->refresh();
        $this->assertSame(PosShift::STATUS_APPROVED, $shift->status);
        $this->assertEquals(-500.0, (float) $shift->cash_variance);
        $this->assertEquals(0.0, (float) $shift->mpesa_variance);
        $this->assertSame($reviewer->id, (int) $shift->reviewed_by);

        $this->actingAs($reviewer->fresh(['role.permissions']))
            ->withSession(['current_branch_id' => $this->branch->id])
            ->post(route('shifts.adjust', $shift), [
                'cash_amount' => -500,
                'mpesa_amount' => 0,
                'reason' => 'Noted the missing cash',
            ])->assertRedirect(route('shifts.show', $shift));

        $shift->refresh();
        $this->assertEquals(-500.0, (float) $shift->cash_variance);
        $this->assertSame(1, PosShiftAdjustment::query()->where('pos_shift_id', $shift->id)->count());
    }

    public function test_a_balanced_close_does_not_wait_for_approval(): void
    {
        $shift = $this->cashierShift();
        $this->actingAsCashier()->post(route('pos.shift.close.store'), [
            'actual_cash' => 0,
            'actual_mpesa' => 0,
        ])->assertRedirect(route('shifts.show', $shift));

        $shift->refresh();
        $this->assertSame(PosShift::STATUS_CLOSED, $shift->status);
        $this->assertEquals(0.0, (float) $shift->cash_variance);
        $this->assertEquals(0.0, (float) $shift->mpesa_variance);
        $this->assertNull($shift->reviewed_by);
    }

    public function test_split_payments_and_an_mpesa_surplus_stay_on_their_own_methods(): void
    {
        $this->sell(1, [
            ['method' => 'cash', 'amount' => 40],
            ['method' => 'mpesa', 'amount' => 60],
        ])->assertOk();
        $this->sell(1, [['method' => 'card', 'amount' => 100]])->assertOk();

        $shift = $this->cashierShift();
        $this->actingAsCashier()->post(route('pos.shift.close.store'), [
            'actual_cash' => 40,
            'actual_mpesa' => 80,
        ])->assertRedirect();

        $shift->refresh();
        $this->assertEquals(40.0, (float) $shift->cash_sales);
        $this->assertEquals(60.0, (float) $shift->mpesa_sales);
        $this->assertEquals(100.0, (float) $shift->card_sales);
        $this->assertEquals(40.0, (float) $shift->expected_cash);
        $this->assertEquals(60.0, (float) $shift->expected_mpesa);
        $this->assertEquals(0.0, (float) $shift->cash_variance);
        $this->assertEquals(20.0, (float) $shift->mpesa_variance);
        $this->assertSame(PosShift::STATUS_PENDING, $shift->status);

        $this->actingAsCashier()->get(route('shifts.show', $shift))
            ->assertSee('Surplus')
            ->assertSee('KSh 20.00');
    }

    public function test_a_rejected_shift_stays_closed_and_the_cashier_cannot_change_it(): void
    {
        $shift = $this->cashierShift();
        $this->actingAsCashier()->post(route('pos.shift.close.store'), [
            'actual_cash' => 10,
            'actual_mpesa' => 0,
            'closing_remarks' => 'Extra note',
        ])->assertRedirect();
        $shift->refresh();
        $this->assertSame(PosShift::STATUS_PENDING, $shift->status);

        $this->actingAsAdmin()->from(route('shifts.show', $shift))
            ->post(route('shifts.reject', $shift), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAsAdmin()->post(route('shifts.reject', $shift), [
            'rejection_reason' => 'Recount the drawer',
        ])->assertRedirect(route('shifts.show', $shift));

        $shift->refresh();
        $this->assertSame(PosShift::STATUS_REJECTED, $shift->status);
        $this->assertNull($shift->is_open);
        $this->assertEquals(10.0, (float) $shift->actual_cash);
        $this->assertSame('Recount the drawer', $shift->rejection_reason);

        $this->actingAsCashier()->post(route('pos.shift.close.store'), [
            'actual_cash' => 0,
            'actual_mpesa' => 0,
        ])->assertRedirect(route('pos.shift.open'));
        $this->assertEquals(10.0, (float) $shift->fresh()->actual_cash);

        $this->actingAsCashier()->post(route('pos.shift.store'), [
            'opening_cash' => 100,
            'opening_mpesa' => 0,
        ])->assertRedirect(route('pos.index'));
        $this->assertSame(PosShift::STATUS_REJECTED, $shift->fresh()->status);
        $this->assertSame(1, PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShift::STATUS_OPEN)->count());
    }

    public function test_an_administrator_cannot_approve_their_own_variance(): void
    {
        $this->actingAsAdmin()->postJson(route('pos.order'), $this->orderPayload(1, [
            ['method' => 'cash', 'amount' => 100],
        ]))->assertOk();

        $shift = PosShift::query()->where('user_id', $this->admin->id)->where('status', PosShift::STATUS_OPEN)->first();
        $this->actingAsAdmin()->post(route('pos.shift.close.store'), [
            'actual_cash' => 0,
            'actual_mpesa' => 0,
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(PosShift::STATUS_PENDING, $shift->status);
        $this->assertEquals(-100.0, (float) $shift->cash_variance);

        $this->actingAsAdmin()->post(route('shifts.approve', $shift))
            ->assertSessionHas('error');
        $this->assertSame(PosShift::STATUS_PENDING, $shift->fresh()->status);

        $reviewer = $this->makeUser('Reviewer Two', 'reviewer2@test.local', 'reviewer2', PermissionCatalog::COMPANY_ADMIN);
        $this->actingAs($reviewer->fresh(['role.permissions']))
            ->withSession(['current_branch_id' => $this->branch->id])
            ->post(route('shifts.approve', $shift))
            ->assertRedirect(route('shifts.show', $shift));
        $this->assertSame(PosShift::STATUS_APPROVED, $shift->fresh()->status);
        $this->assertEquals(-100.0, (float) $shift->fresh()->cash_variance);
    }

    public function test_shift_reports_keep_cashiers_and_branches_apart(): void
    {
        $other = $this->makeUser('Other Till', 'other@test.local', 'othertill', PermissionCatalog::CASHIER);
        $other->update(['branch_id' => $this->secondaryBranch->id]);
        PosShift::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->secondaryBranch->id,
            'user_id' => $other->id,
            'number' => 'SHF-OTHER',
            'status' => PosShift::STATUS_OPEN,
            'is_open' => 1,
            'opened_at' => now(),
            'opening_cash' => 20,
            'opening_mpesa' => 30,
        ]);

        $this->actingAsCashier()->get(route('shifts.index'))
            ->assertOk()
            ->assertSee('SHF-CASHIER')
            ->assertSee('Shift Reports')
            ->assertDontSee('SHF-ADMIN')
            ->assertDontSee('SHF-OTHER');

        $manager = $this->makeUser('Manager', 'manager@test.local', 'manager', PermissionCatalog::BRANCH_MANAGER);
        $this->actingAs($manager->fresh(['role.permissions']))
            ->withSession(['current_branch_id' => $this->branch->id])
            ->get(route('shifts.index'))
            ->assertOk()
            ->assertSee('SHF-CASHIER')
            ->assertDontSee('SHF-OTHER');

        $this->actingAsAdmin()->get(route('shifts.index', ['approval' => 'none', 'branch_id' => $this->secondaryBranch->id]))
            ->assertOk()
            ->assertSee('SHF-OTHER')
            ->assertDontSee('SHF-CASHIER');

        $this->actingAsInventoryManager()->get(route('shifts.index'))->assertForbidden();
        $this->actingAsInventoryManager()->post(route('pos.shift.store'), [
            'opening_cash' => 1,
            'opening_mpesa' => 1,
        ])->assertForbidden();
    }

    private function cashierShift(): PosShift
    {
        return PosShift::query()->where('user_id', $this->cashier->id)->where('status', PosShift::STATUS_OPEN)->firstOrFail();
    }

    private function sell(int $qty, array $payments)
    {
        return $this->actingAsCashier()->postJson(route('pos.order'), $this->orderPayload($qty, $payments));
    }

    private function orderPayload(int $qty, array $payments): array
    {
        return [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $qty],
            ],
            'payments' => $payments,
        ];
    }
}
