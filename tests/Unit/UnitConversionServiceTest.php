<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Services\UnitConversionService;
use Tests\TestCase;

class UnitConversionServiceTest extends TestCase
{
    public function test_bag_opening_converts_to_base_kg(): void
    {
        $svc = new UnitConversionService();
        $this->assertEquals(900.0, $svc->toBase(10, 90));
    }

    public function test_mixed_sale_deduction(): void
    {
        $svc = new UnitConversionService();
        $total = $svc->toBase(2, 90) + $svc->toBase(10, 1) + $svc->toBase(0.5, 1);
        $this->assertEquals(190.5, $total);
    }

    public function test_fractional_sale(): void
    {
        $svc = new UnitConversionService();
        $this->assertEquals(0.25, $svc->toBase(1, 0.25));
        $this->assertEquals(897.5, $svc->roundQty(900 - $svc->toBase(1, 0.5) - $svc->toBase(2, 1)));
    }

    public function test_profit_from_converted_cost(): void
    {
        $svc = new UnitConversionService();
        $product = new Product([
            'purchase_price' => 4500,
            'purchase_to_base' => 90,
            'selling_price' => 50,
        ]);
        $this->assertEquals(50.0, $svc->costPerBase($product));
        $this->assertEquals(100.0, $svc->costPerSellingUnit($product, 2));
        $profit = 120 - $svc->costPerSellingUnit($product, 2);
        $this->assertEquals(20.0, $profit);
    }

    public function test_stock_display_bags_and_kg(): void
    {
        $svc = new UnitConversionService();
        $kg = new Unit(['name' => 'Kilogram', 'short_name' => 'KG']);
        $bag = new Unit(['name' => 'Bag', 'short_name' => 'Bag']);
        $product = new Product(['purchase_to_base' => 90]);
        $product->setRelation('unit', $kg);
        $product->setRelation('purchaseUnit', $bag);
        $product->purchase_unit_id = 2;
        $product->unit_id = 1;

        $this->assertEquals('450 KG (5 Bag)', $svc->stockDisplay($product, 450, 'both'));
        $this->assertEquals('5 Bag + 5 KG', $svc->stockDisplay($product, 455, 'purchase'));
    }

    public function test_historical_sale_item_keeps_snapshot_factor(): void
    {
        $item = new SaleItem([
            'quantity' => 1,
            'quantity_base' => 90,
            'unit_factor' => 90,
            'unit_name' => 'Bag',
        ]);
        $this->assertEquals(90.0, $item->baseQuantity());
        $item->unit_factor = 100;
        $this->assertEquals(90.0, $item->baseQuantity());
    }

    public function test_automatic_kg_price_from_bag(): void
    {
        $svc = new UnitConversionService();
        $product = new Product([
            'selling_price' => 4500,
            'wholesale_price' => 3600,
            'promo_price' => 900,
            'purchase_to_base' => 90,
            'purchase_unit_id' => 2,
            'unit_id' => 1,
            'unit_pricing_mode' => 'automatic',
        ]);
        $this->assertEquals(50.0, $svc->pricePerBase($product));
        $this->assertEquals(40.0, $svc->wholesalePerBase($product));
        $this->assertEquals(10.0, $svc->promoPerBase($product));
        $this->assertEquals(900.0, $svc->promoForFactor($product, 90));
        $this->assertEquals(50.0, $svc->pricePerBase($product) * 1);
        $this->assertEquals(1000.0, round($svc->pricePerBase($product) * 20, 2));
        $this->assertEquals(25.0, round($svc->pricePerBase($product) * 0.5, 2));
    }

    public function test_override_base_price(): void
    {
        $svc = new UnitConversionService();
        $product = new Product([
            'selling_price' => 4500,
            'wholesale_price' => 3600,
            'purchase_to_base' => 90,
            'purchase_unit_id' => 2,
            'unit_id' => 1,
            'base_selling_price' => 55,
            'base_wholesale_price' => 42,
        ]);
        $this->assertEquals(55.0, $svc->pricePerBase($product));
        $this->assertEquals(42.0, $svc->wholesalePerBase($product));
        $this->assertEquals(3600.0, $svc->wholesaleForFactor($product, 90));
        $this->assertEquals(42.0, $svc->wholesaleForFactor($product, 1));
    }
}
