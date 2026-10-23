<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class OfflineModeTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_snapshot_is_available_to_the_till_and_omits_buying_price(): void
    {
        $this->getJson(route('pos.offline-snapshot'))->assertUnauthorized();

        $response = $this->actingAsCashier()->getJson(route('pos.offline-snapshot'));
        $response->assertOk();
        $response->assertJsonPath('products.0.name', $this->product->name);
        $product = $response->json('products.0');
        $this->assertArrayNotHasKey('cost', $product);
        $this->assertArrayNotHasKey('purchase_price', $product);
        $this->assertSame($this->customer->id, $response->json('customers.0.id'));
    }

    public function test_ping_requires_a_signed_in_user(): void
    {
        $this->getJson(route('offline.ping'))->assertUnauthorized();
        $this->actingAsCashier()->getJson(route('offline.ping'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user_id', $this->cashier->id);
    }

    public function test_the_same_offline_sale_is_not_posted_twice(): void
    {
        $payload = array_merge($this->cartPayload(), [
            'offline_key' => 'offline-sale-key-001',
            'payments' => [
                ['method' => 'cash', 'amount' => 100],
            ],
        ]);

        $first = $this->actingAsAdmin()->postJson(route('pos.order'), $payload);
        $first->assertOk();
        $second = $this->actingAsAdmin()->postJson(route('pos.order'), $payload);
        $second->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Sale::query()->count());
        $this->assertEquals(99.0, $this->stockQty());
    }

    public function test_another_user_cannot_replay_an_offline_key(): void
    {
        $payload = array_merge($this->cartPayload(), [
            'offline_key' => 'offline-sale-key-002',
            'payments' => [
                ['method' => 'cash', 'amount' => 100],
            ],
        ]);

        $this->actingAsAdmin()->postJson(route('pos.order'), $payload)->assertOk();
        $this->actingAsCashier()->postJson(route('pos.order'), $payload)->assertForbidden();
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_a_repeated_hold_and_customer_keep_the_original_record(): void
    {
        $hold = array_merge($this->cartPayload(), ['offline_key' => 'offline-hold-key-001']);
        $first = $this->actingAsAdmin()->postJson(route('pos.hold'), $hold);
        $again = $this->actingAsAdmin()->postJson(route('pos.hold'), $hold);
        $first->assertOk();
        $again->assertOk();
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, Sale::query()->where('status', Sale::STATUS_HELD)->count());
        $this->assertEquals(100.0, $this->stockQty());

        $customer = ['name' => 'Amina', 'phone' => '0700000000', 'offline_key' => 'offline-customer-001'];
        $created = $this->actingAsAdmin()->postJson(route('pos.customers.store'), $customer);
        $created->assertOk();
        $this->actingAsAdmin()->postJson(route('pos.customers.store'), $customer)->assertOk()
            ->assertJsonPath('id', $created->json('id'));
        $this->assertSame(1, Customer::query()->where('name', 'Amina')->count());
    }

    public function test_a_held_sale_already_closed_on_the_server_is_not_posted_again(): void
    {
        $hold = $this->actingAsAdmin()->postJson(route('pos.hold'), $this->cartPayload());
        $hold->assertOk();

        $this->actingAsAdmin()->postJson(route('pos.order'), array_merge($this->cartPayload(), [
            'hold_id' => $hold->json('id'),
            'payments' => [
                ['method' => 'cash', 'amount' => 100],
            ],
        ]))->assertOk();

        $this->actingAsAdmin()->postJson(route('pos.order'), array_merge($this->cartPayload(), [
            'hold_id' => $hold->json('id'),
            'offline_key' => 'offline-sale-key-003',
            'payments' => [
                ['method' => 'cash', 'amount' => 100],
            ],
        ]))->assertStatus(422);

        $this->assertSame(1, Sale::query()->count());
        $this->assertEquals(99.0, $this->stockQty());
    }

    public function test_pos_page_loads_the_offline_client(): void
    {
        $this->actingAsCashier()->get(route('pos.index'))
            ->assertOk()
            ->assertSee('sellix-offline.js', false)
            ->assertSee('Saved on this device', false);
    }

    private function cartPayload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ];
    }
}
