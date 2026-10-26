<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\ExpenseCategory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class DemoTourTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_demo_access_is_isolated_from_a_real_business(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Try live demo');
        $this->post(route('demo.tour'), ['action' => 'sync'])->assertRedirect(route('login'));

        $this->post(route('demo.enter'))->assertRedirect(route('dashboard'));
        $demoId = (int) auth()->user()->company_id;
        $this->assertNotSame($this->company->id, $demoId);
        $this->assertTrue((bool) auth()->user()->company->is_demo);
        $this->assertFalse(auth()->user()->isSystemOwner());

        $this->get(route('products.show', $this->product))
            ->assertForbidden()
            ->assertDontSee('Test Widget');
        $this->get(route('owner.dashboard'))->assertForbidden();
        $this->get(route('settings.backup'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();

        $this->assertSame(0, Product::query()->withoutGlobalScope('company')->where('company_id', $demoId)->where('name', $this->product->name)->count());
    }

    public function test_welcome_choice_and_progress_survive_a_refresh(): void
    {
        $this->post(route('demo.enter'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Welcome to Your Interactive POS Demo!')
            ->assertSee('Start Guided Tour')
            ->assertSee('Explore on My Own')
            ->assertSee('sellix-tour.css', false);
        $this->assertStringContainsString('max-width: 768px', file_get_contents(public_path('css/sellix-tour.css')));

        $this->postJson(route('demo.tour'), ['action' => 'choose', 'mode' => 'explore'])
            ->assertOk()
            ->assertJsonPath('mode', 'explore');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Welcome to Your Interactive POS Demo!')
            ->assertSee('Start guided tour');

        $this->postJson(route('demo.tour'), ['action' => 'choose', 'mode' => 'guided'])
            ->assertOk()
            ->assertJsonPath('step.key', 'dashboard');

        $this->postJson(route('demo.tour'), ['action' => 'next', 'route' => 'dashboard'])
            ->assertOk()
            ->assertJsonPath('step.key', 'business');

        $this->get(route('settings.company'))
            ->assertOk()
            ->assertSee('Set Up Your Business');
    }

    public function test_guided_actions_use_the_real_sale_purchase_and_stock_flow(): void
    {
        $this->post(route('demo.enter'));
        $companyId = (int) auth()->user()->company_id;
        $branchId = (int) auth()->user()->branch_id;

        $this->postJson(route('demo.tour'), ['action' => 'choose', 'mode' => 'guided']);
        $this->postJson(route('demo.tour'), ['action' => 'next']);
        $this->postJson(route('demo.tour'), ['action' => 'next'])->assertJsonPath('step.key', 'category');

        $this->post(route('categories.store'), [
            'name' => 'Tour Snacks',
            'branch_id' => $branchId,
            'show_on_pos' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNotNull(ProductCategory::query()->where('company_id', $companyId)->where('name', 'Tour Snacks')->first());
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'product');

        $category = ProductCategory::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('name', 'Tour Snacks')->first();
        $unit = Unit::query()->withoutGlobalScope('company')->where('company_id', $companyId)->first();
        $tax = Tax::query()->withoutGlobalScope('company')->where('company_id', $companyId)->first();
        $this->post(route('products.store'), [
            'name' => 'Tour Biscuits',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'purchase_price' => 40,
            'selling_price' => 70,
            'manage_stock' => 1,
            'for_sale' => 1,
            'opening_stock' => 4,
        ])->assertRedirect(route('products.index'));
        $product = Product::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('name', 'Tour Biscuits')->firstOrFail();
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'stock');

        $this->get(route('products.index'))->assertOk()->assertSee('Tour Biscuits');
        $this->postJson(route('demo.tour'), ['action' => 'seen', 'route' => 'products.index'])
            ->assertJsonPath('step.key', 'purchase');

        $beforePurchase = $this->onHand($companyId, $branchId, $product->id);
        $supplier = Supplier::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Fresh Farms Demo')->firstOrFail();
        $this->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'status' => 'received',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => 40,
                'tax_rate' => 0,
            ]],
        ])->assertRedirect();
        $this->assertSame(1, PurchaseOrder::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'received')->count());
        $this->assertEquals($beforePurchase + 3, $this->onHand($companyId, $branchId, $product->id));
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'customer');

        $this->post(route('customers.store'), [
            'branch_id' => $branchId,
            'name' => 'Tour Customer',
            'phone' => '0700111222',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull(Customer::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('name', 'Tour Customer')->first());
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'sale');

        Sale::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'customer_id' => Customer::query()->where('company_id', $companyId)->where('is_walk_in', true)->value('id'),
            'user_id' => auth()->id(),
            'document_type' => 'pos',
            'number' => 'UNPAID-1',
            'sale_date' => now(),
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => Sale::PAYMENT_UNPAID,
            'subtotal' => 70,
            'total' => 70,
            'paid_amount' => 0,
            'balance' => 70,
        ]);
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'sale');

        $beforeSale = $this->onHand($companyId, $branchId, $product->id);
        $payload = [
            'customer_id' => Customer::query()->where('company_id', $companyId)->where('is_walk_in', true)->value('id'),
            'sale_date' => now()->toDateString(),
            'document_type' => 'pos',
            'offline_key' => 'demo-sale-once',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 70,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => 70,
            ]],
        ];
        $sale = $this->postJson(route('pos.order'), $payload);
        $sale->assertOk();
        $this->postJson(route('pos.order'), $payload)->assertOk()->assertJsonPath('id', $sale->json('id'));
        $this->assertSame(1, Sale::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('payment_status', Sale::PAYMENT_PAID)->where('number', 'not like', 'DEMO-%')->count());
        $this->assertEquals($beforeSale - 1, $this->onHand($companyId, $branchId, $product->id));
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'payments');

        $this->get(route('sales.show', $sale->json('id')))
            ->assertOk()
            ->assertSee('Payment Information');
        $this->postJson(route('demo.tour'), ['action' => 'seen', 'route' => 'sales.show'])
            ->assertJsonPath('step.key', 'receipt');

        $this->get(route('pos.receipt', $sale->json('id')))
            ->assertOk()
            ->assertSee('Print Receipt')
            ->assertDontSee('printSlip', false);
        $this->postJson(route('demo.tour'), ['action' => 'seen', 'route' => 'pos.receipt'])
            ->assertJsonPath('step.key', 'expense');

        $rent = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('name', 'Rent')->first();
        $this->post(route('expenses.store'), [
            'entry_type' => 'direct',
            'expense_date' => now()->toDateString(),
            'expense_category_id' => $rent->id,
            'paying_account' => 'cash',
            'amount' => 150,
            'vat_type' => 'exempt',
            'notes' => 'Tour transport',
        ])->assertRedirect();
        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('step.key', 'reports');

        $this->get(route('reports.sales.sales', ['show' => 1]))
            ->assertOk()
            ->assertSee('Amina Demo');
        $this->postJson(route('demo.tour'), ['action' => 'seen', 'route' => 'reports.sales.sales'])
            ->assertJsonPath('step.key', 'discover');
        $this->postJson(route('demo.tour'), ['action' => 'next'])
            ->assertOk()
            ->assertJsonPath('finished', true);

        $this->postJson(route('demo.tour'), ['action' => 'sync'])->assertJsonPath('finished', true);
    }

    public function test_reset_clears_only_the_demo_business(): void
    {
        $this->post(route('demo.enter'));
        $companyId = (int) auth()->user()->company_id;
        $unit = Unit::query()->withoutGlobalScope('company')->where('company_id', $companyId)->first();
        $tax = Tax::query()->withoutGlobalScope('company')->where('company_id', $companyId)->first();
        $category = ProductCategory::query()->withoutGlobalScope('company')->where('company_id', $companyId)->first();
        $this->post(route('products.store'), [
            'name' => 'TEMP DEMO ITEM',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'purchase_price' => 10,
            'selling_price' => 20,
            'for_sale' => 1,
        ])->assertRedirect();

        $this->post(route('demo.reset'))->assertRedirect(route('dashboard'));

        $this->assertNull(Product::query()->withoutGlobalScope('company')->where('name', 'TEMP DEMO ITEM')->first());
        $this->assertNotNull(Product::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('sku', 'DEMO-MILK')->first());
        $this->assertNotNull(Product::query()->withoutGlobalScope('company')->where('company_id', $this->company->id)->where('name', 'Test Widget')->first());
        $this->assertSame(1, Company::query()->where('slug', 'sellix-live-demo')->count());
    }

    public function test_demo_mail_is_not_sent(): void
    {
        $this->post(route('demo.enter'));
        $sent = false;
        Event::listen(MessageSent::class, function () use (&$sent) {
            $sent = true;
        });
        Mail::raw('Demo should not leave the server', function ($message) {
            $message->to('nobody@example.test')->subject('Demo');
        });
        $this->assertFalse($sent);
    }

    private function onHand(int $companyId, int $branchId, int $productId): float
    {
        return (float) ProductBranchStock::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('product_variant_id', 0)
            ->value('quantity');
    }
}
