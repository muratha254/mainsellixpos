<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\StockMovement;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class PosDraftCartTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_a_cashier_cart_survives_and_stays_private(): void
    {
        $buyer = Customer::query()->create([
            'company_id' => $this->company->id,
            'type' => Customer::TYPE_REGULAR,
            'name' => 'Amina Pharmacy',
            'is_walk_in' => false,
            'is_active' => true,
        ]);

        $stockMoves = StockMovement::query()->count();
        $sales = Sale::query()->count();

        $saved = $this->actingAsCashier()->putJson(route('pos.draft.save'), [
            'base_revision' => 0,
            'customer_id' => $buyer->id,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'document_type' => 'pos',
            'items' => [
                $this->line('tablet', 2, 'base', 'Tablet'),
                $this->line('tablet', 1, 'base', 'Tablet'),
                $this->line('pack', 1, 'purchase', 'Pack'),
            ],
        ]);

        $saved->assertOk()->assertJsonPath('saved', true);
        $revision = (int) $saved->json('revision');
        $this->assertGreaterThan(0, $revision);
        $this->assertSame($stockMoves, StockMovement::query()->count());
        $this->assertSame($sales, Sale::query()->count());
        $this->assertEquals(100.0, $this->stockQty());

        $mine = $this->actingAsCashier()->getJson(route('pos.draft.show'));
        $mine->assertOk()
            ->assertJsonPath('revision', $revision)
            ->assertJsonPath('cart.customer_id', $buyer->id)
            ->assertJsonPath('cart.discount_type', 'percent')
            ->assertJsonPath('cart.discount_value', 10);
        $this->assertCount(2, $mine->json('cart.items'));
        $this->assertSame(1, (int) collect($mine->json('cart.items'))->firstWhere('line_key', 'tablet')['quantity']);
        $this->assertSame('purchase', collect($mine->json('cart.items'))->firstWhere('line_key', 'pack')['sell_as']);

        $this->actingAsAdmin()->getJson(route('pos.draft.show'))
            ->assertOk()
            ->assertJsonPath('cart', null);

        $updated = $this->actingAsCashier()->putJson(route('pos.draft.save'), [
            'base_revision' => $revision,
            'customer_id' => $buyer->id,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'items' => [
                $this->line('pack', 3, 'purchase', 'Pack'),
            ],
        ]);
        $updated->assertOk()->assertJsonPath('saved', true);
        $next = (int) $updated->json('revision');

        $this->actingAsCashier()->putJson(route('pos.draft.save'), [
            'base_revision' => $revision,
            'customer_id' => $buyer->id,
            'items' => [
                $this->line('pack', 9, 'purchase', 'Pack'),
            ],
        ])->assertOk()->assertJsonPath('stale', true);

        $this->actingAsCashier()->getJson(route('pos.draft.show'))
            ->assertJsonPath('revision', $next)
            ->assertJsonPath('cart.items.0.quantity', 3);

        $this->actingAsCashier()->deleteJson(route('pos.draft.clear'))
            ->assertOk()
            ->assertJsonPath('cleared', true);

        $this->actingAsCashier()->getJson(route('pos.draft.show'))
            ->assertOk()
            ->assertJsonPath('cart', null);
        $this->assertSame($sales, Sale::query()->count());
        $this->assertEquals(100.0, $this->stockQty());
    }

    public function test_completing_a_sale_clears_only_that_cashiers_cart(): void
    {
        $this->actingAsCashier()->putJson(route('pos.draft.save'), [
            'base_revision' => 0,
            'customer_id' => $this->customer->id,
            'items' => [
                $this->line('sale', 2, 'base', 'Piece'),
            ],
        ])->assertOk();

        $this->actingAsAdmin()->putJson(route('pos.draft.save'), [
            'base_revision' => 0,
            'customer_id' => $this->customer->id,
            'items' => [
                $this->line('admin', 1, 'base', 'Piece'),
            ],
        ])->assertOk();

        $this->actingAsCashier()->postJson(route('pos.order'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 100],
            ],
        ])->assertOk();

        $this->assertSame(1, Sale::query()->count());
        $this->assertEquals(99.0, $this->stockQty());
        $this->actingAsCashier()->getJson(route('pos.draft.show'))->assertJsonPath('cart', null);
        $this->actingAsAdmin()->getJson(route('pos.draft.show'))
            ->assertJsonPath('cart.items.0.line_key', 'admin');
    }

    public function test_the_till_page_loads_the_saved_cart_for_this_cashier(): void
    {
        $response = $this->actingAsCashier()->get(route('pos.index'));
        $response->assertOk();
        $this->assertStringContainsString(json_encode(route('pos.draft.show')), $response->getContent());

        $this->actingAsInventoryManager()->putJson(route('pos.draft.save'), [
            'base_revision' => 0,
            'items' => [
                $this->line('nope', 1, 'base', 'Piece'),
            ],
        ])->assertForbidden();
    }

    private function line(string $key, $qty, string $sellAs, string $unit): array
    {
        return [
            'id' => $this->product->id,
            'line_key' => $key,
            'product_variant_id' => 0,
            'sell_as' => $sellAs,
            'unit_name' => $unit,
            'unit_factor' => $sellAs === 'purchase' ? 10 : 1,
            'name' => 'Test Widget',
            'price' => 100,
            'quantity' => $qty,
            'type' => 'R',
        ];
    }
}
