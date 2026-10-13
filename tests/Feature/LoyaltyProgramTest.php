<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class LoyaltyProgramTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_pos_customer_menu_lists_name_phone_and_points(): void
    {
        $customer = $this->namedCustomer(105);

        $this->actingAsAdmin()->get(route('pos.index'))
            ->assertOk()
            ->assertSee('select2.full.js', false)
            ->assertSee($customer->name . ' - ' . $customer->phone . ' - 105 pts', false);
    }

    public function test_pos_page_renders_with_loyalty_rules(): void
    {
        $this->actingAsAdmin()
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('RECEIVE PAYMENT', false)
            ->assertSee('Pay with points', false)
            ->assertSee('Not enough stock', false);
    }

    public function test_a_held_sale_can_be_cancelled_from_the_hold_list(): void
    {
        $hold = $this->actingAsCashier()->postJson(route('pos.hold'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);
        $hold->assertOk();
        $id = $hold->json('id');

        $this->actingAsCashier()->get(route('pos.index'))
            ->assertOk()
            ->assertSee('sx-cancel-hold', false);

        $this->actingAsCashier()->getJson(route('pos.holds'))
            ->assertOk()
            ->assertJsonFragment(['id' => $id]);

        $cancel = $this->actingAsCashier()->postJson(route('pos.holds.cancel', $id));
        $cancel->assertOk();
        $this->assertEquals(0, $cancel->json('held_count'));
        $this->assertNull(Sale::query()->find($id));
        $this->assertEquals(100.0, (float) ProductBranchStock::query()->where('product_id', $this->product->id)->value('quantity'));
    }

    public function test_pos_refuses_a_quantity_above_store_stock(): void
    {
        ProductBranchStock::query()->where('product_id', $this->product->id)->update(['quantity' => 5]);

        $found = $this->actingAsAdmin()->getJson(route('pos.search', ['q' => $this->product->name]));
        $found->assertOk();
        $match = collect($found->json())->firstWhere('id', $this->product->id);
        $this->assertNotNull($match);
        $this->assertTrue($match['manage_stock']);
        $this->assertFalse($match['allow_negative_stock']);
        $this->assertEquals(5.0, (float) $match['qty']);

        $over = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 40],
            ],
            'payment_amount' => 4000,
            'payment_method' => 'cash',
        ]);

        $over->assertStatus(422);
        $this->assertStringContainsString('Insufficient stock', (string) $over->json('message'));
    }

    public function test_purchase_earns_points_and_a_later_sale_can_redeem_them(): void
    {
        $settings = app(SettingsService::class);
        $settings->set($this->company->id, 'loyalty_enabled', '1', 'loyalty');
        $settings->set($this->company->id, 'loyalty_earn_mode', 'spend', 'loyalty');
        $settings->set($this->company->id, 'loyalty_points_rate', '0.01', 'loyalty');
        $settings->set($this->company->id, 'loyalty_min_redeem', '100', 'loyalty');
        $settings->set($this->company->id, 'loyalty_point_value', '1', 'loyalty');
        $settings->set($this->company->id, 'loyalty_max_redeem', '0', 'loyalty');

        $customer = $this->namedCustomer(70);
        ProductBranchStock::query()->where('product_id', $this->product->id)->update(['quantity' => 200]);

        $first = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 50],
            ],
            'payment_amount' => 5000,
            'payment_method' => 'cash',
        ]);
        $first->assertOk();
        $this->assertEquals(120.0, (float) $customer->fresh()->loyalty_points);

        app(LoyaltyService::class)->earnForSale(Sale::query()->findOrFail($first->json('id')));
        $this->assertEquals(1, LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->where('type', LoyaltyTransaction::TYPE_EARN)
            ->count());

        $second = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
            'payment_amount' => 0,
            'payment_method' => 'cash',
            'loyalty_points' => 100,
        ]);
        $second->assertOk();

        $sale = Sale::query()->findOrFail($second->json('id'));
        $this->assertEquals(0.0, (float) $sale->total);
        $this->assertEquals(100.0, (float) $sale->loyalty_discount);
        $this->assertEquals(20.0, (float) $customer->fresh()->loyalty_points);

        $this->actingAsAdmin()->get(route('customers.loyalty.show', $customer))
            ->assertOk()
            ->assertSee('120.00')
            ->assertSee('Redeem');
    }

    public function test_points_reduce_a_larger_sale_and_the_customer_pays_the_rest(): void
    {
        $settings = app(SettingsService::class);
        $settings->set($this->company->id, 'loyalty_enabled', '1', 'loyalty');
        $settings->set($this->company->id, 'loyalty_point_value', '1', 'loyalty');
        $settings->set($this->company->id, 'loyalty_min_redeem', '0', 'loyalty');

        $customer = $this->namedCustomer(100);
        ProductBranchStock::query()->where('product_id', $this->product->id)->update(['quantity' => 20]);

        $response = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5],
            ],
            'payment_amount' => 400,
            'payment_method' => 'cash',
            'loyalty_points' => 100,
        ]);

        $response->assertOk();
        $sale = Sale::query()->findOrFail($response->json('id'));
        $this->assertEquals(100.0, (float) $sale->loyalty_discount);
        $this->assertEquals(400.0, (float) $sale->total);
        $this->assertEquals(400.0, (float) $sale->paid_amount);
        $this->assertEquals(0.0, (float) $sale->balance);
        $this->assertEquals(Sale::PAYMENT_PAID, $sale->payment_status);

        $this->actingAsAdmin()->get(route('sales.index'))
            ->assertOk()
            ->assertSee('Paid with points', false)
            ->assertSee('>Cash<', false)
            ->assertSee('M-Pesa', false)
            ->assertSee('Card', false)
            ->assertSee('Ksh 400.00', false)
            ->assertSee('100.00 pts', false)
            ->assertSee('Ksh 100.00 off', false);
    }

    public function test_redemption_below_the_minimum_is_rejected(): void
    {
        app(SettingsService::class)->set($this->company->id, 'loyalty_min_redeem', '100', 'loyalty');
        app(SettingsService::class)->set($this->company->id, 'loyalty_point_value', '1', 'loyalty');
        $customer = $this->namedCustomer(70);

        $response = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
            'payment_amount' => 100,
            'payment_method' => 'cash',
            'loyalty_points' => 70,
        ]);

        $response->assertStatus(422);
        $this->assertEquals(0, Sale::query()->where('customer_id', $customer->id)->count());
        $this->assertEquals(70.0, (float) $customer->fresh()->loyalty_points);
    }

    public function test_the_same_points_cannot_be_redeemed_twice(): void
    {
        app(SettingsService::class)->set($this->company->id, 'loyalty_min_redeem', '100', 'loyalty');
        app(SettingsService::class)->set($this->company->id, 'loyalty_point_value', '1', 'loyalty');
        $customer = $this->namedCustomer(100);

        $ok = $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 1, 100, true));
        $ok->assertOk();
        $this->assertEquals(0.0, (float) $customer->fresh()->loyalty_points);

        $again = $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 1, 100, true));
        $again->assertStatus(422);
        $this->assertEquals(1, Sale::query()->where('customer_id', $customer->id)->count());
    }

    public function test_unpaid_invoice_earns_points_only_after_it_is_paid(): void
    {
        $customer = $this->namedCustomer(0);
        $response = $this->actingAsAdmin()->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);
        $response->assertRedirect();
        $sale = Sale::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($sale);
        $this->assertEquals(0.0, (float) $customer->fresh()->loyalty_points);

        $this->actingAsAdmin()->post(route('sales.payments.store', $sale), [
            'amount' => (float) $sale->total,
            'method' => 'cash',
            'paid_at' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertEquals(round((float) $sale->fresh()->total * 0.01, 2), (float) $customer->fresh()->loyalty_points);
    }

    public function test_a_refunded_return_reverses_earned_points(): void
    {
        $customer = $this->namedCustomer(0);
        $order = $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 2, 200));
        $order->assertOk();
        $this->assertEquals(2.0, (float) $customer->fresh()->loyalty_points);

        $sale = Sale::query()->with('items')->findOrFail($order->json('id'));
        $this->actingAsAdmin()->post(route('sales.returns.store', $sale), [
            'receipt_ref' => $sale->number,
            'want_refund' => 1,
            'notes' => 'Customer changed their mind',
            'items' => [
                [
                    'sale_item_id' => $sale->items->first()->id,
                    'quantity' => 1,
                    'condition' => 'yes',
                ],
            ],
        ])->assertRedirect();

        $this->assertEquals(1.0, (float) $customer->fresh()->loyalty_points);
    }

    public function test_void_restores_redeemed_points(): void
    {
        app(SettingsService::class)->set($this->company->id, 'loyalty_min_redeem', '100', 'loyalty');
        app(SettingsService::class)->set($this->company->id, 'loyalty_point_value', '1', 'loyalty');
        $customer = $this->namedCustomer(100);
        $order = $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 1, 100, true));
        $order->assertOk();
        $this->assertEquals(0.0, (float) $customer->fresh()->loyalty_points);

        $sale = Sale::query()->findOrFail($order->json('id'));
        $this->actingAsAdmin()->post(route('sales.void', $sale), [
            'invoice_date' => now()->toDateString(),
            'receipt_ref' => $sale->number,
            'want_refund' => 0,
            'void_reason' => 'Wrong sale',
            'flagged' => 0,
        ])->assertRedirect();

        $this->assertEquals(100.0, (float) $customer->fresh()->loyalty_points);
    }

    public function test_item_and_selected_product_rules_change_the_points_awarded(): void
    {
        $other = Product::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Plain Soap',
            'sku' => 'SOAP-' . Str::upper(Str::random(4)),
            'purchase_price' => 20,
            'selling_price' => 100,
            'manage_stock' => true,
            'allow_negative_stock' => true,
            'is_active' => true,
            'for_sale' => true,
            'tax_inclusive' => true,
        ]);
        ProductBranchStock::query()->withoutGlobalScope('branch')->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'product_id' => $other->id,
            'product_variant_id' => 0,
            'quantity' => 0,
            'average_cost' => 20,
        ]);
        $settings = app(SettingsService::class);
        $settings->set($this->company->id, 'loyalty_earn_mode', 'item', 'loyalty');
        $settings->set($this->company->id, 'loyalty_points_per_item', '2', 'loyalty');
        $settings->set($this->company->id, 'loyalty_eligible', 'selected', 'loyalty');
        \App\Models\LoyaltyProduct::query()->create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        $customer = $this->namedCustomer(0);
        $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
                ['product_id' => $other->id, 'quantity' => 4],
            ],
            'payment_amount' => 700,
            'payment_method' => 'cash',
        ])->assertOk();

        $this->assertEquals(6.0, (float) $customer->fresh()->loyalty_points);
    }

    public function test_disabled_program_does_not_award_points(): void
    {
        app(SettingsService::class)->set($this->company->id, 'loyalty_enabled', '0', 'loyalty');
        $customer = $this->namedCustomer(0);
        $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 1, 100))->assertOk();
        $this->assertEquals(0.0, (float) $customer->fresh()->loyalty_points);
    }

    public function test_manual_adjustment_is_permission_controlled_and_audited(): void
    {
        $customer = $this->namedCustomer(10);

        $this->actingAsCashier()->post(route('customers.loyalty.adjust', $customer), [
            'direction' => 'award',
            'points' => 5,
            'reason' => 'Goodwill',
        ])->assertForbidden();

        $this->actingAsAdmin()->post(route('customers.loyalty.adjust', $customer), [
            'direction' => 'award',
            'points' => 5,
            'reason' => 'Goodwill',
        ])->assertRedirect();

        $this->assertEquals(15.0, (float) $customer->fresh()->loyalty_points);
        $this->assertTrue(\App\Models\AuditLog::query()
            ->where('module', 'loyalty')
            ->where('action', 'adjust')
            ->exists());

        $this->actingAsAdmin()->from(route('customers.loyalty.show', $customer))
            ->post(route('customers.loyalty.adjust', $customer), [
                'direction' => 'deduct',
                'points' => 100,
                'reason' => 'Too much',
            ])->assertRedirect()
            ->assertSessionHas('error');
        $this->assertEquals(15.0, (float) $customer->fresh()->loyalty_points);
    }

    public function test_points_expire_and_settings_round_trip(): void
    {
        app(SettingsService::class)->set($this->company->id, 'loyalty_expiry_days', '1', 'loyalty');
        $customer = $this->namedCustomer(0);
        $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 1, 100))->assertOk();
        $this->assertEquals(1.0, (float) $customer->fresh()->loyalty_points);

        \Carbon\Carbon::setTestNow(now()->addDays(2));
        $summary = app(LoyaltyService::class)->summary($customer->fresh());
        \Carbon\Carbon::setTestNow();
        $this->assertEquals(0.0, $summary['available']);
        $this->assertEquals(1.0, $summary['expired']);

        $this->actingAsAdmin()->put(route('settings.loyalty.update'), [
            'loyalty_enabled' => '1',
            'loyalty_earn_mode' => 'spend',
            'points_awarded' => 1,
            'spend_amount' => 100,
            'loyalty_points_per_item' => 0,
            'loyalty_min_redeem' => 100,
            'loyalty_point_value' => 1,
            'loyalty_max_redeem' => 50,
            'loyalty_eligible' => 'all',
            'loyalty_expiry_days' => 0,
        ])->assertRedirect(route('settings.loyalty'));

        $rules = app(LoyaltyService::class)->rules($this->company->id);
        $this->assertEquals(0.01, $rules['rate']);
        $this->assertEquals(100.0, $rules['min_redeem']);
        $this->assertEquals(50.0, $rules['max_redeem']);
    }

    public function test_receipt_prints_the_shop_details_from_receipt_settings(): void
    {
        $this->company->update([
            'name' => 'Skyblue Cosmetics',
            'address' => 'Shop No. 3, Posta, Ruiru',
            'phone' => '0740421824',
            'tax_pin' => 'P051234567X',
        ]);
        $settings = app(SettingsService::class);
        $settings->set($this->company->id, 'display_name', 'Yes', 'receipt');
        $settings->set($this->company->id, 'display_address', 'Yes', 'receipt');
        $settings->set($this->company->id, 'display_phone', 'Yes', 'receipt');
        $settings->set($this->company->id, 'display_vat_kra', 'Yes', 'receipt');
        $settings->set($this->company->id, 'receipt_heading', 'Original Cash Sale Receipt', 'receipt');
        $settings->set($this->company->id, 'till_paybill', '542542', 'receipt');
        $settings->set($this->company->id, 'till_account_no', '202331', 'receipt');
        $settings->set($this->company->id, 'display_logo', 'No', 'receipt');
        Storage::disk('public')->put('company-logos/receipt-logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->company->update(['logo_path' => 'company-logos/receipt-logo.png']);

        $customer = $this->namedCustomer(0);
        $response = $this->actingAsAdmin()->postJson(route('pos.order'), $this->salePayload($customer->id, 1, 100));
        $response->assertOk();

        $this->actingAsAdmin()->get(route('pos.receipt', $response->json('id')))
            ->assertOk()
            ->assertSee('Skyblue Cosmetics', false)
            ->assertSee('Shop No. 3, Posta, Ruiru', false)
            ->assertSee('TEL: 0740421824', false)
            ->assertSee('Original Cash Sale Receipt', false)
            ->assertSee('data:image/png;base64,', false)
            ->assertSee('Pin No: P051234567X', false)
            ->assertSee('Rct No:', false)
            ->assertSee('Date:', false)
            ->assertSee('Sold To:', false);

        $this->company->update(['tax_pin' => null]);
        $this->actingAsAdmin()->get(route('pos.receipt', $response->json('id')))
            ->assertOk()
            ->assertDontSee('Pin No:', false)
            ->assertSee('Rct No:', false)
            ->assertSee('Date:', false)
            ->assertSee('PAYBILL NO. 542542', false)
            ->assertSee('ACCOUNT NO. 202331', false)
            ->assertSee('#Pcs:', false)
            ->assertSee('Total 100.00', false)
            ->assertSee('Paymode', false)
            ->assertSee('Tendered', false)
            ->assertSee('Change', false)
            ->assertDontSee('Mpesa', false)
            ->assertDontSee('Card', false)
            ->assertDontSee('1.0', false);
    }

    private function namedCustomer(float $points): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'type' => Customer::TYPE_REGULAR,
            'name' => 'Amina ' . Str::upper(Str::random(4)),
            'phone' => '0712' . random_int(100000, 999999),
            'email' => Str::lower(Str::random(6)) . '@example.com',
            'is_walk_in' => false,
            'is_active' => true,
            'loyalty_points' => $points,
        ]);
    }

    private function salePayload(int $customerId, float $qty, float $pay, bool $redeem = false): array
    {
        return [
            'customer_id' => $customerId,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $qty],
            ],
            'payment_amount' => $redeem ? 0 : $pay,
            'payment_method' => 'cash',
            'loyalty_points' => $redeem ? 100 : 0,
        ];
    }
}
