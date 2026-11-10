@extends('layouts.fleet')

@section('title', $shift->number)

@section('content')
<link rel="stylesheet" href="{{ asset('css/sellix-shifts.css') }}?v=1">
@include('layouts.partials.page-header', [
    'title' => $shift->number,
    'subtitle' => $shift->statusLabel(),
    'backUrl' => route('shifts.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Shift Reports', 'url' => route('shifts.index')],
        ['label' => $shift->number],
    ],
])

@php
    $varianceClass = function ($amount) {
        if ($amount === null) return '';
        if (abs((float) $amount) < 0.01) return 'sx-shift-even';
        return (float) $amount < 0 ? 'sx-shift-short' : 'sx-shift-over';
    };
    $varianceText = function ($amount) {
        if ($amount === null) return '—';
        $label = abs((float) $amount) < 0.01 ? 'Balanced' : ((float) $amount < 0 ? 'Shortage' : 'Surplus');
        return format_kes($amount) . ' (' . $label . ')';
    };
@endphp

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<div class="row">
    <div class="col-md-4"><div class="sx-shift-figure"><span>Cashier</span><strong>{{ $shift->cashier->name ?? '—' }}</strong></div></div>
    <div class="col-md-4"><div class="sx-shift-figure"><span>Branch</span><strong>{{ $shift->branch->name ?? '—' }}</strong></div></div>
    <div class="col-md-4"><div class="sx-shift-figure"><span>Status</span><strong><span class="sx-shift-badge sx-shift-{{ $shift->status }}">{{ $shift->statusLabel() }}</span></strong></div></div>
</div>

<div class="sx-acc-card">
    <div class="sx-acc-card-head">Balances</div>
    <div class="sx-acc-card-body table-responsive">
        <table class="table">
            <tbody>
                <tr><td>Opened</td><td>{{ optional($shift->opened_at)->format('d/m/Y H:i') }} by {{ $shift->cashier->name ?? '—' }}</td></tr>
                <tr><td>Closed</td><td>{{ $shift->closed_at ? optional($shift->closed_at)->format('d/m/Y H:i') . ' by ' . ($shift->closedBy->name ?? $shift->cashier->name ?? '—') : '—' }}</td></tr>
                <tr><td>Opening cash</td><td>{{ format_kes($figures['opening_cash']) }}</td></tr>
                <tr><td>Opening M-Pesa</td><td>{{ format_kes($figures['opening_mpesa']) }}</td></tr>
                <tr><td>Opening remarks</td><td>{{ $shift->opening_remarks ?: '—' }}</td></tr>
                <tr><td>Cash sales</td><td>{{ format_kes($figures['cash_sales']) }}</td></tr>
                <tr><td>M-Pesa sales</td><td>{{ format_kes($figures['mpesa_sales']) }}</td></tr>
                <tr><td>Card sales</td><td>{{ format_kes($figures['card_sales']) }}</td></tr>
                <tr><td>Bank transfer sales</td><td>{{ format_kes($figures['bank_transfer_sales']) }}</td></tr>
                <tr><td>Other sales</td><td>{{ format_kes($figures['other_sales']) }}</td></tr>
                <tr><td>Cash refunds</td><td>{{ format_kes($figures['cash_refunds']) }}</td></tr>
                <tr><td>M-Pesa refunds</td><td>{{ format_kes($figures['mpesa_refunds']) }}</td></tr>
                <tr><td>Cash expenses</td><td>{{ format_kes($figures['cash_expenses']) }}</td></tr>
                <tr><td>M-Pesa expenses</td><td>{{ format_kes($figures['mpesa_expenses']) }}</td></tr>
                <tr><th>Expected cash</th><th>{{ format_kes($figures['expected_cash']) }}</th></tr>
                <tr><th>Expected M-Pesa</th><th>{{ format_kes($figures['expected_mpesa']) }}</th></tr>
                <tr><th>Expected total</th><th>{{ format_kes($figures['expected_total']) }}</th></tr>
                <tr><td>Actual cash counted</td><td>{{ $figures['actual_cash'] === null ? '—' : format_kes($figures['actual_cash']) }}</td></tr>
                <tr><td>Actual M-Pesa</td><td>{{ $figures['actual_mpesa'] === null ? '—' : format_kes($figures['actual_mpesa']) }}</td></tr>
                <tr><td>Actual total</td><td>{{ $figures['actual_total'] === null ? '—' : format_kes($figures['actual_total']) }}</td></tr>
                <tr><td>Closing remarks</td><td>{{ $shift->closing_remarks ?: '—' }}</td></tr>
                <tr><th>Cash variance</th><th class="{{ $varianceClass($figures['cash_variance']) }}">{{ $varianceText($figures['cash_variance']) }}</th></tr>
                <tr><th>M-Pesa variance</th><th class="{{ $varianceClass($figures['mpesa_variance']) }}">{{ $varianceText($figures['mpesa_variance']) }}</th></tr>
                <tr><th>Total variance</th><th class="{{ $varianceClass($figures['total_variance']) }}">{{ $varianceText($figures['total_variance']) }}</th></tr>
            </tbody>
        </table>
    </div>
</div>

@if($shift->reviewed_at)
    <div class="sx-acc-card">
        <div class="sx-acc-card-head">Review</div>
        <div class="sx-acc-card-body">
            <p>{{ $shift->statusLabel() }} by {{ $shift->reviewer->name ?? '—' }} on {{ optional($shift->reviewed_at)->format('d/m/Y H:i') }}.</p>
            @if($shift->review_remarks)<p>Remarks: {{ $shift->review_remarks }}</p>@endif
            @if($shift->rejection_reason)<p>Reason: {{ $shift->rejection_reason }}</p>@endif
            <p>Original cash variance {{ format_kes($shift->cash_variance) }}. Original M-Pesa variance {{ format_kes($shift->mpesa_variance) }}.</p>
        </div>
    </div>
@endif

@if($canReviewThis)
    <div class="row sx-shift-actions">
        <div class="col-md-6">
            <form method="post" action="{{ route('shifts.approve', $shift) }}" class="sx-acc-card">
                @csrf
                <div class="sx-acc-card-head">Approve variance</div>
                <div class="sx-acc-card-body">
                    <div class="form-group">
                        <label>Approval remarks</label>
                        <textarea name="review_remarks" class="form-control" rows="2" maxlength="1000"></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
        <div class="col-md-6">
            <form method="post" action="{{ route('shifts.reject', $shift) }}" class="sx-acc-card">
                @csrf
                <div class="sx-acc-card-head">Reject variance</div>
                <div class="sx-acc-card-body">
                    <div class="form-group">
                        <label>Reason</label>
                        <textarea name="rejection_reason" class="form-control" rows="2" maxlength="1000" required></textarea>
                    </div>
                    @if($errors->has('rejection_reason'))
                        <div class="alert alert-danger">{{ $errors->first('rejection_reason') }}</div>
                    @endif
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if($canApprove && ! $shift->isOpen())
    <form method="post" action="{{ route('shifts.adjust', $shift) }}" class="sx-acc-card">
        @csrf
        <div class="sx-acc-card-head">Audited adjustment</div>
        <div class="sx-acc-card-body">
            <p>This note is stored separately. It does not change the opening balance, the counted balance, or the calculated variance.</p>
            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        <label>Cash note (KSh)</label>
                        <input type="number" step="0.01" name="cash_amount" class="form-control" value="0">
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        <label>M-Pesa note (KSh)</label>
                        <input type="number" step="0.01" name="mpesa_amount" class="form-control" value="0">
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        <label>Reason</label>
                        <input type="text" name="reason" class="form-control" maxlength="1000" required>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-default">Record adjustment</button>
        </div>
    </form>
@endif

@if($shift->adjustments->isNotEmpty())
    <div class="sx-acc-card">
        <div class="sx-acc-card-head">Adjustment history</div>
        <div class="sx-acc-card-body table-responsive">
            <table class="table">
                <thead><tr><th>When</th><th>By</th><th class="text-right">Cash</th><th class="text-right">M-Pesa</th><th>Reason</th></tr></thead>
                <tbody>
                    @foreach($shift->adjustments as $adjustment)
                        <tr>
                            <td>{{ optional($adjustment->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ $adjustment->user->name ?? '—' }}</td>
                            <td class="text-right">{{ format_kes($adjustment->cash_amount) }}</td>
                            <td class="text-right">{{ format_kes($adjustment->mpesa_amount) }}</td>
                            <td>{{ $adjustment->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="sx-acc-card">
    <div class="sx-acc-card-head">Transactions on this shift</div>
    <div class="sx-acc-card-body table-responsive">
        <table class="table table-striped">
            <thead><tr><th>Number</th><th>Time</th><th>Status</th><th>Payments</th><th class="text-right">Total</th></tr></thead>
            <tbody>
                @forelse($shift->sales as $sale)
                    <tr>
                        <td>{{ $sale->number }}</td>
                        <td>{{ optional($sale->created_at)->format('d/m/Y H:i') }}</td>
                        <td>{{ $sale->statusLabel() }}</td>
                        <td>
                            @forelse($sale->payments as $payment)
                                {{ $paymentLabels[$payment->method] ?? ucfirst($payment->method) }} {{ format_kes($payment->amount) }}@if(! $loop->last), @endif
                            @empty
                                —
                            @endforelse
                        </td>
                        <td class="text-right">{{ format_kes($sale->total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No sales on this shift.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
