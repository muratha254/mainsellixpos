<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    protected DocumentNumberService $numbers;
    protected AccountingPoster $accounting;

    public function __construct(DocumentNumberService $numbers, AccountingPoster $accounting)
    {
        $this->numbers = $numbers;
        $this->accounting = $accounting;
    }

    /**
     * Apply a supplier payment FIFO against unpaid purchases, then opening balance.
     *
     * @return array{applied:float,payments:array<int,Payment>}
     */
    public function apply(
        Supplier $supplier,
        float $amount,
        string $method,
        $paidAt,
        ?string $reference = null,
        ?string $notes = null,
        $branchId = null
    ): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $due = $supplier->currentBalance();
        if ($due <= 0) {
            throw new \InvalidArgumentException('This supplier has no outstanding balance.');
        }
        if ($amount > $due + 0.009) {
            throw new \InvalidArgumentException('Payment cannot exceed the outstanding balance of Ksh ' . number_format($due, 2) . '.');
        }

        $companyId = (int) $supplier->company_id;
        $branchId = $branchId ?: ($supplier->branch_id ?: null);
        $number = $this->numbers->next($companyId, 'payment');
        $payments = [];

        DB::transaction(function () use (
            $supplier, $amount, $method, $paidAt, $reference, $notes,
            $companyId, $branchId, $number, &$payments
        ) {
            $remaining = $amount;
            $orders = PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_DRAFT])
                ->orderBy('order_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                if ($remaining <= 0) {
                    break;
                }

                $due = $order->balance();
                $pay = round(min($due, $remaining), 2);
                if ($pay <= 0) {
                    continue;
                }

                $order->update([
                    'paid_amount' => round((float) $order->paid_amount + $pay, 2),
                ]);

                $payment = Payment::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId ?: $order->branch_id,
                    'user_id' => auth()->id(),
                    'supplier_id' => $supplier->id,
                    'number' => $number,
                    'payable_type' => PurchaseOrder::class,
                    'payable_id' => $order->id,
                    'method' => $method,
                    'amount' => $pay,
                    'reference' => $reference,
                    'paid_at' => $paidAt,
                    'notes' => $notes,
                ]);

                $this->accounting->postPurchasePayment($order->fresh(), $payment);
                $payments[] = $payment;
                $remaining = round($remaining - $pay, 2);
            }

            if ($remaining > 0) {
                $supplier->refresh();
                $open = round(max(0, (float) $supplier->opening_balance), 2);
                $againstOpen = round(min($open, $remaining), 2);
                if ($againstOpen > 0) {
                    $supplier->decrement('opening_balance', $againstOpen);
                    $payment = Payment::query()->create([
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'user_id' => auth()->id(),
                        'supplier_id' => $supplier->id,
                        'number' => $number,
                        'payable_type' => Supplier::class,
                        'payable_id' => $supplier->id,
                        'method' => $method,
                        'amount' => $againstOpen,
                        'reference' => $reference,
                        'paid_at' => $paidAt,
                        'notes' => $notes,
                    ]);
                    $this->accounting->postSupplierOpeningPayment($supplier->fresh(), $payment);
                    $payments[] = $payment;
                }
            }
        });

        return [
            'applied' => $amount,
            'payments' => $payments,
        ];
    }
}
