<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
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
