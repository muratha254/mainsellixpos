<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\LoyaltyTransaction;
use App\Models\LedgerAccount;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockTransfer;
use App\Services\AccountingLedger;
use App\Services\AccountingPoster;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class OperationalWorkflowTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_cash_sale_via_pos_posts_balanced_journal_deducts_stock_and_is_idempotent(): void
    {
        $qty = 2;
        $unitPrice = (float) $this->product->selling_price;
        $expectedTotal = round($unitPrice * $qty, 2);

        $response = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $qty],
            ],
            'payment_amount' => $expectedTotal,
            'payment_method' => 'cash',
        ]);

        $response->assertOk()->assertJsonStructure(['id', 'number']);

        $sale = Sale::query()->with(['items', 'payments'])->findOrFail($response->json('id'));
        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);
        $this->assertEquals($expectedTotal, (float) $sale->total);
        $this->assertEquals(98.0, $this->stockQty());

        $entry = JournalEntry::query()
            ->with('lines')
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('source_event', 'sale_complete')
            ->first();

        $this->assertNotNull($entry);
        $debit = round((float) $entry->lines->sum('debit'), 2);
        $credit = round((float) $entry->lines->sum('credit'), 2);
        $this->assertGreaterThan(0, $debit);
        $this->assertEquals($debit, $credit);

        $this->assertTrue(
            AuditLog::query()
                ->where('module', 'sales')
                ->where('action', 'create')
                ->where('auditable_type', Sale::class)
                ->where('auditable_id', $sale->id)
                ->exists()
        );

        $this->assertEquals(1, LoyaltyTransaction::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('type', LoyaltyTransaction::TYPE_EARN)
            ->count());

        $this->customer->refresh();
        $this->assertEquals(round($expectedTotal * 0.01, 2), (float) $this->customer->loyalty_points);

        app(AccountingPoster::class)->postSale($sale->fresh(['items.product', 'payments']));

        $this->assertEquals(1, JournalEntry::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('source_event', 'sale_complete')
            ->count());

        $this->assertEquals(1, LoyaltyTransaction::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('type', LoyaltyTransaction::TYPE_EARN)
            ->count());
    }

    public function test_credit_note_create_and_post_restores_stock_and_rejects_over_quantity(): void
    {
        $order = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
            ],
            'payment_amount' => 300,
            'payment_method' => 'cash',
        ]);
        $order->assertOk();

        $sale = Sale::query()->with('items')->findOrFail($order->json('id'));
        $saleItem = $sale->items->first();
        $this->assertEquals(97.0, $this->stockQty());

        $posted = $this->actingAsAdmin()->post(route('sales.credit-notes.store'), [
            'sale_id' => $sale->id,
            'credit_date' => now()->toDateString(),
            'reason' => 'Customer return',
            'notes' => 'Partial return',
            'restore_stock' => 1,
            'action' => 'posted',
            'items' => [
                ['sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ]);

        $posted->assertRedirect();
        $this->assertEquals(98.0, $this->stockQty());

        $note = CreditNote::query()->where('sale_id', $sale->id)->first();
        $this->assertNotNull($note);
        $this->assertSame(CreditNote::STATUS_POSTED, $note->status);
        $this->assertTrue((bool) $note->stock_restored);
        $this->assertTrue((bool) $note->accounting_posted);

        $entry = JournalEntry::query()
            ->with('lines')
            ->where('source_type', CreditNote::class)
            ->where('source_id', $note->id)
            ->where('source_event', 'credit_note')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals(
            round((float) $entry->lines->sum('debit'), 2),
            round((float) $entry->lines->sum('credit'), 2)
        );

        $rejected = $this->actingAsAdmin()
            ->from(route('sales.credit-notes.create'))
            ->post(route('sales.credit-notes.store'), [
                'sale_id' => $sale->id,
                'credit_date' => now()->toDateString(),
                'reason' => 'Too many',
                'restore_stock' => 1,
                'action' => 'posted',
                'items' => [
                    ['sale_item_id' => $saleItem->id, 'quantity' => 99],
                ],
            ]);

        $rejected->assertRedirect(route('sales.credit-notes.create'));
        $rejected->assertSessionHas('error');
        $this->assertEquals(98.0, $this->stockQty());
        $this->assertEquals(1, CreditNote::query()->where('sale_id', $sale->id)->count());
    }

    public function test_stock_transfer_complete_moves_stock_and_rejects_second_complete(): void
    {
        $this->assertEquals(100.0, $this->stockQty($this->branch->id));
        $this->assertEquals(0.0, $this->stockQty($this->secondaryBranch->id));

        $response = $this->actingAsAdmin()->post(route('stock.transfers.store'), [
            'from_branch_id' => $this->branch->id,
            'to_branch_id' => $this->secondaryBranch->id,
            'transfer_date' => now()->toDateString(),
            'notes' => 'Move stock',
            'action' => 'complete',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10,
                    'unit_cost' => 50,
                ],
            ],
        ]);

        $transfer = StockTransfer::query()->latest('id')->first();
        $this->assertNotNull($transfer);
        $response->assertRedirect(route('stock.transfers.show', $transfer));
        $this->assertSame(StockTransfer::STATUS_PENDING, $transfer->fresh()->status);

        $this->assertEquals(90.0, $this->stockQty($this->branch->id));
        $this->assertEquals(0.0, $this->stockQty($this->secondaryBranch->id));

        $this->actingAsAdmin()
            ->withSession(['current_branch_id' => $this->secondaryBranch->id])
            ->get(route('stock.transfers.index'))
            ->assertOk()
            ->assertSee('Receive', false)
            ->assertSee('Reject', false)
            ->assertSee($transfer->number, false);

        $tooSoon = $this->actingAsAdmin()
            ->from(route('stock.transfers.show', $transfer))
            ->post(route('stock.transfers.receive', $transfer));
        $tooSoon->assertRedirect(route('stock.transfers.show', $transfer));
        $tooSoon->assertSessionHas('error');
        $this->assertEquals(0.0, $this->stockQty($this->secondaryBranch->id));

        $received = $this->actingAsAdmin()
            ->withSession(['current_branch_id' => $this->secondaryBranch->id])
            ->from(route('stock.transfers.show', $transfer))
            ->post(route('stock.transfers.receive', $transfer));
        $received->assertRedirect(route('stock.transfers.show', $transfer));
        $received->assertSessionHas('success');
        $this->assertSame(StockTransfer::STATUS_COMPLETED, $transfer->fresh()->status);
        $this->assertEquals(90.0, $this->stockQty($this->branch->id));
        $this->assertEquals(10.0, $this->stockQty($this->secondaryBranch->id));

        $second = $this->actingAsAdmin()
            ->withSession(['current_branch_id' => $this->secondaryBranch->id])
            ->from(route('stock.transfers.show', $transfer))
            ->post(route('stock.transfers.receive', $transfer));

        $second->assertRedirect(route('stock.transfers.show', $transfer));
        $second->assertSessionHas('error');
        $this->assertEquals(90.0, $this->stockQty($this->branch->id));
        $this->assertEquals(10.0, $this->stockQty($this->secondaryBranch->id));
    }

    public function test_receiving_branch_can_reject_a_transfer_and_stock_returns(): void
    {
        $this->actingAsAdmin()->post(route('stock.transfers.store'), [
            'from_branch_id' => $this->branch->id,
            'to_branch_id' => $this->secondaryBranch->id,
            'transfer_date' => now()->toDateString(),
            'action' => 'send',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 4,
                    'unit_cost' => 50,
                ],
            ],
        ])->assertRedirect();

        $transfer = StockTransfer::query()->latest('id')->first();
        $this->assertEquals(96.0, $this->stockQty($this->branch->id));

        $this->actingAsAdmin()
            ->withSession(['current_branch_id' => $this->secondaryBranch->id])
            ->post(route('stock.transfers.reject', $transfer))
            ->assertRedirect(route('stock.transfers.show', $transfer))
            ->assertSessionHas('success');

        $this->assertSame(StockTransfer::STATUS_REJECTED, $transfer->fresh()->status);
        $this->assertEquals(100.0, $this->stockQty($this->branch->id));
        $this->assertEquals(0.0, $this->stockQty($this->secondaryBranch->id));
    }

    public function test_unauthorized_cashier_cannot_access_settings_or_journal(): void
    {
        $this->actingAsCashier()
            ->get(route('settings.company'))
            ->assertForbidden();

        $this->actingAsCashier()
            ->get(route('accounting.journal'))
            ->assertForbidden();
    }

    public function test_sale_return_reverses_cogs_sales_and_cash(): void
    {
        $unitPrice = (float) $this->product->selling_price;
        $response = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
            'payment_amount' => round($unitPrice * 2, 2),
            'payment_method' => 'cash',
        ]);
        $response->assertOk();

        $sale = Sale::query()->with('items')->findOrFail($response->json('id'));
        $item = $sale->items->first();

        $this->actingAsAdmin()->post(route('sales.returns.store', $sale), [
            'receipt_ref' => $sale->documentNumber(),
            'want_refund' => '1',
            'notes' => 'Customer returned one item',
            'items' => [[
                'sale_item_id' => $item->id,
                'quantity' => 1,
                'condition' => 'yes',
            ]],
        ])->assertRedirect(route('sales.returns'));

        $this->assertEquals(1, SaleReturn::query()->count());
        $this->assertEquals(99.0, $this->stockQty());

        $this->actingAsAdmin()
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('data-order="100"', false)
            ->assertSee('data-order="1"', false)
            ->assertSee('data-order="99"', false);

        $report = app(AccountingLedger::class)->profitAndLoss(now()->toDateString(), now()->toDateString());
        $sales = collect($report['income'])->firstWhere('name', 'Sales');
        $this->assertNotNull($sales);
        $this->assertEqualsWithDelta(100.0, (float) $sales['amount'], 0.02);
        $this->assertEqualsWithDelta(50.0, (float) $report['cogs_total'], 0.02);
        $this->assertEqualsWithDelta(50.0, (float) $report['gross_profit'], 0.02);

        $ledger = app(AccountingLedger::class);
        $balance = function (string $name) use ($ledger) {
            $account = LedgerAccount::query()->where('name', $name)->firstOrFail();

            return $ledger->accountBalance($account);
        };
        $this->assertEqualsWithDelta(50.0, $balance('Cost Of Goods Sold'), 0.02);
        $this->assertEqualsWithDelta(100.0, $balance('Sales Revenue'), 0.02);
        $this->assertEqualsWithDelta(100.0, $balance('Cash'), 0.02);
        $this->assertEqualsWithDelta(-50.0, $balance('Inventory'), 0.02);
    }

    public function test_a_cash_refund_does_not_make_the_sales_list_cash_total_negative(): void
    {
        $sell = function (int $quantity) {
            $response = $this->actingAsAdmin()->postJson(route('pos.order'), [
                'customer_id' => $this->customer->id,
                'sale_date' => now()->toDateString(),
                'document_type' => 'pos',
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => $quantity],
                ],
                'payment_amount' => round((float) $this->product->selling_price * $quantity, 2),
                'payment_method' => 'cash',
            ]);
            $response->assertOk();

            return Sale::query()->with('items')->findOrFail($response->json('id'));
        };

        $returned = $sell(2);
        $item = $returned->items->first();
        $this->actingAsAdmin()->post(route('sales.returns.store', $returned), [
            'receipt_ref' => $returned->documentNumber(),
            'want_refund' => '1',
            'notes' => 'Customer returned the sale',
            'items' => [[
                'sale_item_id' => $item->id,
                'quantity' => 2,
                'condition' => 'yes',
            ]],
        ])->assertRedirect(route('sales.returns'));

        $returned->refresh()->load(['payments', 'returns']);
        $this->assertSame(Sale::STATUS_RETURNED, $returned->status);
        $this->assertEquals(0.0, $returned->tenderByMethod('cash'));

        $sell(8);

        $this->actingAsAdmin()
            ->get(route('sales.index'))
            ->assertOk()
            ->assertDontSee('data-order="-', false)
            ->assertSee('>Ksh 800.00<', false);
    }

    public function test_authorized_admin_can_view_credit_notes_index(): void
    {
        $this->actingAsAdmin()
            ->get(route('sales.credit-notes'))
            ->assertOk();
    }
}
