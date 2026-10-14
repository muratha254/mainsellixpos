<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class CashierOwnSalesTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_cashier_dashboard_and_sales_list_show_only_that_cashiers_sales(): void
    {
        $this->makeSale($this->cashier->id, 'SL-CASH-1', 80, 80, Sale::PAYMENT_PAID);
        $this->makeSale($this->cashier->id, 'SL-CASH-DUE', 30, 0, Sale::PAYMENT_UNPAID);
        $other = $this->makeSale($this->admin->id, 'SL-OTHER', 500, 300, Sale::PAYMENT_PARTIAL);

        $this->actingAsCashier()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('KSh 110.00', false)
            ->assertSee('KSh 30.00', false)
            ->assertDontSee('KSh 610.00', false)
            ->assertDontSee('KSh 230.00', false)
            ->assertDontSee('SL-OTHER', false);

        $this->actingAsCashier()
            ->get(route('sales.index', ['period' => 'today']))
            ->assertOk()
            ->assertSee('SL-CASH-1')
            ->assertSee('SL-CASH-DUE')
            ->assertDontSee('SL-OTHER');

        $this->actingAsCashier()
            ->get(route('sales.show', $other))
            ->assertForbidden();

        $this->actingAsAdmin()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('KSh 610.00', false)
            ->assertSee('KSh 230.00', false);
    }

    private function makeSale(int $userId, string $number, float $total, float $paid, string $paymentStatus): Sale
    {
        return Sale::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $userId,
            'number' => $number,
            'status' => Sale::STATUS_COMPLETED,
            'payment_status' => $paymentStatus,
            'sale_date' => Carbon::now(),
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'paid_amount' => $paid,
            'balance' => round($total - $paid, 2),
        ]);
    }
}
