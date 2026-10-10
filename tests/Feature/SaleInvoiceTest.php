<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Sale;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class SaleInvoiceTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_invoice_list_and_create_pages_match_quotation_flow(): void
    {
        $this->actingAsAdmin()->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Invoice List')
            ->assertSee('New Invoice');

        $this->actingAsAdmin()->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('Create Invoice')
            ->assertSee('Invoice Date')
            ->assertSee('Ksh')
            ->assertSee('%');
    }

    public function test_creating_an_invoice_posts_sale_and_deducts_stock(): void
    {
        $response = $this->actingAsAdmin()->post(route('invoices.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'discount_amount' => 0,
            'notes' => 'Office invoice',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_amount' => 0,
                ],
            ],
        ]);

        $sale = Sale::query()->where('document_type', Sale::TYPE_INVOICE)->latest('id')->first();
        $this->assertNotNull($sale);
        $response->assertRedirect(route('invoices.show', $sale));

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->status);
        $this->assertSame(Sale::PAYMENT_UNPAID, $sale->payment_status);
        $this->assertEquals(200.0, (float) $sale->total);
        $this->assertEquals(200.0, (float) $sale->balance);
        $this->assertNotEmpty($sale->invoice_number);
        $this->assertEquals(98.0, $this->stockQty());

        $this->actingAsAdmin()->get(route('invoices.show', $sale))
            ->assertOk()
            ->assertSee($sale->invoice_number);

        $this->assertNotNull(
            JournalEntry::query()
                ->where('source_type', Sale::class)
                ->where('source_id', $sale->id)
                ->first()
        );
    }

    public function test_invoice_percentage_discount_reduces_total(): void
    {
        $this->actingAsAdmin()->post(route('invoices.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount_type' => 'percent',
            'discount_value' => 10,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount_type' => 'fixed',
                    'discount_value' => 0,
                ],
            ],
        ])->assertRedirect();

        $sale = Sale::query()->where('document_type', Sale::TYPE_INVOICE)->latest('id')->first();
        $this->assertEquals(10.0, (float) $sale->discount_percent);
        $this->assertEquals(20.0, (float) $sale->discount_amount);
        $this->assertEquals(180.0, (float) $sale->total);
    }

    public function test_cashier_cannot_open_invoice_page(): void
    {
        $this->actingAsCashier()->get(route('invoices.index'))->assertForbidden();
        $this->actingAsCashier()->get(route('invoices.create'))->assertForbidden();
    }
}
