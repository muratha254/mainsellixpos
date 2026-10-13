<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyProduct;
use App\Models\LoyaltyTransaction;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\LoyaltyService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission('customers.view');

        $search = trim((string) $request->input('q', ''));
        $customers = Customer::query()
            ->with('branch')
            ->where('is_walk_in', false)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    if (ctype_digit($search)) {
                        $inner->orWhere('id', (int) $search);
                    }
                    $inner->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%')
                        ->orWhere('mobile', 'like', '%' . $search . '%');
                });
            })
            ->withSum(['loyaltyTransactions as earned_points' => function ($query) {
                $query->where('type', LoyaltyTransaction::TYPE_EARN);
            }], 'points')
            ->withSum(['loyaltyTransactions as redeemed_points' => function ($query) {
                $query->where('type', LoyaltyTransaction::TYPE_REDEEM);
            }], 'points')
            ->orderBy('name')
            ->get();

        return view('loyalty.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'customers.loyalty',
            'customers' => $customers,
            'search' => $search,
            'canAdjust' => $this->canAdjust(),
        ]));
    }

    public function transactions(Request $request)
    {
        $this->authorizePermission('customers.view');

        $type = (string) $request->input('type', '');
        $status = (string) $request->input('status', '');
        $customerId = $request->input('customer_id');
        $from = $request->input('from');
        $to = $request->input('to');

        $transactions = LoyaltyTransaction::query()
            ->with(['customer', 'sale', 'user'])
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        $customers = Customer::query()->where('is_walk_in', false)->orderBy('name')->get(['id', 'name']);

        return view('loyalty.transactions', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'customers.loyalty',
            'transactions' => $transactions,
            'customers' => $customers,
            'filters' => [
                'type' => $type,
                'status' => $status,
                'customer_id' => $customerId,
                'from' => $from,
                'to' => $to,
            ],
        ]));
    }

    public function show(Customer $customer, LoyaltyService $loyalty)
    {
        $this->authorizePermission('customers.view');

        $summary = $loyalty->summary($customer);
        $transactions = LoyaltyTransaction::query()
            ->with(['sale', 'user'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        return view('loyalty.show', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'customers.loyalty',
            'customer' => $customer,
            'summary' => $summary,
            'transactions' => $transactions,
            'canAdjust' => $this->canAdjust(),
        ]));
    }

    public function adjust(Request $request, Customer $customer, LoyaltyService $loyalty, AuditLogger $audit)
    {
        abort_unless($this->canAdjust(), 403);

        $data = $request->validate([
            'direction' => 'required|in:award,deduct',
            'points' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:1000',
        ]);

        $points = round((float) $data['points'], 2);
        if ($data['direction'] === 'deduct') {
            $points = -$points;
        }

        try {
            $tx = $loyalty->adjust($customer, $points, $data['reason'], auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $audit->record('adjust', 'loyalty', $tx, null, [
            'customer_id' => $customer->id,
            'points' => $points,
            'reason' => $data['reason'],
            'balance_after' => $tx->balance_after,
        ]);

        return back()->with('success', 'Loyalty points updated. Balance is now ' . number_format((float) $tx->balance_after, 2) . '.');
    }

    public function settings(SettingsService $settings)
    {
        $this->authorizePermission('settings.view');

        $companyId = (int) auth()->user()->company_id;
        $loyalty = app(LoyaltyService::class);
        $rules = $loyalty->rules($companyId);
        $spend = $this->spendFields($rules['rate']);
        $products = Product::query()
            ->availableAtBranch($this->currentBranchId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        return view('loyalty.settings', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'settings.loyalty',
            'rules' => $rules,
            'spendPoints' => $spend['points'],
            'spendAmount' => $spend['amount'],
            'products' => $products,
            'canUpdate' => auth()->user()->hasPermission('settings.company'),
        ]));
    }

    public function updateSettings(Request $request, SettingsService $settings, AuditLogger $audit)
    {
        $this->authorizePermission('settings.company');

        $data = $request->validate([
            'loyalty_enabled' => 'required|in:0,1',
            'loyalty_earn_mode' => 'required|in:spend,item',
            'points_awarded' => 'required|numeric|min:0',
            'spend_amount' => 'required|numeric|min:0.01',
            'loyalty_points_per_item' => 'required|numeric|min:0',
            'loyalty_min_redeem' => 'required|numeric|min:0',
            'loyalty_point_value' => 'required|numeric|min:0',
            'loyalty_max_redeem' => 'nullable|numeric|min:0',
            'loyalty_eligible' => 'required|in:all,selected',
            'loyalty_expiry_days' => 'required|integer|min:0|max:3650',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $rate = round(((float) $data['points_awarded']) / ((float) $data['spend_amount']), 6);
        $companyId = (int) auth()->user()->company_id;
        $settings->setMany($companyId, [
            'loyalty_enabled' => $data['loyalty_enabled'],
            'loyalty_earn_mode' => $data['loyalty_earn_mode'],
            'loyalty_points_rate' => $rate,
            'loyalty_points_per_item' => $data['loyalty_points_per_item'],
            'loyalty_min_redeem' => $data['loyalty_min_redeem'],
            'loyalty_point_value' => $data['loyalty_point_value'],
            'loyalty_max_redeem' => $data['loyalty_max_redeem'] ?? 0,
            'loyalty_eligible' => $data['loyalty_eligible'],
            'loyalty_expiry_days' => $data['loyalty_expiry_days'],
        ], 'loyalty');

        LoyaltyProduct::query()->where('company_id', $companyId)->delete();
        foreach (array_unique($data['product_ids'] ?? []) as $productId) {
            LoyaltyProduct::query()->create([
                'company_id' => $companyId,
                'product_id' => (int) $productId,
            ]);
        }

        $audit->record('update', 'loyalty', null, null, [
            'enabled' => $data['loyalty_enabled'],
            'mode' => $data['loyalty_earn_mode'],
            'rate' => $rate,
            'min_redeem' => $data['loyalty_min_redeem'],
            'point_value' => $data['loyalty_point_value'],
        ]);

        return redirect()->route('settings.loyalty')->with('success', 'Loyalty settings saved.');
    }

    private function canAdjust(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasPermission('customers.loyalty') || $user->isCompanyAdmin());
    }

    /**
     * @return array{points:float, amount:float}
     */
    private function spendFields(float $rate): array
    {
        if ($rate <= 0) {
            return ['points' => 1.0, 'amount' => 100.0];
        }

        $amount = 1 / $rate;
        if ($amount >= 1 && $amount <= 1000000 && abs($amount - round($amount, 2)) < 0.0001) {
            return ['points' => 1.0, 'amount' => round($amount, 2)];
        }

        return ['points' => $rate, 'amount' => 1.0];
    }
}
