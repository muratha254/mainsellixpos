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
                'body' => 'Categories group items for the till and for reports. Create one sample category, for example Snacks, using the category form on this page.',
                'route' => 'categories.index',
                'anchor' => '#sx-cat-name',
                'complete' => 'category',
                'pending' => 'Save a new category before this step can continue. You can also skip it.',
            ],
            [
                'key' => 'product',
                'title' => 'Add a Product to Your Inventory',
                'body' => 'A product needs a name, category, purchase cost, and selling price. Opening stock and a reorder level are optional. Variations are only needed when one item comes in sizes or colours. Save one sample product. The form checks the required fields for you.',
                'route' => 'products.create',
                'anchor' => '[data-tour="product-name"]',
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
                'body' => 'A purchase records stock you buy from a supplier. Choose the sample supplier, add a product and quantity, and set the status to Received to put the stock on hand. Pending or ordered saves the order without adding stock. Receiving the same purchase twice does not add the quantity twice.',
                'route' => 'purchases.create',
                'anchor' => '#sx-po-supplier',
                'complete' => 'purchase',
                'pending' => 'Save a purchase with status Received. A draft or pending order does not finish this step.',
            ],
            [
                'key' => 'customer',
                'title' => 'Manage Your Customers',
                'body' => 'Customer records keep sale history and balances. Create a sample customer, or skip this step and sell to the walk-in customer already in the demo.',
                'route' => 'customers.create',
                'anchor' => '[data-tour="customer-name"]',
                'complete' => 'customer',
                'pending' => 'Save a customer, or choose Skip step to sell to the walk-in customer.',
            ],
            [
                'key' => 'sale',
                'title' => 'Sell a Product',
                'body' => 'Search or scan a product, add it to the cart, and check the quantity and total. Choose a customer or leave the walk-in customer. Pick a payment method, enter the amount received, and complete the transaction. The sale is stored and the stock quantity is reduced only when the sale succeeds. An unpaid sale stays unpaid.',
                'route' => 'pos.index',
                'anchor' => '#sx-pos-search',
                'complete' => 'sale',
                'pending' => 'Complete a paid sale on the till. The tour will not mark an unpaid sale as paid.',
            ],
            [
                'key' => 'payments',
                'title' => 'Understand Your Payments',
                'body' => 'Open the sale you just completed. The payment section shows the method and whether the balance is paid, partial, or unpaid. Credit sales keep the remainder as a customer balance. Recording another payment is optional and is never invented for you.',
                'route' => 'sales.index',
                'anchor' => '[data-tour="sale-payments"]',
                'complete' => 'visit',
                'pending' => 'Open a completed sale and review its payment status, then choose Next.',
                'also' => ['sales.show'],
            ],
            [
                'key' => 'receipt',
                'title' => 'Give Your Customer a Receipt',
                'body' => 'Open the receipt for the sale. You can print it yourself with the print button. The demo does not send the receipt by email or start a printer on its own.',
                'route' => 'sales.index',
                'anchor' => '[data-tour="receipt"]',
                'complete' => 'visit',
                'pending' => 'Open the receipt for your sale, then choose Next.',
                'also' => ['pos.receipt', 'sales.print'],
            ],
            [
                'key' => 'expense',
                'title' => 'Track Your Business Expenses',
                'body' => 'Expenses such as rent, transport, or supplies are recorded here and appear in the expense report. Save a small sample expense, or skip this step.',
                'route' => 'expenses.create',
                'anchor' => '[data-tour="expense-amount"]',
                'complete' => 'expense',
                'pending' => 'Save an expense, or choose Skip step.',
            ],
            [
                'key' => 'reports',
                'title' => 'Understand Your Business Performance',
                'body' => 'Reports read the demo sales, stock, purchases, and expenses. Open the sales report and choose Show to see the transactions in this demo. The figures follow the same rules as a live business.',
                'route' => 'reports.sales.sales',
                'anchor' => '[data-tour="sales-report"]',
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
