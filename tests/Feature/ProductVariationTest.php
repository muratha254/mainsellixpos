<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\ProductVariationService;
use App\Services\StockReportsService;
use App\Services\UnitConversionService;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class ProductVariationTest extends TestCase
{
    use CreatesSellixWorld;

    private ProductCategory $category;

    private Tax $tax;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
        $this->category = ProductCategory::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Apparel',
            'is_active' => true,
        ]);
        $this->tax = Tax::query()->create([
            'company_id' => $this->company->id,
            'name' => 'None',
            'rate' => 0,
            'is_inclusive' => true,
            'is_active' => true,
        ]);
        $this->unit = Unit::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Piece',
            'short_name' => 'Pc',
            'is_active' => true,
        ]);
    }

    public function test_variation_screens_render_for_the_existing_item_form(): void
    {
        $this->actingAsAdmin()->get(route('products.create'))
            ->assertOk()
            ->assertSee('Has Variations', false);
        $this->actingAsAdmin()->get(route('products.edit', $this->product))
            ->assertOk()
            ->assertSee('id="sx-variation-builder"', false);
        $this->actingAsAdmin()->get(route('pos.index'))
            ->assertOk()
            ->assertSee('sx-pos-variant-modal', false);
        $this->actingAsAdmin()->get(route('purchases.create'))
            ->assertOk()
            ->assertSee('purchaseChoices', false);
        $this->actingAsAdmin()->get(route('stock.manager'))
            ->assertOk()
            ->assertSee('sx-adjust-variant', false);
    }

    public function test_item_without_variations_still_sells_from_the_parent_stock(): void
    {
        $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
            'payment_amount' => 100,
            'payment_method' => 'cash',
        ])->assertOk();

        $this->assertEquals(99.0, $this->stockQty());
        $this->assertFalse((bool) $this->product->fresh()->has_variants);
    }

    public function test_dynamic_variations_keep_their_own_stock_through_purchase_sale_return_and_adjust(): void
    {
        $product = $this->storeShirt([
            ['name' => 'Size', 'values' => ['Small', 'Medium']],
            ['name' => 'Colour', 'values' => ['Red', 'Blue']],
        ], [
            'colour=red|size=small' => ['sku' => 'SHIRT-SR', 'barcode' => 'SHIRT-RED-S', 'selling_price' => 1200, 'purchase_price' => 700, 'stock' => 5, 'reorder_level' => 2],
            'colour=blue|size=medium' => ['sku' => 'SHIRT-MB', 'barcode' => 'SHIRT-BLUE-M', 'selling_price' => 1300, 'stock' => 2, 'reorder_level' => 4],
        ]);

        $this->assertTrue((bool) $product->has_variants);
        $this->assertCount(4, $product->variants);
        $this->assertEquals(0.0, $this->variantQty($product->id, 0));

        $red = $product->variants->firstWhere('sku', 'SHIRT-SR');
        $blue = $product->variants->firstWhere('sku', 'SHIRT-MB');
        $this->assertEquals(5.0, $this->variantQty($product->id, $red->id));
        $this->assertEquals(2.0, $this->variantQty($product->id, $blue->id));
        $this->assertEquals(0.0, $this->variantQty($product->id, $red->id, $this->secondaryBranch->id));

        $supplier = Supplier::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Cloth House',
        ]);
        $this->actingAsAdmin()->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $red->id,
                'quantity' => 3,
                'unit_cost' => 700,
                'tax_rate' => 0,
            ]],
        ])->assertRedirect();
        $this->assertEquals(8.0, $this->variantQty($product->id, $red->id));
        $this->assertEquals(2.0, $this->variantQty($product->id, $blue->id));

        $sale = $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $red->id,
                'quantity' => 2,
                'unit_price' => 1200,
            ]],
            'payment_amount' => 2400,
            'payment_method' => 'cash',
        ]);
        $sale->assertOk();
        $this->assertEquals(6.0, $this->variantQty($product->id, $red->id));
        $this->assertEquals(2.0, $this->variantQty($product->id, $blue->id));

        $saleItem = Sale::query()->with('items')->findOrFail($sale->json('id'))->items->first();
        $this->assertSame($red->id, (int) $saleItem->product_variant_id);
        $this->assertStringContainsString('Small / Red', $saleItem->name);

        $this->actingAsAdmin()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            'payment_amount' => 100,
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $hit = app(UnitConversionService::class)->findByBarcode($this->company->id, 'SHIRT-RED-S');
        $this->assertSame($product->id, $hit['product_id']);
        $this->assertSame($red->id, $hit['product_variant_id']);

        $found = $this->actingAsAdmin()->getJson(route('pos.search', ['q' => 'SHIRT-RED-S']));
        $found->assertOk();
        $this->assertSame($red->id, (int) $found->json('0.scan_variant_id'));

        $this->actingAsAdmin()->post(route('stock.adjust', $product), [
            'branch_id' => $this->branch->id,
            'occurred_at' => now()->toDateString(),
            'status' => 'addition',
            'control_account' => 'migration_control',
            'quantity' => 1,
            'product_variant_id' => $red->id,
        ])->assertRedirect();
        $this->assertEquals(7.0, $this->variantQty($product->id, $red->id));

        $this->actingAsAdmin()->post(route('sales.credit-notes.store'), [
            'sale_id' => $sale->json('id'),
            'credit_date' => now()->toDateString(),
            'reason' => 'Too big',
            'restore_stock' => 1,
            'action' => 'posted',
            'items' => [
                ['sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ])->assertRedirect();
        $this->assertEquals(8.0, $this->variantQty($product->id, $red->id));
        $this->assertEquals(2.0, $this->variantQty($product->id, $blue->id));

        $alerts = app(StockReportsService::class)->alertRows($this->branch->id);
        $this->assertTrue($alerts->contains(fn ($row) => str_contains($row['item'], 'Medium / Blue')));
        $this->assertFalse($alerts->contains(fn ($row) => str_contains($row['item'], 'Small / Red')));
    }

    public function test_invalid_variation_input_is_rejected_and_existing_items_are_not_given_variations(): void
    {
        $this->actingAsAdmin()->post(route('products.store'), $this->productPayload([
            'has_variants' => '1',
            'variation_payload' => json_encode([
                'attributes' => [
                    ['name' => 'Size', 'values' => ['Small', 'Small']],
                ],
                'variations' => [],
            ]),
        ]))->assertSessionHasErrors('variation_payload');
        $this->assertNull(Product::query()->where('name', 'Campus Shirt')->first());

        $this->actingAsAdmin()->post(route('products.store'), $this->productPayload([
            'name' => 'Plain Shirt',
            'has_variants' => '0',
            'opening_stock' => 4,
        ]))->assertRedirect(route('products.index'));

        $plain = Product::query()->where('name', 'Plain Shirt')->firstOrFail();
        $this->assertFalse((bool) $plain->has_variants);
        $this->assertEquals(0, ProductVariant::query()->where('product_id', $plain->id)->count());
        $this->assertEquals(4.0, $this->variantQty($plain->id, 0));
        $this->assertEquals(100.0, $this->stockQty());
    }

    public function test_a_variation_with_stock_history_cannot_be_removed(): void
    {
        $product = $this->storeShirt([
            ['name' => 'Size', 'values' => ['Small']],
        ], [
            'size=small' => ['stock' => 3, 'sku' => 'ONLY-S'],
        ]);

        $this->actingAsAdmin()->put(route('products.update', $product), $this->productPayload([
            'name' => 'Campus Shirt',
            'has_variants' => '1',
            'variation_payload' => json_encode([
                'attributes' => [
                    ['name' => 'Size', 'values' => ['Large']],
                ],
                'variations' => [],
            ]),
        ]))->assertSessionHasErrors('variation_payload');

        $this->assertNotNull($product->fresh()->variants()->where('sku', 'ONLY-S')->first());
    }

    private function storeShirt(array $attributes, array $overrides): Product
    {
        $combos = app(ProductVariationService::class)->combinations($attributes);
        $variations = array_map(function (array $combo) use ($overrides) {
            return array_merge([
                'signature' => $combo['signature'],
                'name' => $combo['name'],
                'sku' => '',
                'barcode' => '',
                'selling_price' => '',
                'purchase_price' => '',
                'stock' => 0,
                'reorder_level' => '',
                'is_active' => true,
            ], $overrides[$combo['signature']] ?? []);
        }, $combos);

        $this->actingAsAdmin()->post(route('products.store'), $this->productPayload([
            'has_variants' => '1',
            'opening_stock' => 99,
            'variation_payload' => json_encode([
                'attributes' => $attributes,
                'variations' => $variations,
            ]),
        ]))->assertRedirect(route('products.index'));

        return Product::query()->where('name', 'Campus Shirt')->with('variants')->firstOrFail();
    }

    private function productPayload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Campus Shirt',
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'tax_id' => $this->tax->id,
            'purchase_price' => 500,
            'selling_price' => 1000,
            'manage_stock' => '1',
            'for_sale' => '1',
            'opening_stock' => 0,
        ], $extra);
    }

    private function variantQty(int $productId, int $variantId, ?int $branchId = null): float
    {
        $row = ProductBranchStock::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $branchId ?: $this->branch->id)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        return $row ? (float) $row->quantity : 0.0;
    }
}
