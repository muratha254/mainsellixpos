<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class PurchaseListStatusTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_purchase_list_shows_order_status_and_allows_change(): void
    {
        $supplier = Supplier::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Kenchic Supplies',
            'is_active' => true,
        ]);

        $purchase = PurchaseOrder::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->admin->id,
            'number' => 'PO-STATUS-1',
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrder::STATUS_PENDING,
            'subtotal' => 100,
            'total' => 100,
            'paid_amount' => 0,
        ]);

        $this->actingAsAdmin()
            ->get(route('purchases.index'))
            ->assertOk()
            ->assertSee('Status')
            ->assertSee('Pending')
            ->assertSee('sx-po-status-select', false)
            ->assertSee(route('purchases.status.update', $purchase), false);

        $this->actingAsAdmin()
            ->put(route('purchases.status.update', $purchase), ['status' => PurchaseOrder::STATUS_ORDERED])
            ->assertRedirect();

        $this->assertEquals(PurchaseOrder::STATUS_ORDERED, $purchase->fresh()->status);
    }

    public function test_supplier_list_payment_reduces_the_outstanding_balance(): void
    {
        $supplier = Supplier::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'name' => 'Kenchic Supplies',
            'is_active' => true,
            'opening_balance' => 0,
        ]);

        $purchase = PurchaseOrder::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'user_id' => $this->admin->id,
            'number' => 'PO-PAY-1',
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'subtotal' => 100,
            'total' => 100,
            'paid_amount' => 0,
        ]);

        $this->actingAsAdmin()
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('Add Payment')
            ->assertSee('Add Supplier Payment', false);

        $this->actingAsAdmin()
            ->get(route('accounting.suppliers'))
            ->assertOk()
            ->assertSee('Add Payment')
            ->assertSee('Add Supplier Payment', false);

        $this->actingAsAdmin()
            ->from(route('suppliers.index'))
            ->post(route('suppliers.payments.store', $supplier), [
                'amount' => 40,
                'method' => 'cash',
                'paid_at' => now()->toDateString(),
                'notes' => 'Part payment',
            ])
            ->assertRedirect(route('suppliers.index'))
            ->assertSessionHas('success');

        $this->assertEquals(40.0, (float) $purchase->fresh()->paid_amount);
        $this->assertEquals(60.0, $supplier->fresh()->currentBalance());
    }
}
