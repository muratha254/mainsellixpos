<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DemoTour;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\ProductCategory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\SubscriptionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DemoBusinessService
{
    public const SLUG = 'sellix-live-demo';

    public function __construct(
        protected CompanyProvisioner $provisioner,
        protected InventoryService $inventory
    ) {
    }

    public function ensure(): array
    {
        $company = Company::query()->where('slug', self::SLUG)->first();
        if ($company && ! $company->is_demo) {
            $company->update(['is_demo' => true]);
        }

        if (! $company) {
            $password = Str::random(40);
            $provisioned = $this->provisioner->provision([
                'name' => 'Sellix Demo Shop',
                'owner_name' => 'Demo Manager',
                'slug' => self::SLUG,
                'email' => 'demo@sellix.invalid',
                'phone' => '0700000000',
                'address' => 'Demo Lane, Nairobi',
                'city' => 'Nairobi',
                'country' => 'Kenya',
                'is_active' => true,
            ], [
                'name' => 'Demo Manager',
                'email' => 'demo@sellix.invalid',
                'username' => 'sellix.demo',
                'password' => $password,
            ]);
            $company = $provisioned['company'];
            $company->update(['is_demo' => true]);
            $this->subscribe($company);
            $this->seedSamples($company, $provisioned['branch'], $provisioned['admin']);

            return $provisioned;
        }

        $company->update(['is_active' => true, 'is_demo' => true]);
        $this->subscribe($company);
        $admin = User::query()->where('company_id', $company->id)->where('email', 'demo@sellix.invalid')->first();
        $branch = $company->branches()->where('is_active', true)->orderByDesc('is_default')->first();
        if (! $admin || ! $branch) {
            throw new \RuntimeException('The demo business is missing its user or branch.');
        }
        if (! Product::query()->withoutGlobalScope('company')->where('company_id', $company->id)->where('sku', 'DEMO-MILK')->exists()) {
            $this->seedSamples($company, $branch, $admin);
        }

        return compact('company', 'branch', 'admin');
    }

    public function reset(Company $company): void
    {
        abort_unless($company->is_demo && $company->slug === self::SLUG, 403);

        $admin = User::query()->where('company_id', $company->id)->where('email', 'demo@sellix.invalid')->firstOrFail();
        $branch = $company->branches()->where('is_active', true)->orderByDesc('is_default')->firstOrFail();

        DB::transaction(function () use ($company, $admin, $branch) {
            $this->wipe($company->id);
            DemoTour::query()->where('company_id', $company->id)->delete();
            $this->seedSamples($company, $branch, $admin);
        });
    }

    protected function subscribe(Company $company): void
    {
        $plan = SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'description' => 'All modules',
                'price' => 0,
                'billing_period' => SubscriptionCatalog::PERIOD_ANNUALLY,
                'max_users' => null,
                'max_branches' => null,
                'features' => SubscriptionCatalog::allFeatureKeys(),
                'is_active' => true,
                'sort_order' => 99,
            ]
        );

        Subscription::query()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'subscription_plan_id' => $plan->id,
                'status' => SubscriptionCatalog::STATUS_ACTIVE,
                'starts_at' => now()->toDateString(),
                'expires_at' => now()->addYears(10)->toDateString(),
            ]
        );
    }

    protected function seedSamples(Company $company, $branch, User $admin): void
    {
        $previous = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        $previousBranch = app()->bound('currentBranchId') ? app('currentBranchId') : null;
        app()->instance('currentCompanyId', $company->id);
        app()->instance('currentBranchId', $branch->id);

        try {
            Customer::query()->firstOrCreate(
                ['company_id' => $company->id, 'is_walk_in' => true],
                ['type' => Customer::TYPE_WALK_IN, 'name' => 'Walk-in Customer', 'is_active' => true]
            );

            $groceries = ProductCategory::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Groceries'],
                ['show_on_pos' => true, 'is_active' => true, 'sort_order' => 2]
            );
            ProductCategory::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Household'],
                ['show_on_pos' => true, 'is_active' => true, 'sort_order' => 3]
            );

            $unit = Unit::query()->where('company_id', $company->id)->orderBy('id')->first();
            $milk = Product::query()->firstOrCreate(
                ['company_id' => $company->id, 'sku' => 'DEMO-MILK'],
                [
                    'category_id' => $groceries->id,
                    'unit_id' => optional($unit)->id,
                    'name' => 'Demo Fresh Milk 1L',
                    'barcode' => 'DEMO-MILK',
                    'purchase_price' => 80,
                    'selling_price' => 120,
                    'reorder_level' => 6,
                    'manage_stock' => true,
                    'for_sale' => true,
                    'is_active' => true,
                    'tax_inclusive' => true,
                ]
            );
            Product::query()->firstOrCreate(
                ['company_id' => $company->id, 'sku' => 'DEMO-BREAD'],
                [
                    'category_id' => $groceries->id,
                    'unit_id' => optional($unit)->id,
                    'name' => 'Demo Brown Bread',
                    'barcode' => 'DEMO-BREAD',
                    'purchase_price' => 50,
                    'selling_price' => 85,
                    'reorder_level' => 4,
                    'manage_stock' => true,
                    'for_sale' => true,
                    'is_active' => true,
                    'tax_inclusive' => true,
                ]
            );

            $this->inventory->apply([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'product_id' => $milk->id,
                'product_variant_id' => 0,
                'type' => StockMovement::OPENING,
                'quantity_in' => 24,
                'unit_cost' => 80,
                'user_id' => $admin->id,
                'notes' => 'Demo opening stock',
                'occurred_at' => now()->subDay(),
            ]);

            $customer = Customer::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Amina Demo'],
                ['type' => Customer::TYPE_REGULAR, 'phone' => '0711000000', 'is_active' => true, 'is_walk_in' => false]
            );
            Supplier::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Fresh Farms Demo'],
                ['branch_id' => $branch->id, 'phone' => '0722000000']
            );

            if (! Sale::query()->where('company_id', $company->id)->where('number', 'DEMO-1001')->exists()) {
                $sale = Sale::query()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'user_id' => $admin->id,
                    'document_type' => Sale::TYPE_POS,
                    'number' => 'DEMO-1001',
                    'invoice_number' => 'DEMO-1001',
                    'receipt_number' => 'DEMO-1001',
                    'sale_date' => now(),
                    'status' => Sale::STATUS_COMPLETED,
                    'payment_status' => Sale::PAYMENT_PAID,
                    'subtotal' => 240,
                    'tax_amount' => 0,
                    'total' => 240,
                    'paid_amount' => 240,
                    'balance' => 0,
                    'notes' => 'Sample demo sale',
                ]);
                SaleItem::query()->create([
                    'company_id' => $company->id,
                    'sale_id' => $sale->id,
                    'product_id' => $milk->id,
                    'product_variant_id' => 0,
                    'name' => $milk->name,
                    'sku' => $milk->sku,
                    'quantity' => 2,
                    'quantity_base' => 2,
                    'unit_price' => 120,
                    'cost_price' => 80,
                    'line_total' => 240,
                ]);
                Payment::query()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'user_id' => $admin->id,
                    'customer_id' => $customer->id,
                    'number' => 'DEMO-PAY-1001',
                    'payable_type' => Sale::class,
                    'payable_id' => $sale->id,
                    'method' => Payment::METHOD_CASH,
                    'amount' => 240,
                    'paid_at' => now(),
                    'notes' => 'Sample demo payment',
                ]);
                $this->inventory->apply([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'product_id' => $milk->id,
                    'product_variant_id' => 0,
                    'type' => StockMovement::POS_SALE,
                    'quantity_out' => 2,
                    'unit_cost' => 80,
                    'user_id' => $admin->id,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                    'notes' => 'Sample demo sale',
                    'occurred_at' => now(),
                ]);
            }

            $rent = ExpenseCategory::query()->where('company_id', $company->id)->where('name', 'Rent')->first();
            if ($rent && ! Expense::query()->where('company_id', $company->id)->where('notes', 'Sample demo rent')->exists()) {
                Expense::query()->create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'user_id' => $admin->id,
                    'expense_category_id' => $rent->id,
                    'entry_type' => Expense::TYPE_DIRECT,
                    'expense_date' => now()->toDateString(),
                    'number' => 'DEMO-EXP-1',
                    'paying_account' => 'cash',
                    'payment_method' => 'cash',
                    'amount' => 5000,
                    'paid_amount' => 5000,
                    'vat_type' => Expense::VAT_EXEMPT,
                    'vat_amount' => 0,
                    'notes' => 'Sample demo rent',
                    'description' => 'Sample demo rent',
                    'status' => Expense::STATUS_PAID,
                ]);
            }
        } finally {
            if ($previous) {
                app()->instance('currentCompanyId', $previous);
            }
            if ($previousBranch) {
                app()->instance('currentBranchId', $previousBranch);
            }
        }
    }

    protected function wipe(int $companyId): void
    {
        $tables = [
            'sale_return_items',
            'sale_returns',
            'sale_items',
            'payments',
            'sales',
            'purchase_order_items',
            'goods_receipt_items',
            'goods_receipts',
            'purchase_orders',
            'stock_movements',
            'product_branch_stocks',
            'product_variant_attribute_values',
            'product_variant_attributes',
            'product_variants',
            'expenses',
            'products',
            'customers',
            'suppliers',
            'product_categories',
        ];

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }
                DB::table($table)->where('company_id', $companyId)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        ProductBranchStock::query()->withoutGlobalScopes()->where('company_id', $companyId)->delete();
    }
}
