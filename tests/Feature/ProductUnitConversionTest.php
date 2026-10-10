<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Services\UnitConversionService;
use Illuminate\Support\Str;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class ProductUnitConversionTest extends TestCase
{
    use CreatesSellixWorld;

    private Product $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
        $this->seedFeedProduct();
    }

    public function test_opening_ten_bags_is_nine_hundred_kg(): void
    {
        $this->assertEquals(900.0, $this->stockQty(null, $this->feed->id));
    }

    public function test_creating_item_converts_opening_bags_to_base_kg(): void
    {
        $category = \App\Models\ProductCategory::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Feeds',
            'is_active' => true,
        ]);
        $tax = \App\Models\Tax::query()->create([
            'company_id' => $this->company->id,
            'name' => 'VAT',
            'rate' => 16,
            'is_inclusive' => true,
            'is_active' => true,
        ]);
        $kg = Unit::query()->where('short_name', 'KG')->first();
        $bag = Unit::query()->where('short_name', 'Bag')->first();

        $this->actingAsAdmin()->post(route('products.store'), [
            'name' => 'Dairy Meal Create',
            'category_id' => $category->id,
            'unit_id' => $kg->id,
            'purchase_unit_id' => $bag->id,
            'purchase_to_base' => 90,
            'tax_id' => $tax->id,
            'purchase_price' => 3500,
            'selling_price' => 4500,
            'manage_stock' => '1',
            'for_sale' => '1',
            'opening_stock' => 10,
        ])->assertRedirect(route('products.index'));

        $created = Product::query()->where('name', 'Dairy Meal Create')->firstOrFail();
        $this->assertEquals(90.0, (float) $created->purchase_to_base);
        $this->assertEquals(900.0, $this->stockQty(null, $created->id));
    }

    public function test_edit_loads_opening_stock_and_unchanged_save_does_not_add_again(): void
    {
        $category = \App\Models\ProductCategory::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Feeds Edit',
            'is_active' => true,
        ]);
        $tax = \App\Models\Tax::query()->create([
            'company_id' => $this->company->id,
            'name' => 'VAT Edit',
            'rate' => 16,
            'is_inclusive' => true,
            'is_active' => true,
        ]);
        $kg = Unit::query()->where('short_name', 'KG')->first();
        $bag = Unit::query()->where('short_name', 'Bag')->first();
        $payload = [
            'name' => 'Dairy Meal Edit Stock',
            'category_id' => $category->id,
            'unit_id' => $kg->id,
            'purchase_unit_id' => $bag->id,
            'purchase_to_base' => 90,
            'tax_id' => $tax->id,
            'purchase_price' => 3500,
            'selling_price' => 4500,
            'wholesale_price' => 3600,
            'manage_stock' => '1',
            'for_sale' => '1',
            'opening_stock' => 10,
            'opening_stock_unit' => 'purchase',
        ];

        $this->actingAsAdmin()->post(route('products.store'), $payload)->assertRedirect(route('products.index'));
        $created = Product::query()->where('name', 'Dairy Meal Edit Stock')->firstOrFail();

        $edit = $this->actingAsAdmin()->get(route('products.edit', $created));
        $edit->assertOk();
        $this->assertMatchesRegularExpression('/id="opening_stock"[^>]*value="10"/', $edit->getContent());

        $this->actingAsAdmin()->put(route('products.update', $created), $payload)->assertRedirect(route('products.index'));
        $this->assertEquals(900.0, $this->stockQty(null, $created->id));

        $payload['opening_stock'] = 11;
        $this->actingAsAdmin()->put(route('products.update', $created), $payload)->assertRedirect(route('products.index'));
        $this->assertEquals(990.0, $this->stockQty(null, $created->id));
    }

    public function test_sell_ten_kg_is_five_hundred_and_deducts_ten(): void
    {
        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 10,
        ]], 500);
        $response->assertOk();
        $sale = Sale::query()->with('items')->findOrFail($response->json('id'));
        $this->assertEquals(500.0, (float) $sale->total);
        $this->assertEquals(890.0, $this->stockQty(null, $this->feed->id));
    }

    public function test_sell_twenty_kg_is_one_thousand(): void
    {
        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 20,
        ]], 1000);
        $response->assertOk();
        $this->assertEquals(1000.0, (float) Sale::query()->find($response->json('id'))->total);
        $this->assertEquals(880.0, $this->stockQty(null, $this->feed->id));
    }

    public function test_sell_half_kg(): void
    {
        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 0.5,
        ]], 25);
        $response->assertOk();
        $this->assertEquals(25.0, (float) Sale::query()->find($response->json('id'))->total);
        $this->assertEquals(899.5, $this->stockQty(null, $this->feed->id));
    }

    public function test_sell_two_bags_is_nine_thousand_and_deducts_one_hundred_eighty(): void
    {
        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'purchase',
            'quantity' => 2,
        ]], 9000);
        $response->assertOk();
        $this->assertEquals(9000.0, (float) Sale::query()->find($response->json('id'))->total);
        $this->assertEquals(720.0, $this->stockQty(null, $this->feed->id));
    }

    public function test_mixed_bags_and_any_kg_quantity(): void
    {
        $response = $this->posOrder([
            ['product_id' => $this->feed->id, 'sell_as' => 'purchase', 'quantity' => 2],
            ['product_id' => $this->feed->id, 'sell_as' => 'base', 'quantity' => 10],
            ['product_id' => $this->feed->id, 'sell_as' => 'base', 'quantity' => 0.5],
        ], 9525);
        $response->assertOk();
        $this->assertEquals(709.5, $this->stockQty(null, $this->feed->id));
    }

    public function test_insufficient_stock_is_rejected(): void
    {
        ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->where('product_id', $this->feed->id)
            ->update(['quantity' => 5]);

        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 10,
        ]], 500);

        $response->assertStatus(422);
        $this->assertEquals(5.0, $this->stockQty(null, $this->feed->id));
        $this->assertStringContainsString('Insufficient stock', $response->json('message'));
    }

    public function test_tenants_keep_separate_conversions(): void
    {
        $other = Company::query()->create([
            'name' => 'Other Co',
            'slug' => 'other-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        $svc = new UnitConversionService();
        $this->assertEquals(90.0, $svc->purchaseFactor($this->feed));
        $otherProduct = new Product([
            'company_id' => $other->id,
            'name' => 'Dairy Meal',
            'purchase_price' => 5000,
            'selling_price' => 6000,
            'purchase_to_base' => 100,
        ]);
        $this->assertEquals(50.0, $svc->pricePerBase($this->feed));
        $this->assertEquals(60.0, $svc->pricePerBase($otherProduct));
        $this->assertEquals(900.0, $svc->toBase(10, 90));
        $this->assertEquals(1000.0, $svc->toBase(10, 100));
    }

    public function test_historical_sale_keeps_original_conversion_after_product_change(): void
    {
        $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'purchase',
            'quantity' => 1,
        ]], 4500)->assertOk();
        $item = SaleItem::query()->latest('id')->first();
        $this->assertEquals(90.0, (float) $item->quantity_base);
        $this->assertEquals(90.0, (float) $item->unit_factor);

        $this->feed->update(['purchase_to_base' => 100]);
        $item->refresh();
        $this->assertEquals(90.0, $item->baseQuantity());
    }

    public function test_profit_uses_converted_cost_for_any_kg_qty(): void
    {
        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 20,
        ]], 1000);
        $response->assertOk();
        $item = Sale::query()->findOrFail($response->json('id'))->items()->first();
        $cost = (float) $item->cost_price * (float) $item->quantity;
        $this->assertEqualsWithDelta(20 * (3500 / 90), $cost, 0.01);
        $this->assertEqualsWithDelta(1000 - $cost, (float) $item->line_total - $cost, 0.01);
    }

    public function test_existing_simple_product_still_sells_one_to_one(): void
    {
        $response = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
            'payment_amount' => 200,
            'payment_method' => 'cash',
        ]);
        $response->assertOk();
        $this->assertEquals(98.0, $this->stockQty());
    }

    public function test_base_price_override(): void
    {
        $this->feed->update(['base_selling_price' => 55]);
        $response = $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 10,
        ]], 550);
        $response->assertOk();
        $this->assertEquals(550.0, (float) Sale::query()->find($response->json('id'))->total);
    }

    public function test_item_list_and_profile_show_sold_remaining_and_movement(): void
    {
        $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 10,
        ]], 500)->assertOk();

        $this->actingAsAdmin()
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Dairy Meal')
            ->assertSee('Stock')
            ->assertSee('Sold')
            ->assertSee('Remaining');

        $this->feed->update(['wholesale_price' => 3600, 'base_selling_price' => 50]);
        $this->actingAsAdmin()
            ->get(route('products.show', $this->feed))
            ->assertOk()
            ->assertSee('Dairy Meal')
            ->assertSee('Selling (Bag)')
            ->assertSee('Selling / KG')
            ->assertSee('4,500.00')
            ->assertSee('50.00')
            ->assertSee('Wholesale (Bag)')
            ->assertSee('Wholesale / KG')
            ->assertSee('3,600.00')
            ->assertSee('40.00')
            ->assertSee('Item movement')
            ->assertSee('POS sale')
            ->assertSee('890');
    }

    public function test_profit_and_loss_uses_sale_amount_not_double_counted(): void
    {
        $this->posOrder([[
            'product_id' => $this->feed->id,
            'sell_as' => 'base',
            'quantity' => 2,
        ]], 100)->assertOk();

        $this->feed->update(['purchase_to_base' => 100]);

        $report = app(\App\Services\AccountingLedger::class)->profitAndLoss(
            now()->toDateString(),
            now()->toDateString()
        );

        $sales = collect($report['income'])->firstWhere('name', 'Sales');
        $this->assertNotNull($sales);
        $this->assertEqualsWithDelta(100.0, (float) $sales['amount'], 0.02);
        $this->assertEqualsWithDelta(100.0, (float) $report['income_total'], 0.02);
        $this->assertEqualsWithDelta(round(2 * (3500 / 90), 2), (float) $report['cogs_total'], 0.02);
        $this->assertEqualsWithDelta(100 - round(2 * (3500 / 90), 2), (float) $report['gross_profit'], 0.02);
        $this->assertEqualsWithDelta((float) $report['gross_profit'], (float) $report['net'], 0.02);
        $this->assertNotEquals(186.21, round((float) $sales['amount'], 2));
    }

    private function posOrder(array $items, $payAmount = 50000)
    {
        return $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => $items,
            'payment_amount' => $payAmount,
            'payment_method' => 'cash',
        ]);
    }

    private function seedFeedProduct(): void
    {
        $kg = Unit::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Kilogram',
            'short_name' => 'KG',
            'is_active' => true,
        ]);
        $bag = Unit::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Bag',
            'short_name' => 'Bag',
            'is_active' => true,
        ]);

        $this->feed = Product::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Dairy Meal',
            'unit_id' => $kg->id,
            'purchase_unit_id' => $bag->id,
            'purchase_to_base' => 90,
            'unit_pricing_mode' => 'automatic',
            'purchase_price' => 3500,
            'selling_price' => 4500,
            'manage_stock' => true,
            'allow_negative_stock' => false,
            'is_active' => true,
            'for_sale' => true,
            'tax_inclusive' => true,
        ]);

        app(InventoryService::class)->apply([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->feed->id,
            'type' => 'opening',
            'quantity_in' => 900,
            'unit_cost' => 3500 / 90,
            'quantity_entered' => 10,
            'unit_name' => 'Bag',
            'unit_factor' => 90,
        ]);
    }
}
