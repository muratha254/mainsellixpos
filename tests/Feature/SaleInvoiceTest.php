<?php

namespace Tests\Feature;

use App\Mail\QuotationMail;
use App\Models\JournalEntry;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
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

    public function test_invoice_and_quotation_download_as_pdf(): void
    {
        $this->actingAsAdmin()->post(route('invoices.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 50,
                'tax_rate' => 0,
            ]],
        ])->assertRedirect();

        $sale = Sale::query()->where('document_type', Sale::TYPE_INVOICE)->latest('id')->first();
        $this->actingAsAdmin()->get(route('invoices.show', $sale))
            ->assertOk()
            ->assertSee('Download PDF');

        $invoice = $this->actingAsAdmin()->get(route('invoices.pdf', $sale));
        $invoice->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $invoice->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $invoice->getContent());
        $this->assertStringContainsString(
            'inv-walk-in-'.preg_replace('/\D+/', '', (string) $sale->documentNumber()),
            (string) $invoice->headers->get('content-disposition')
        );

        $this->actingAsAdmin()->post(route('quotations.store'), [
            'customer_id' => $this->customer->id,
            'quote_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 75,
                'tax_rate' => 0,
            ]],
        ])->assertRedirect();

        $quotation = Quotation::query()->latest('id')->first();
        $this->actingAsAdmin()->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Download PDF');

        $quote = $this->actingAsAdmin()->get(route('quotations.pdf', $quotation));
        $quote->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $quote->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $quote->getContent());
        $this->assertStringContainsString(
            'quo-walk-in-'.preg_replace('/\D+/', '', (string) $quotation->number),
            (string) $quote->headers->get('content-disposition')
        );

        $this->actingAsAdmin()->get(route('quotations.print', $quotation))
            ->assertOk()
            ->assertSee('QUOTATION')
            ->assertSee('BILL TO:')
            ->assertSee('QUOTE NO:')
            ->assertSee('Address');

        $this->actingAsAdmin()->get(route('invoices.print', $sale))
            ->assertOk()
            ->assertSee('INVOICE')
            ->assertSee('BILL TO:')
            ->assertSee('INVOICE NO:')
            ->assertSee('SIZE');
    }

    public function test_quotation_can_be_sent_by_email_and_whatsapp(): void
    {
        $this->customer->update([
            'email' => 'lewis@example.com',
            'phone' => '0712345678',
            'mobile' => null,
        ]);

        $this->actingAsAdmin()->post(route('quotations.store'), [
            'customer_id' => $this->customer->id,
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(14)->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 80,
                'tax_rate' => 0,
            ]],
        ])->assertRedirect();

        $quotation = Quotation::query()->latest('id')->first();
        $this->actingAsAdmin()->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee('WhatsApp')
            ->assertSee('Email')
            ->assertSee('wa.me/254712345678', false);

        Mail::fake();
        $this->actingAsAdmin()->post(route('quotations.email', $quotation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(Quotation::STATUS_SENT, $quotation->fresh()->status);
        Mail::assertSent(QuotationMail::class, function (QuotationMail $mail) {
            $mail->build();

            return $mail->hasTo('lewis@example.com')
                && $mail->hasFrom('test@sellix.local')
                && isset($mail->rawAttachments[0]['data'])
                && strpos($mail->rawAttachments[0]['data'], '%PDF') === 0;
        });

        $this->customer->update(['email' => null, 'phone' => null, 'mobile' => null]);
        $this->actingAsAdmin()->post(route('quotations.email', $quotation))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAsAdmin()->post(route('quotations.whatsapp', $quotation))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->get(route('quotations.share', $quotation))->assertForbidden();
        $share = $this->get(URL::temporarySignedRoute('quotations.share', now()->addDays(7), ['quotation' => $quotation->id]));
        $share->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $share->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $share->getContent());
    }
}
