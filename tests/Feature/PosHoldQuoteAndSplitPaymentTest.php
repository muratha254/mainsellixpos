<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class PosHoldQuoteAndSplitPaymentTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_a_held_sale_can_be_printed_as_a_price_quotation(): void
    {
        $hold = $this->actingAsCashier()->postJson(route('pos.hold'), $this->cartPayload());
        $hold->assertOk();
        $sale = Sale::query()->findOrFail($hold->json('id'));

        $this->assertSame(Sale::STATUS_HELD, $sale->status);
        $this->assertEquals(100.0, (float) ProductBranchStock::query()->where('product_id', $this->product->id)->value('quantity'));

        $this->actingAsCashier()->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Print prices', false)
            ->assertSee('Split payment', false)
            ->assertSee('sx-print-hold', false);

        $this->actingAsCashier()->get(route('pos.holds.print', $sale))
            ->assertOk()
            ->assertSee('Price Quotation', false)
            ->assertSee('Hold No:', false)
            ->assertSee($this->product->name, false)
            ->assertSee('Total 100.00', false)
            ->assertSee('NOT YET PAID', false)
            ->assertDontSee('Paymode:', false);

        $this->assertSame(Sale::STATUS_HELD, $sale->fresh()->status);
        $this->assertEquals(100.0, (float) ProductBranchStock::query()->where('product_id', $this->product->id)->value('quantity'));

        $other = $this->actingAsAdmin()->postJson(route('pos.hold'), $this->cartPayload());
        $other->assertOk();
        $this->actingAsCashier()->get(route('pos.holds.print', $other->json('id')))->assertForbidden();
    }

    public function test_a_sale_can_be_paid_with_cash_and_another_method(): void
    {
        $response = $this->actingAsAdmin()->postJson(route('pos.order'), array_merge($this->cartPayload(), [
            'payments' => [
                ['method' => 'cash', 'amount' => 80],
                ['method' => 'card', 'amount' => 40],
            ],
        ]));
        $response->assertOk();

        $sale = Sale::query()->with('payments')->findOrFail($response->json('id'));
        $this->assertEquals(100.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->balance);
        $this->assertEquals(80.0, (float) $sale->payments->firstWhere('method', Payment::METHOD_CASH)->amount);
        $this->assertEquals(40.0, (float) $sale->payments->firstWhere('method', Payment::METHOD_CARD)->amount);

        $this->actingAsAdmin()->get(route('pos.receipt', $sale))
            ->assertOk()
            ->assertSee('Cash', false)
            ->assertSee('Card', false)
            ->assertSee('Tendered', false)
            ->assertSee('80.00', false)
            ->assertSee('Balance', false)
            ->assertSee('20.00', false)
            ->assertDontSee('Mpesa', false);
    }

    private function cartPayload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ];
    }
}
