<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\LoyaltyProduct;
use App\Models\LoyaltyTransaction;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoyaltyService
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * @return array{
     *   enabled:bool,mode:string,rate:float,points_per_item:float,min_redeem:float,
     *   point_value:float,max_redeem:float,eligible:string,expiry_days:int,product_ids:array<int,int>
     * }
     */
    public function rules(int $companyId): array
    {
        $mode = (string) $this->settings->get($companyId, 'loyalty_earn_mode', 'spend');
        if (! in_array($mode, ['spend', 'item'], true)) {
            $mode = 'spend';
        }
        $eligible = (string) $this->settings->get($companyId, 'loyalty_eligible', 'all');
        if (! in_array($eligible, ['all', 'selected'], true)) {
            $eligible = 'all';
        }

        $rate = round(max(0, (float) $this->settings->get($companyId, 'loyalty_points_rate', 0)), 6);
        $pointsPerItem = round(max(0, (float) $this->settings->get($companyId, 'loyalty_points_per_item', 0)), 2);
        $pointValue = round(max(0, (float) $this->settings->get($companyId, 'loyalty_point_value', 1)), 4);
        $storedEnabled = $this->settings->get($companyId, 'loyalty_enabled', null);
        if ($storedEnabled === null || $storedEnabled === '') {
            $enabled = $rate > 0 || ($mode === 'item' && $pointsPerItem > 0);
        } else {
            $enabled = (string) $storedEnabled !== '0';
        }

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'rate' => $rate,
            'points_per_item' => $pointsPerItem,
            'min_redeem' => round(max(0, (float) $this->settings->get($companyId, 'loyalty_min_redeem', 0)), 2),
            'point_value' => $pointValue,
            'can_redeem' => (string) $storedEnabled !== '0' && $pointValue > 0,
            'max_redeem' => round(max(0, (float) $this->settings->get($companyId, 'loyalty_max_redeem', 0)), 2),
            'eligible' => $eligible,
            'expiry_days' => max(0, (int) $this->settings->get($companyId, 'loyalty_expiry_days', 0)),
            'product_ids' => LoyaltyProduct::query()
                ->where('company_id', $companyId)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        ];
    }

    public function earnForSale(Sale $sale): ?LoyaltyTransaction
    {
        if ($sale->status !== Sale::STATUS_COMPLETED || ! $sale->customer_id) {
            return null;
        }
        if (! $this->isSettled($sale)) {
            return null;
        }

        $existing = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_EARN);
        if ($existing) {
            return $existing;
        }

        $rules = $this->rules((int) $sale->company_id);
        if (! $rules['enabled']) {
            return null;
        }

        $sale->loadMissing('items');
        $points = $this->pointsForSale($sale, $rules);
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($sale, $points, $rules) {
            $again = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_EARN);
            if ($again) {
                return $again;
            }

            $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
            if (! $customer) {
                return null;
            }

            $this->expireLocked($customer);
            $customer->refresh();
            $balance = $this->moveBalance($customer, $points, false);
            $expiresAt = $rules['expiry_days'] > 0 ? now()->addDays($rules['expiry_days']) : null;

            return LoyaltyTransaction::query()->create([
                'company_id' => $sale->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_EARN,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => $points,
                'points_remaining' => $points,
                'amount' => round((float) $sale->total, 2),
                'balance_after' => $balance,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'sale_id' => $sale->id,
                'reference' => $sale->number,
                'expires_at' => $expiresAt,
                'notes' => 'Points earned on sale',
            ]);
        });
    }

    public function reverseForSale(Sale $sale): ?LoyaltyTransaction
    {
        $earnReverse = $this->reverseEarnedPoints($sale);
        $redeemReverse = $this->restoreRedeemedPoints($sale);

        return $earnReverse ?: $redeemReverse;
    }

    public function reverseForReturn(SaleReturn $return, float $amount): ?LoyaltyTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || ! $return->sale_id || ! $return->customer_id) {
            return null;
        }

        $existing = $this->findSource($return->company_id, SaleReturn::class, $return->id, LoyaltyTransaction::TYPE_REVERSE);
        if ($existing) {
            return $existing;
        }

        $sale = $return->relationLoaded('sale') ? $return->sale : Sale::query()->find($return->sale_id);
        if (! $sale) {
            return null;
        }

        $earn = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_EARN);
        if (! $earn) {
            return null;
        }

        $base = round((float) $sale->total, 2);
        if ($base <= 0) {
            return null;
        }
        $fraction = min(1, $amount / $base);
        $points = round((float) $earn->points * $fraction, 2);
        $points = min($points, $this->unreversedEarn($sale, $earn));
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($return, $sale, $earn, $points) {
            $again = $this->findSource($return->company_id, SaleReturn::class, $return->id, LoyaltyTransaction::TYPE_REVERSE);
            if ($again) {
                return $again;
            }

            $customer = Customer::query()->lockForUpdate()->find($return->customer_id);
            if (! $customer) {
                return null;
            }

            $this->shrinkLot($earn, $points);
            $balance = $this->moveBalance($customer, -$points, true);

            return LoyaltyTransaction::query()->create([
                'company_id' => $return->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_REVERSE,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => -$points,
                'amount' => null,
                'balance_after' => $balance,
                'source_type' => SaleReturn::class,
                'source_id' => $return->id,
                'sale_id' => $sale->id,
                'reference' => $return->number,
                'notes' => 'Points reversed for sale return',
            ]);
        });
    }

    public function adjustForCreditNote(CreditNote $note): ?LoyaltyTransaction
    {
        if (! $note->customer_id || $note->loyalty_adjusted) {
            return null;
        }

        $existing = $this->findSource($note->company_id, CreditNote::class, $note->id, LoyaltyTransaction::TYPE_REVERSE);
        if ($existing) {
            return $existing;
        }

        $rules = $this->rules((int) $note->company_id);
        if (! $rules['enabled']) {
            return null;
        }

        $points = 0.0;
        if ($rules['mode'] === 'item') {
            $note->loadMissing('items');
            $qty = round((float) $note->items->sum('quantity'), 4);
            $points = round($qty * $rules['points_per_item'], 2);
        } else {
            $points = round(((float) $note->total) * $rules['rate'], 2);
        }
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($note, $points) {
            $customer = Customer::query()->lockForUpdate()->find($note->customer_id);
            if (! $customer) {
                return null;
            }

            $this->consumeLots($customer, $points);
            $balance = $this->moveBalance($customer, -$points, true);
            $tx = LoyaltyTransaction::query()->create([
                'company_id' => $note->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_REVERSE,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => -$points,
                'balance_after' => $balance,
                'source_type' => CreditNote::class,
                'source_id' => $note->id,
                'sale_id' => $note->sale_id,
                'reference' => $note->number,
                'notes' => 'Points adjusted for credit note',
            ]);
            $note->update(['loyalty_adjusted' => true]);

            return $tx;
        });
    }

    /**
     * @param  array<int, array{product_id:int, quantity:float, line_total:float}>  $lines
     * @return array{points:float, amount:float}
     */
    public function quoteRedemption(Customer $customer, float $points, array $lines, float $cashierDiscount): array
    {
        $points = round($points, 2);
        if ($points <= 0) {
            return ['points' => 0.0, 'amount' => 0.0];
        }

        $locked = Customer::query()->lockForUpdate()->find($customer->id);
        if (! $locked) {
            throw new \InvalidArgumentException('Select a customer before redeeming points.');
        }
        if ($locked->is_walk_in) {
            throw new \InvalidArgumentException('Walk-in customers cannot redeem loyalty points. Select a customer account.');
        }

        $rules = $this->rules((int) $locked->company_id);

        $this->expireLocked($locked);
        $locked->refresh();
        $available = round((float) $locked->loyalty_points, 2);
        if ($rules['min_redeem'] > 0 && $available + 0.009 < $rules['min_redeem']) {
            throw new \InvalidArgumentException(
                'This customer needs at least ' . number_format($rules['min_redeem'], 2)
                . ' points before redeeming. Available: ' . number_format($available, 2) . '.'
            );
        }
        if ($points - $available > 0.009) {
            throw new \InvalidArgumentException('Not enough loyalty points. Available: ' . number_format($available, 2) . '.');
        }
        if ($rules['min_redeem'] > 0 && $points + 0.009 < $rules['min_redeem']) {
            throw new \InvalidArgumentException('Redeem at least ' . number_format($rules['min_redeem'], 2) . ' points.');
        }
        if ($rules['max_redeem'] > 0 && $points - $rules['max_redeem'] > 0.009) {
            throw new \InvalidArgumentException('This sale can redeem at most ' . number_format($rules['max_redeem'], 2) . ' points.');
        }

        $eligible = $this->eligiblePayable($lines, $cashierDiscount, $rules);
        $amount = round($points * $this->redemptionUnit($rules), 2);
        if ($amount - $eligible > 0.009) {
            throw new \InvalidArgumentException(
                'Loyalty discount cannot exceed the payable total of Ksh ' . number_format($eligible, 2) . '.'
            );
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('This redemption has no value.');
        }

        return ['points' => $points, 'amount' => $amount];
    }

    public function redeemForSale(Sale $sale): ?LoyaltyTransaction
    {
        $points = round((float) $sale->loyalty_points_redeemed, 2);
        if ($points <= 0 || ! $sale->customer_id) {
            return null;
        }

        $existing = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_REDEEM);
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($sale, $points) {
            $again = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_REDEEM);
            if ($again) {
                return $again;
            }

            $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
            if (! $customer || $customer->is_walk_in) {
                throw new \InvalidArgumentException('Walk-in customers cannot redeem loyalty points.');
            }

            $rules = $this->rules((int) $sale->company_id);

            $this->expireLocked($customer);
            $customer->refresh();
            if ($points - (float) $customer->loyalty_points > 0.009) {
                throw new \InvalidArgumentException('Not enough loyalty points to complete this sale.');
            }

            $expected = round($points * $this->redemptionUnit($rules), 2);
            if (abs($expected - (float) $sale->loyalty_discount) > 0.05) {
                throw new \InvalidArgumentException('Loyalty discount does not match the points being redeemed.');
            }

            $this->consumeLots($customer, $points);
            $balance = $this->moveBalance($customer, -$points, false);

            return LoyaltyTransaction::query()->create([
                'company_id' => $sale->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_REDEEM,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => -$points,
                'amount' => round((float) $sale->loyalty_discount, 2),
                'balance_after' => $balance,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'sale_id' => $sale->id,
                'reference' => $sale->number . ':redeem',
                'notes' => 'Points redeemed on sale',
            ]);
        });
    }

    public function adjust(Customer $customer, float $points, string $reason, ?int $userId = null): LoyaltyTransaction
    {
        $points = round($points, 2);
        if (abs($points) < 0.009) {
            throw new \InvalidArgumentException('Enter a points amount other than zero.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required.');
        }

        return DB::transaction(function () use ($customer, $points, $reason, $userId) {
            $locked = Customer::query()->lockForUpdate()->find($customer->id);
            if (! $locked) {
                throw new \InvalidArgumentException('Customer not found.');
            }
            $this->expireLocked($locked);
            $locked->refresh();
            if ($points < 0 && abs($points) - (float) $locked->loyalty_points > 0.009) {
                throw new \InvalidArgumentException('Deduction cannot be more than the available balance.');
            }

            $rules = $this->rules((int) $locked->company_id);
            if ($points < 0) {
                $this->consumeLots($locked, abs($points));
            }
            $balance = $this->moveBalance($locked, $points, false);
            $reference = 'ADJ-' . now()->format('YmdHis') . '-' . $locked->id . '-' . Str::lower(Str::random(4));

            $tx = LoyaltyTransaction::query()->create([
                'company_id' => $locked->company_id,
                'customer_id' => $locked->id,
                'user_id' => $userId ?: auth()->id(),
                'type' => LoyaltyTransaction::TYPE_ADJUST,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => $points,
                'points_remaining' => $points > 0 ? $points : null,
                'balance_after' => $balance,
                'reference' => $reference,
                'notes' => $reason,
                'expires_at' => ($points > 0 && ($rules['expiry_days'] ?? 0) > 0)
                    ? now()->addDays($rules['expiry_days'])
                    : null,
            ]);

            if ($points > 0) {
                $tx->update([
                    'source_type' => LoyaltyTransaction::class,
                    'source_id' => $tx->id,
                ]);
            }

            return $tx->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Customer $customer): array
    {
        DB::transaction(function () use ($customer) {
            $locked = Customer::query()->lockForUpdate()->find($customer->id);
            if ($locked) {
                $this->expireLocked($locked);
            }
        });
        $customer->refresh();

        $rules = $this->rules((int) $customer->company_id);
        $rows = LoyaltyTransaction::query()->where('customer_id', $customer->id)->get();
        $sum = function (string $type) use ($rows) {
            return round((float) $rows->where('type', $type)->sum(fn ($row) => abs((float) $row->points)), 2);
        };
        $available = round((float) $customer->loyalty_points, 2);

        return [
            'available' => $available,
            'earned' => $sum(LoyaltyTransaction::TYPE_EARN),
            'redeemed' => $sum(LoyaltyTransaction::TYPE_REDEEM),
            'adjusted' => round((float) $rows->where('type', LoyaltyTransaction::TYPE_ADJUST)->sum('points'), 2),
            'expired' => $sum(LoyaltyTransaction::TYPE_EXPIRE),
            'reversed' => $sum(LoyaltyTransaction::TYPE_REVERSE),
            'pending' => $this->pendingPoints($customer),
            'min_redeem' => $rules['min_redeem'],
            'point_value' => $rules['point_value'],
            'value' => round($available * $rules['point_value'], 2),
            'can_redeem' => $rules['enabled'] && $rules['point_value'] > 0 && $available + 0.009 >= max($rules['min_redeem'], 0.01),
            'enabled' => $rules['enabled'],
            'rules' => $rules,
        ];
    }

    public function expireAll(): int
    {
        $count = 0;
        $ids = LoyaltyTransaction::query()
            ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_ADJUST])
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->distinct()
            ->pluck('customer_id');

        foreach ($ids as $customerId) {
            $customer = Customer::query()->find($customerId);
            if (! $customer) {
                continue;
            }
            DB::transaction(function () use ($customer, &$count) {
                $locked = Customer::query()->lockForUpdate()->find($customer->id);
                if ($locked) {
                    $count += $this->expireLocked($locked);
                }
            });
        }

        return $count;
    }

    /**
     * @param  array<int, array{product_id:int, quantity?:float, line_total:float}>  $lines
     */
    public function eligiblePayable(array $lines, float $cashierDiscount, ?array $rules = null): float
    {
        $subtotal = round(collect($lines)->sum('line_total'), 2);
        $cashierDiscount = round(min(max(0, $cashierDiscount), max(0, $subtotal)), 2);
        if (! $rules || $rules['eligible'] !== 'selected') {
            return round(max(0, $subtotal - $cashierDiscount), 2);
        }

        $ids = array_map('intval', $rules['product_ids'] ?? []);
        $eligible = round(collect($lines)->filter(function ($line) use ($ids) {
            return in_array((int) ($line['product_id'] ?? 0), $ids, true);
        })->sum('line_total'), 2);
        if ($subtotal <= 0) {
            return 0.0;
        }
        $share = round($cashierDiscount * ($eligible / $subtotal), 2);

        return round(max(0, $eligible - $share), 2);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function pointsForSale(Sale $sale, array $rules): float
    {
        if ($rules['mode'] === 'item') {
            $qty = 0.0;
            foreach ($sale->items as $item) {
                if ($this->productEligible((int) $item->product_id, $rules)) {
                    $qty += (float) $item->quantity;
                }
            }

            return round($qty * $rules['points_per_item'], 2);
        }

        if ($rules['eligible'] === 'selected') {
            $lines = $sale->items->map(function ($item) {
                return [
                    'product_id' => (int) $item->product_id,
                    'line_total' => (float) $item->line_total,
                ];
            })->all();
            $cashier = round(max(0, (float) $sale->discount_amount - (float) $sale->loyalty_discount), 2);
            $base = $this->eligiblePayable($lines, $cashier, $rules);

            return round($base * $rules['rate'], 2);
        }

        return round(((float) $sale->total) * $rules['rate'], 2);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function productEligible(int $productId, array $rules): bool
    {
        if ($rules['eligible'] !== 'selected') {
            return true;
        }

        return in_array($productId, array_map('intval', $rules['product_ids'] ?? []), true);
    }

    private function pendingPoints(Customer $customer): float
    {
        $rules = $this->rules((int) $customer->company_id);
        if (! $rules['enabled']) {
            return 0.0;
        }

        $sales = Sale::query()
            ->with('items')
            ->where('customer_id', $customer->id)
            ->where('status', Sale::STATUS_COMPLETED)
            ->where(function ($query) {
                $query->where('payment_status', '!=', Sale::PAYMENT_PAID)
                    ->orWhereColumn('paid_amount', '<', 'total');
            })
            ->get();

        $points = 0.0;
        foreach ($sales as $sale) {
            if ($this->isSettled($sale)) {
                continue;
            }
            if ($this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_EARN)) {
                continue;
            }
            $points += $this->pointsForSale($sale, $rules);
        }

        return round($points, 2);
    }

    private function reverseEarnedPoints(Sale $sale): ?LoyaltyTransaction
    {
        $earn = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_EARN);
        if (! $earn || ! $sale->customer_id) {
            return null;
        }

        $existing = LoyaltyTransaction::query()
            ->where('company_id', $sale->company_id)
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('type', LoyaltyTransaction::TYPE_REVERSE)
            ->where('reference', $sale->number)
            ->first();
        if ($existing) {
            return $existing;
        }

        $points = $this->unreversedEarn($sale, $earn);
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($sale, $earn, $points) {
            $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
            if (! $customer) {
                return null;
            }
            $this->shrinkLot($earn, $points);
            $balance = $this->moveBalance($customer, -$points, true);
            $earn->update(['status' => LoyaltyTransaction::STATUS_REVERSED, 'points_remaining' => 0]);

            return LoyaltyTransaction::query()->create([
                'company_id' => $sale->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_REVERSE,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => -$points,
                'balance_after' => $balance,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'sale_id' => $sale->id,
                'reference' => $sale->number,
                'notes' => 'Points reversed for voided/cancelled sale',
            ]);
        });
    }

    private function restoreRedeemedPoints(Sale $sale): ?LoyaltyTransaction
    {
        $redeem = $this->findSource($sale->company_id, Sale::class, $sale->id, LoyaltyTransaction::TYPE_REDEEM);
        if (! $redeem || ! $sale->customer_id) {
            return null;
        }

        $reference = $sale->number . ':redeem';
        $existing = LoyaltyTransaction::query()
            ->where('company_id', $sale->company_id)
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('type', LoyaltyTransaction::TYPE_REVERSE)
            ->where('reference', $reference)
            ->first();
        if ($existing) {
            return $existing;
        }

        $points = abs((float) $redeem->points);
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($sale, $redeem, $points, $reference) {
            $customer = Customer::query()->lockForUpdate()->find($sale->customer_id);
            if (! $customer) {
                return null;
            }
            $balance = $this->moveBalance($customer, $points, true);
            $redeem->update(['status' => LoyaltyTransaction::STATUS_REVERSED]);

            return LoyaltyTransaction::query()->create([
                'company_id' => $sale->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_REVERSE,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => $points,
                'amount' => round((float) $sale->loyalty_discount, 2),
                'balance_after' => $balance,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'sale_id' => $sale->id,
                'reference' => $reference,
                'notes' => 'Redeemed points restored for voided/cancelled sale',
            ]);
        });
    }

    private function unreversedEarn(Sale $sale, LoyaltyTransaction $earn): float
    {
        $reversed = (float) LoyaltyTransaction::query()
            ->where('sale_id', $sale->id)
            ->where('type', LoyaltyTransaction::TYPE_REVERSE)
            ->where('points', '<', 0)
            ->sum('points');

        return round(max(0, (float) $earn->points + $reversed), 2);
    }

    private function findSource($companyId, string $type, $id, string $movement): ?LoyaltyTransaction
    {
        return LoyaltyTransaction::query()
            ->where('company_id', $companyId)
            ->where('source_type', $type)
            ->where('source_id', $id)
            ->where('type', $movement)
            ->first();
    }

    private function isSettled(Sale $sale): bool
    {
        $total = round((float) $sale->total, 2);
        $paid = round((float) $sale->paid_amount, 2);

        return $sale->payment_status === Sale::PAYMENT_PAID || $total <= 0.009 || $paid + 0.009 >= $total;
    }

    private function redemptionUnit(array $rules): float
    {
        $unit = round((float) ($rules['point_value'] ?? 0), 4);

        return $unit > 0 ? $unit : 1.0;
    }

    private function moveBalance(Customer $customer, float $delta, bool $clamp): float
    {
        $balance = round((float) $customer->loyalty_points + $delta, 2);
        if ($balance < -0.009) {
            if (! $clamp) {
                throw new \InvalidArgumentException('Loyalty balance cannot go below zero.');
            }
            $balance = 0;
        }
        $customer->update(['loyalty_points' => round(max(0, $balance), 2)]);

        return round((float) $customer->loyalty_points, 2);
    }

    private function consumeLots(Customer $customer, float $points): void
    {
        $left = round($points, 2);
        if ($left <= 0) {
            return;
        }

        $lots = LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_ADJUST])
            ->where('points_remaining', '>', 0)
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = round(min((float) $lot->points_remaining, $left), 2);
            $lot->update(['points_remaining' => round((float) $lot->points_remaining - $take, 2)]);
            $left = round($left - $take, 2);
        }
    }

    private function shrinkLot(LoyaltyTransaction $earn, float $points): void
    {
        $remaining = $earn->points_remaining === null ? (float) $earn->points : (float) $earn->points_remaining;
        $earn->update(['points_remaining' => round(max(0, $remaining - $points), 2)]);
    }

    private function expireLocked(Customer $customer): int
    {
        $lots = LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_ADJUST])
            ->where('points_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $count = 0;
        foreach ($lots as $lot) {
            $customer->refresh();
            $points = round(min((float) $lot->points_remaining, (float) $customer->loyalty_points), 2);
            $lot->update(['points_remaining' => 0]);
            if ($points <= 0) {
                continue;
            }
            if ($this->findSource($customer->company_id, LoyaltyTransaction::class, $lot->id, LoyaltyTransaction::TYPE_EXPIRE)) {
                continue;
            }
            $balance = $this->moveBalance($customer, -$points, true);
            LoyaltyTransaction::query()->create([
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => LoyaltyTransaction::TYPE_EXPIRE,
                'status' => LoyaltyTransaction::STATUS_POSTED,
                'points' => -$points,
                'balance_after' => $balance,
                'source_type' => LoyaltyTransaction::class,
                'source_id' => $lot->id,
                'sale_id' => $lot->sale_id,
                'reference' => 'EXP-' . $lot->id,
                'notes' => 'Points expired',
            ]);
            $count++;
        }

        return $count;
    }
}
