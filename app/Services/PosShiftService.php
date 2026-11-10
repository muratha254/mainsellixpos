<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\PosShift;
use App\Models\PosShiftAdjustment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PosShiftService
{
    public function isExempt(User $user): bool
    {
        $company = $user->relationLoaded('company') ? $user->company : $user->company()->first();

        return $company && (bool) $company->is_demo;
    }

    public function findOpen(User $user): ?PosShift
    {
        return PosShift::query()
            ->where('user_id', $user->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->first();
    }

    public function requireForPos(User $user, ?int $branchId): ?PosShift
    {
        if ($this->isExempt($user)) {
            return null;
        }

        if (! $branchId) {
            throw new InvalidArgumentException('Select a branch before opening the till.');
        }

        $shift = $this->findOpen($user);
        if (! $shift) {
            throw new InvalidArgumentException('Open a shift and confirm the cash and M-Pesa balances before selling.');
        }

        if ((int) $shift->branch_id !== (int) $branchId) {
            throw new InvalidArgumentException('Your open shift is for another branch. Close it before selling here.');
        }

        return $shift;
    }

    public function open(User $user, int $branchId, float $cash, float $mpesa, ?string $remarks): PosShift
    {
        if ($cash < 0 || $mpesa < 0) {
            throw new InvalidArgumentException('Opening balances cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($user, $branchId, $cash, $mpesa, $remarks) {
                $existing = PosShift::query()
                    ->where('user_id', $user->id)
                    ->where('status', PosShift::STATUS_OPEN)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new InvalidArgumentException('You already have an open shift. Close it before opening another.');
                }

                return PosShift::query()->create([
                    'company_id' => $user->company_id,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'number' => app(DocumentNumberService::class)->next((int) $user->company_id, 'shift'),
                    'status' => PosShift::STATUS_OPEN,
                    'is_open' => 1,
                    'opened_at' => now(),
                    'opening_cash' => round($cash, 2),
                    'opening_mpesa' => round($mpesa, 2),
                    'opening_remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
                ]);
            });
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            if ($state === '23000' || $state === '23505') {
                throw new InvalidArgumentException('You already have an open shift. Close it before opening another.');
            }
            throw $e;
        }
    }

    public function lockOpenForSale(User $user, int $branchId): ?PosShift
    {
        if ($this->isExempt($user)) {
            return null;
        }

        $shift = PosShift::query()
            ->where('user_id', $user->id)
            ->where('status', PosShift::STATUS_OPEN)
            ->lockForUpdate()
            ->first();

        if (! $shift || (int) $shift->branch_id !== (int) $branchId) {
            throw new InvalidArgumentException('Open a shift and confirm the cash and M-Pesa balances before selling.');
        }

        return $shift;
    }

    /**
     * @return array<string, float|null>
     */
    public function figures(PosShift $shift): array
    {
        $sales = Sale::query()
            ->withoutGlobalScope('branch')
            ->where('pos_shift_id', $shift->id)
            ->whereNotIn('status', [Sale::STATUS_HELD, Sale::STATUS_DRAFT])
            ->with([
                'payments' => function ($query) {
                    $query->withoutGlobalScope('branch');
                },
                'returns' => function ($query) {
                    $query->withoutGlobalScope('branch');
                },
            ])
            ->get();

        $buckets = [
            'cash' => 0.0,
            'mpesa' => 0.0,
            'card' => 0.0,
            'bank_transfer' => 0.0,
            'other' => 0.0,
        ];
        $refunds = ['cash' => 0.0, 'mpesa' => 0.0];

        foreach ($sales as $sale) {
            if ($sale->status === Sale::STATUS_VOIDED && $sale->void_refund) {
                continue;
            }

            foreach ($this->netReceived($sale) as $method => $amount) {
                $key = $this->bucket($method);
                $buckets[$key] += $amount;
            }

            if ($sale->status === Sale::STATUS_VOIDED) {
                continue;
            }

            foreach ($sale->returns as $return) {
                $method = strtolower((string) $return->refund_method);
                if (isset($refunds[$method])) {
                    $refunds[$method] += (float) $return->refund_amount;
                }
            }
        }

        $expenses = $this->expenses($shift);
        $expectedCash = round((float) $shift->opening_cash + $buckets['cash'] - $refunds['cash'] - $expenses['cash'], 2);
        $expectedMpesa = round((float) $shift->opening_mpesa + $buckets['mpesa'] - $refunds['mpesa'] - $expenses['mpesa'], 2);

        $actualCash = $shift->actual_cash !== null ? round((float) $shift->actual_cash, 2) : null;
        $actualMpesa = $shift->actual_mpesa !== null ? round((float) $shift->actual_mpesa, 2) : null;

        return [
            'opening_cash' => round((float) $shift->opening_cash, 2),
            'opening_mpesa' => round((float) $shift->opening_mpesa, 2),
            'opening_total' => round((float) $shift->opening_cash + (float) $shift->opening_mpesa, 2),
            'cash_sales' => round($buckets['cash'], 2),
            'mpesa_sales' => round($buckets['mpesa'], 2),
            'card_sales' => round($buckets['card'], 2),
            'bank_transfer_sales' => round($buckets['bank_transfer'], 2),
            'other_sales' => round($buckets['other'], 2),
            'cash_refunds' => round($refunds['cash'], 2),
            'mpesa_refunds' => round($refunds['mpesa'], 2),
            'cash_expenses' => round($expenses['cash'], 2),
            'mpesa_expenses' => round($expenses['mpesa'], 2),
            'expected_cash' => $expectedCash,
            'expected_mpesa' => $expectedMpesa,
            'expected_total' => round($expectedCash + $expectedMpesa, 2),
            'actual_cash' => $actualCash,
            'actual_mpesa' => $actualMpesa,
            'actual_total' => ($actualCash === null && $actualMpesa === null) ? null : round((float) $actualCash + (float) $actualMpesa, 2),
            'cash_variance' => $actualCash === null ? null : round($actualCash - $expectedCash, 2),
            'mpesa_variance' => $actualMpesa === null ? null : round($actualMpesa - $expectedMpesa, 2),
            'total_variance' => ($actualCash === null && $actualMpesa === null)
                ? null
                : round(($actualCash - $expectedCash) + ($actualMpesa - $expectedMpesa), 2),
        ];
    }

    /**
     * @return array<string, float|null>
     */
    public function report(PosShift $shift): array
    {
        if ($shift->isOpen() || $shift->expected_cash === null) {
            return $this->figures($shift);
        }

        $cashVariance = round((float) $shift->cash_variance, 2);
        $mpesaVariance = round((float) $shift->mpesa_variance, 2);

        return [
            'opening_cash' => round((float) $shift->opening_cash, 2),
            'opening_mpesa' => round((float) $shift->opening_mpesa, 2),
            'opening_total' => round((float) $shift->opening_cash + (float) $shift->opening_mpesa, 2),
            'cash_sales' => round((float) $shift->cash_sales, 2),
            'mpesa_sales' => round((float) $shift->mpesa_sales, 2),
            'card_sales' => round((float) $shift->card_sales, 2),
            'bank_transfer_sales' => round((float) $shift->bank_transfer_sales, 2),
            'other_sales' => round((float) $shift->other_sales, 2),
            'cash_refunds' => round((float) $shift->cash_refunds, 2),
            'mpesa_refunds' => round((float) $shift->mpesa_refunds, 2),
            'cash_expenses' => round((float) $shift->cash_expenses, 2),
            'mpesa_expenses' => round((float) $shift->mpesa_expenses, 2),
            'expected_cash' => round((float) $shift->expected_cash, 2),
            'expected_mpesa' => round((float) $shift->expected_mpesa, 2),
            'expected_total' => round((float) $shift->expected_cash + (float) $shift->expected_mpesa, 2),
            'actual_cash' => round((float) $shift->actual_cash, 2),
            'actual_mpesa' => round((float) $shift->actual_mpesa, 2),
            'actual_total' => round((float) $shift->actual_cash + (float) $shift->actual_mpesa, 2),
            'cash_variance' => $cashVariance,
            'mpesa_variance' => $mpesaVariance,
            'total_variance' => round($cashVariance + $mpesaVariance, 2),
        ];
    }

    public function close(PosShift $shift, User $user, float $actualCash, float $actualMpesa, ?string $remarks): PosShift
    {
        if ($actualCash < 0 || $actualMpesa < 0) {
            throw new InvalidArgumentException('Closing balances cannot be negative.');
        }

        return DB::transaction(function () use ($shift, $user, $actualCash, $actualMpesa, $remarks) {
            $locked = PosShift::query()->whereKey($shift->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isOpen()) {
                throw new InvalidArgumentException('This shift is already closed.');
            }
            if ((int) $locked->user_id !== (int) $user->id) {
                throw new InvalidArgumentException('Only the cashier who opened this shift can close it.');
            }

            $locked->actual_cash = round($actualCash, 2);
            $locked->actual_mpesa = round($actualMpesa, 2);
            $figures = $this->figures($locked);
            $needsApproval = abs((float) $figures['cash_variance']) >= 0.01 || abs((float) $figures['mpesa_variance']) >= 0.01;

            $locked->fill([
                'closing_remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'is_open' => null,
                'status' => $needsApproval ? PosShift::STATUS_PENDING : PosShift::STATUS_CLOSED,
                'cash_sales' => $figures['cash_sales'],
                'mpesa_sales' => $figures['mpesa_sales'],
                'card_sales' => $figures['card_sales'],
                'bank_transfer_sales' => $figures['bank_transfer_sales'],
                'other_sales' => $figures['other_sales'],
                'cash_refunds' => $figures['cash_refunds'],
                'mpesa_refunds' => $figures['mpesa_refunds'],
                'cash_expenses' => $figures['cash_expenses'],
                'mpesa_expenses' => $figures['mpesa_expenses'],
                'expected_cash' => $figures['expected_cash'],
                'expected_mpesa' => $figures['expected_mpesa'],
                'cash_variance' => $figures['cash_variance'],
                'mpesa_variance' => $figures['mpesa_variance'],
            ]);
            $locked->save();

            return $locked->fresh();
        });
    }

    public function approve(PosShift $shift, User $user, ?string $remarks): PosShift
    {
        return $this->review($shift, $user, PosShift::STATUS_APPROVED, $remarks, null);
    }

    public function reject(PosShift $shift, User $user, string $reason): PosShift
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to reject a variance.');
        }

        return $this->review($shift, $user, PosShift::STATUS_REJECTED, null, trim($reason));
    }

    public function adjust(PosShift $shift, User $user, float $cashAmount, float $mpesaAmount, string $reason): PosShiftAdjustment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for an adjustment.');
        }
        if ($shift->isOpen()) {
            throw new InvalidArgumentException('Close the shift before recording an adjustment.');
        }

        $originalCash = $shift->cash_variance;
        $originalMpesa = $shift->mpesa_variance;

        $row = PosShiftAdjustment::query()->create([
            'company_id' => $shift->company_id,
            'pos_shift_id' => $shift->id,
            'user_id' => $user->id,
            'cash_amount' => round($cashAmount, 2),
            'mpesa_amount' => round($mpesaAmount, 2),
            'reason' => trim($reason),
        ]);

        $shift->refresh();
        if ((string) $shift->cash_variance !== (string) $originalCash || (string) $shift->mpesa_variance !== (string) $originalMpesa) {
            $shift->cash_variance = $originalCash;
            $shift->mpesa_variance = $originalMpesa;
            $shift->save();
        }

        return $row;
    }

    public function varianceLabel(?float $amount): string
    {
        if ($amount === null) {
            return '';
        }
        if (abs($amount) < 0.01) {
            return 'Balanced';
        }

        return $amount < 0 ? 'Shortage' : 'Surplus';
    }

    /**
     * @return array<string, float>
     */
    private function netReceived(Sale $sale): array
    {
        $received = [];
        foreach ($sale->payments as $payment) {
            $method = strtolower((string) $payment->method);
            $received[$method] = ($received[$method] ?? 0) + (float) $payment->amount;
        }

        $refunded = 0.0;
        foreach ($sale->returns as $return) {
            $refunded += (float) $return->refund_amount;
        }

        $originalTotal = round((float) $sale->total + $refunded, 2);
        $cash = round($received[Payment::METHOD_CASH] ?? 0, 2);
        $nonCash = round(array_sum($received) - $cash, 2);
        $coveredByOthers = min($nonCash, $originalTotal);
        $change = $cash > 0 ? max(0, $cash - max(0, $originalTotal - $coveredByOthers)) : 0;
        if ($cash > 0) {
            $received[Payment::METHOD_CASH] = round($cash - $change, 2);
        }

        return $received;
    }

    private function bucket(string $method): string
    {
        if (in_array($method, ['cash', 'mpesa', 'card', 'bank_transfer'], true)) {
            return $method;
        }

        return 'other';
    }

    /**
     * @return array{cash: float, mpesa: float}
     */
    private function expenses(PosShift $shift): array
    {
        $rows = Expense::query()
            ->withoutGlobalScope('branch')
            ->where('branch_id', $shift->branch_id)
            ->where('user_id', $shift->user_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->when($shift->closed_at, function ($query) use ($shift) {
                $query->where('created_at', '<=', $shift->closed_at);
            })
            ->get();

        $totals = ['cash' => 0.0, 'mpesa' => 0.0];
        foreach ($rows as $expense) {
            $method = strtolower((string) ($expense->paying_account ?: $expense->payment_method));
            if (! isset($totals[$method])) {
                continue;
            }
            $paid = (float) $expense->paid_amount;
            if ($paid <= 0 && $expense->status === Expense::STATUS_PAID) {
                $paid = (float) $expense->amount;
            }
            if ($paid > 0) {
                $totals[$method] += $paid;
            }
        }

        return $totals;
    }

    private function review(PosShift $shift, User $user, string $status, ?string $remarks, ?string $reason): PosShift
    {
        if (! $user->hasPermission('pos.shifts.approve')) {
            throw new InvalidArgumentException('You are not allowed to review shift variances.');
        }
        if ((int) $user->id === (int) $shift->user_id || (int) $user->id === (int) $shift->closed_by) {
            throw new InvalidArgumentException('You cannot approve or reject your own shift.');
        }

        return DB::transaction(function () use ($shift, $user, $status, $remarks, $reason) {
            $locked = PosShift::query()->whereKey($shift->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== PosShift::STATUS_PENDING) {
                throw new InvalidArgumentException('This shift is not waiting for approval.');
            }

            $cashVariance = $locked->cash_variance;
            $mpesaVariance = $locked->mpesa_variance;
            $locked->status = $status;
            $locked->reviewed_by = $user->id;
            $locked->reviewed_at = now();
            $locked->review_remarks = $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null;
            $locked->rejection_reason = $reason;
            $locked->cash_variance = $cashVariance;
            $locked->mpesa_variance = $mpesaVariance;
            $locked->is_open = null;
            $locked->save();

            return $locked->fresh();
        });
    }
}
