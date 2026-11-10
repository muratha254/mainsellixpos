<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\PosShift;
use App\Models\User;
use App\Services\PosShiftService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PosShiftController extends Controller
{
    public function openForm(PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shift');

        $user = auth()->user();
        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select a branch first.');

        $existing = $shifts->findOpen($user);
        if ($existing && (int) $existing->branch_id === (int) $branchId) {
            return redirect()->route('pos.index');
        }

        $today = $existing ? null : $shifts->findToday($user);

        return view('shifts.open', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'shifts.open',
            'branch' => Branch::query()->find($branchId),
            'existing' => $existing,
            'closedToday' => $today && ! $today->isOpen() ? $today : null,
        ]));
    }

    public function open(Request $request, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shift');

        $branchId = $this->currentBranchId();
        abort_unless($branchId, 422, 'Select a branch first.');

        $data = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'opening_mpesa' => 'required|numeric|min:0',
            'opening_remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $shift = $shifts->open(
                auth()->user(),
                $branchId,
                (float) $data['opening_cash'],
                (float) $data['opening_mpesa'],
                $data['opening_remarks'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('pos.index')
            ->with('success', 'Shift ' . $shift->number . ' is open. Opening balance KSh ' . number_format((float) $shift->opening_cash + (float) $shift->opening_mpesa, 2) . '.');
    }

    public function closeForm(PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shift');

        $shift = $shifts->findOpen(auth()->user());
        if (! $shift) {
            return redirect()->route('pos.shift.open')->with('warning', 'Open a shift before closing one.');
        }

        $showFigures = auth()->user()->hasPermission('pos.shifts.approve');

        return view('shifts.close', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'shifts.close',
            'shift' => $shift->load(['cashier', 'branch']),
            'figures' => $showFigures ? $shifts->figures($shift) : null,
            'showFigures' => $showFigures,
        ]));
    }

    public function close(Request $request, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shift');

        $data = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'actual_mpesa' => 'required|numeric|min:0',
            'closing_remarks' => 'nullable|string|max:1000',
        ]);

        $shift = $shifts->findOpen(auth()->user());
        if (! $shift) {
            return redirect()->route('pos.shift.open')->with('error', 'There is no open shift to close.');
        }

        try {
            $closed = $shifts->close(
                $shift,
                auth()->user(),
                (float) $data['actual_cash'],
                (float) $data['actual_mpesa'],
                $data['closing_remarks'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = $closed->status === PosShift::STATUS_PENDING
            ? 'Shift ' . $closed->number . ' is closed and waiting for an administrator to review the variance.'
            : 'Shift ' . $closed->number . ' is closed. The cash and M-Pesa balances match.';

        if (! auth()->user()->hasPermission('pos.shifts.view')) {
            return redirect()->route('pos.shift.open')->with('success', $message);
        }

        return redirect()->route('shifts.show', $closed)->with('success', $message);
    }

    public function requestReopen(PosShift $shift, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shift');
        $this->assertVisible($shift);

        try {
            $shifts->requestReopen($shift, auth()->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $shift->number . ' is waiting for an administrator to approve reopening it.');
    }

    public function approveReopen(PosShift $shift, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shifts.approve');
        $this->assertVisible($shift);

        try {
            $reopened = $shifts->approveReopen($shift, auth()->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('shifts.show', $reopened)
            ->with('success', $reopened->number . ' is open again. The cashier can continue selling on the same shift.');
    }

    public function index(Request $request, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shifts.view');

        $user = auth()->user();
        $filters = $request->only(['from', 'to', 'user_id', 'branch_id', 'status', 'approval']);

        $query = $this->visibleShifts($user)->with(['cashier', 'branch', 'reviewer'])->orderByDesc('opened_at');

        if (! empty($filters['from'])) {
            $query->whereDate('opened_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('opened_at', '<=', $filters['to']);
        }
        if (! empty($filters['user_id']) && ! $user->seesOwnSalesOnly()) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['branch_id']) && ($user->canSwitchBranches() || $user->hasPermission('pos.shifts.approve'))) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['status'])) {
            if ($filters['status'] === 'closed_any') {
                $query->where('status', '!=', PosShift::STATUS_OPEN);
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if (! empty($filters['approval'])) {
            $approval = [
                'pending' => [PosShift::STATUS_PENDING],
                'approved' => [PosShift::STATUS_APPROVED],
                'rejected' => [PosShift::STATUS_REJECTED],
                'none' => [PosShift::STATUS_CLOSED, PosShift::STATUS_OPEN],
            ];
            if (isset($approval[$filters['approval']])) {
                $query->whereIn('status', $approval[$filters['approval']]);
            }
        }

        $varianceRow = (clone $query)->reorder()
            ->selectRaw('SUM(cash_variance) as cash_variance, SUM(mpesa_variance) as mpesa_variance')
            ->first();
        $cashVarianceTotal = round((float) ($varianceRow->cash_variance ?? 0), 2);
        $mpesaVarianceTotal = round((float) ($varianceRow->mpesa_variance ?? 0), 2);

        $rows = $query->paginate(25)->withQueryString();
        $rows->getCollection()->transform(function (PosShift $shift) use ($shifts) {
            $shift->report = $shifts->report($shift);

            return $shift;
        });

        return view('shifts.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'shifts.index',
            'shifts' => $rows,
            'filters' => $filters,
            'cashiers' => $this->cashierOptions($user),
            'branches' => $this->branchOptions($user),
            'canApprove' => $user->hasPermission('pos.shifts.approve'),
            'businessTimezone' => $shifts->businessTimezone($user),
            'cashVarianceTotal' => $cashVarianceTotal,
            'mpesaVarianceTotal' => $mpesaVarianceTotal,
            'varianceTotal' => round($cashVarianceTotal + $mpesaVarianceTotal, 2),
        ]));
    }

    public function show(PosShift $shift, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shifts.view');
        $this->assertVisible($shift);

        $shift->load([
            'cashier', 'branch', 'reviewer', 'closedBy', 'reopenedBy',
            'adjustments.user',
            'sales' => function ($query) {
                $query->withoutGlobalScope('branch')
                    ->with(['payments' => function ($payments) {
                        $payments->withoutGlobalScope('branch');
                    }])
                    ->orderBy('id');
            },
        ]);

        $user = auth()->user();

        return view('shifts.show', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'shifts.show',
            'shift' => $shift,
            'figures' => $shifts->report($shift),
            'canApprove' => $user->hasPermission('pos.shifts.approve'),
            'canReviewThis' => $this->canReview($user, $shift),
            'canApproveReopen' => $this->canApproveReopen($user, $shift, $shifts),
            'canRequestReopen' => $this->canRequestReopen($user, $shift, $shifts),
            'paymentLabels' => config('sellix.payment_methods', []),
        ]));
    }

    public function approve(Request $request, PosShift $shift, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shifts.approve');
        $this->assertVisible($shift);

        $data = $request->validate([
            'review_remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $reviewed = $shifts->approve($shift, auth()->user(), $data['review_remarks'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('shifts.show', $reviewed)->with('success', 'Variance approved. The original variance was left unchanged.');
    }

    public function reject(Request $request, PosShift $shift, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shifts.approve');
        $this->assertVisible($shift);

        $data = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $reviewed = $shifts->reject($shift, auth()->user(), $data['rejection_reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('shifts.show', $reviewed)->with('success', 'Variance rejected. The shift stays closed.');
    }

    public function adjust(Request $request, PosShift $shift, PosShiftService $shifts)
    {
        $this->authorizePermission('pos.shifts.approve');
        $this->assertVisible($shift);

        $data = $request->validate([
            'cash_amount' => 'nullable|numeric',
            'mpesa_amount' => 'nullable|numeric',
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $shifts->adjust(
                $shift,
                auth()->user(),
                (float) ($data['cash_amount'] ?? 0),
                (float) ($data['mpesa_amount'] ?? 0),
                $data['reason']
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('shifts.show', $shift)->with('success', 'Adjustment recorded. Opening, closing, and variance figures were not changed.');
    }

    private function visibleShifts(User $user)
    {
        $query = PosShift::query()->where('company_id', $user->company_id);

        if ($user->seesOwnSalesOnly()) {
            return $query->where('user_id', $user->id);
        }

        if (! $user->canSwitchBranches() && ! $user->hasPermission('pos.shifts.approve')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    private function assertVisible(PosShift $shift): void
    {
        abort_unless($this->visibleShifts(auth()->user())->whereKey($shift->id)->exists(), 403);
    }

    private function canReview(User $user, PosShift $shift): bool
    {
        return $user->hasPermission('pos.shifts.approve')
            && $shift->status === PosShift::STATUS_PENDING;
    }

    private function canApproveReopen(User $user, PosShift $shift, PosShiftService $shifts): bool
    {
        return $user->hasPermission('pos.shifts.approve')
            && ! $shift->isOpen()
            && $shifts->openedToday($shift, $user);
    }

    private function canRequestReopen(User $user, PosShift $shift, PosShiftService $shifts): bool
    {
        return ! $user->hasPermission('pos.shifts.approve')
            && (int) $shift->user_id === (int) $user->id
            && ! $shift->isOpen()
            && ! $shift->reopen_requested_at
            && $shifts->openedToday($shift, $user);
    }

    private function cashierOptions(User $user)
    {
        if ($user->seesOwnSalesOnly()) {
            return collect([$user]);
        }

        $query = User::query()->where('company_id', $user->company_id)->where('is_active', true)->orderBy('name');
        if (! $user->canSwitchBranches() && ! $user->hasPermission('pos.shifts.approve')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query->get();
    }

    private function branchOptions(User $user)
    {
        $query = Branch::query()->where('company_id', $user->company_id)->where('is_active', true)->orderBy('name');
        if (! $user->canSwitchBranches() && ! $user->hasPermission('pos.shifts.approve')) {
            $query->where('id', $user->branch_id);
        }

        return $query->get();
    }
}
