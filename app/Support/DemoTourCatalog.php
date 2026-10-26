<?php

namespace App\Support;

class DemoTourCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function steps(): array
    {
        return [
            [
                'key' => 'dashboard',
                'title' => 'Welcome to Your Dashboard',
                'body' => 'This is the business home screen. The cards show purchases, sales, expenses, customers, suppliers and items. Recent activity and shortcuts sit further down the page. Look around, then continue when you are ready. You do not need to record a transaction yet.',
                'route' => 'dashboard',
                'anchor' => '[data-tour="dashboard"]',
                'complete' => 'acknowledge',
                'pending' => 'Review the dashboard, then choose Next.',
            ],
            [
                'key' => 'business',
                'title' => 'Set Up Your Business',
                'body' => 'Business settings hold the name customers see on receipts, the address, and the currency. This demo already has sensible defaults, so you can review them and continue. Optional fields can stay as they are.',
                'route' => 'settings.company',
                'anchor' => '[data-tour="business-name"]',
                'complete' => 'acknowledge',
                'pending' => 'Open business settings, then choose Next. You do not have to change anything.',
            ],
            [
                'key' => 'category',
                'title' => 'Organize Your Products',
                'body' => 'Click the highlighted Add Category button. In the box that opens, type a name such as Snacks, then choose Submit. This guide stays clear of that button.',
                'route' => 'categories.index',
                'anchor' => '#sx-add-category',
                'form_anchor' => '#sx-cat-name',
                'complete' => 'category',
                'pending' => 'Save a new category before this step can continue. You can also skip it.',
            ],
            [
                'key' => 'product',
                'title' => 'Add a Product to Your Inventory',
                'body' => 'Click the highlighted New Item button. On the form, enter a name, category, purchase cost and selling price, then choose Save at the bottom. Variations are optional.',
                'route' => 'products.index',
                'anchor' => '[data-tour="new-item"]',
                'form_route' => 'products.create',
                'form_anchor' => '[data-tour="product-name"]',
                'complete' => 'product',
                'pending' => 'Save a new product before this step can continue. You can also skip it.',
            ],
            [
                'key' => 'stock',
                'title' => 'Check Your Stock',
                'body' => 'The item list shows what you sell and how much is on hand. Find the product you just added. Sales reduce this quantity. Purchases and opening stock increase it.',
                'route' => 'products.index',
                'anchor' => '#items-table',
                'complete' => 'visit',
                'pending' => 'Open the item list, then choose Next.',
            ],
            [
                'key' => 'purchase',
                'title' => 'Restock Your Products',
                'body' => 'Click New Purchase. Choose the supplier, add a product and quantity, set Status to Received, then choose Save. Pending or ordered does not add stock. Save stays clear of this guide.',
                'route' => 'purchases.index',
                'anchor' => '[data-tour="new-purchase"]',
                'form_route' => 'purchases.create',
                'form_anchor' => '#sx-po-supplier',
                'complete' => 'purchase',
                'pending' => 'Save a purchase with status Received. A draft or pending order does not finish this step.',
            ],
            [
                'key' => 'customer',
                'title' => 'Manage Your Customers',
                'body' => 'Click New Customer and save a name, or choose Skip step and sell to the walk-in customer already in the demo.',
                'route' => 'customers.index',
                'anchor' => '[data-tour="new-customer"]',
                'form_route' => 'customers.create',
                'form_anchor' => '[data-tour="customer-name"]',
                'complete' => 'customer',
                'pending' => 'Save a customer, or choose Skip step to sell to the walk-in customer.',
            ],
            [
                'key' => 'sale',
                'title' => 'Sell a Product',
                'body' => 'Click the highlighted search box, find a product and add it to the cart. Check the total, choose a payment, and press Complete transaction. That button stays clear of this guide. An unpaid sale stays unpaid.',
                'route' => 'pos.index',
                'anchor' => '#sx-pos-search',
                'complete' => 'sale',
                'pending' => 'Complete a paid sale on the till. The tour will not mark an unpaid sale as paid.',
            ],
            [
                'key' => 'payments',
                'title' => 'Understand Your Payments',
                'body' => 'Click the highlighted sale number. The payment section shows whether it is paid, partial, or unpaid. Another payment is optional and is never added for you.',
                'route' => 'sales.index',
                'anchor' => '[data-tour="open-sale"]',
                'form_anchor' => '[data-tour="sale-payments"]',
                'stay' => ['sales.show'],
                'complete' => 'visit',
                'pending' => 'Open a completed sale and review its payment status, then choose Next.',
                'also' => ['sales.show'],
            ],
            [
                'key' => 'receipt',
                'title' => 'Give Your Customer a Receipt',
                'body' => 'Click View receipt. You can print it yourself with the print button. The demo does not email the receipt or start a printer on its own.',
                'route' => 'sales.index',
                'anchor' => '[data-tour="open-sale"]',
                'form_anchor' => '[data-tour="receipt"]',
                'stay' => ['sales.show'],
                'complete' => 'visit',
                'pending' => 'Open the receipt for your sale, then choose Next.',
                'also' => ['pos.receipt', 'sales.print'],
            ],
            [
                'key' => 'expense',
                'title' => 'Track Your Business Expenses',
                'body' => 'Click Add Bill/Expense. Enter an amount and choose Save, or skip this step. Save stays clear of this guide.',
                'route' => 'expenses.index',
                'anchor' => '[data-tour="new-expense"]',
                'form_route' => 'expenses.create',
                'form_anchor' => '[data-tour="expense-amount"]',
                'complete' => 'expense',
                'pending' => 'Save an expense, or choose Skip step.',
            ],
            [
                'key' => 'reports',
                'title' => 'Understand Your Business Performance',
                'body' => 'Click the highlighted Show button. The report lists the sales recorded in this demo and uses the same totals as a live business.',
                'route' => 'reports.sales.sales',
                'anchor' => '[data-tour="sales-report-show"]',
                'complete' => 'visit',
                'pending' => 'Open the sales report, then choose Next.',
            ],
            [
                'key' => 'discover',
                'title' => 'Discover More Features',
                'body' => 'These modules are available in this demo. Open any of them, look around, then come back and continue. Pages you are not allowed to use are not listed.',
                'route' => 'dashboard',
                'anchor' => '[data-tour="dashboard"]',
                'complete' => 'acknowledge',
                'pending' => 'Explore a module if you want, then choose Next.',
                'links' => [
                    ['route' => 'suppliers.index', 'permission' => 'suppliers.view', 'label' => 'Suppliers'],
                    ['route' => 'sales.returns', 'permission' => 'sales.return', 'label' => 'Sale returns'],
                    ['route' => 'stock.transfers.index', 'permission' => 'inventory.transfer', 'label' => 'Stock transfers'],
                    ['route' => 'settings.branches', 'permission' => 'branches.view', 'label' => 'Branches'],
                    ['route' => 'users.index', 'permission' => 'users.view', 'label' => 'Staff'],
                    ['route' => 'customers.index', 'permission' => 'customers.view', 'label' => 'Customers'],
                    ['route' => 'reports.stock.stock', 'permission' => 'reports.stock', 'label' => 'Stock report'],
                    ['route' => 'reports.purchases.purchase', 'permission' => 'reports.purchases', 'label' => 'Purchase report'],
                    ['route' => 'reports.expenses', 'permission' => 'reports.expenses', 'label' => 'Expense report'],
                ],
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        foreach (self::steps() as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }
}
