@extends('layouts.fleet')

@section('title', 'Shift Reports')

@section('content')
<link rel="stylesheet" href="{{ asset('css/sellix-shifts.css') }}?v=1">
@include('layouts.partials.page-header', [
    'title' => 'Shift Reports',
    'subtitle' => 'Cashier opening, sales, and cash-up',
    'backUrl' => route('dashboard'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Shift Reports'],
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
        return format_kes($amount) . ' ' . $label;
    };
@endphp

<div class="sx-acc-card">
    <div class="sx-acc-card-head"><i class="fa fa-filter"></i> Filters</div>
    <div class="sx-acc-card-body">
        <form method="get" action="{{ route('shifts.index') }}">
            <div class="row">
                <div class="col-md-2 col-sm-6">
                    <div class="form-group">
                        <label>From</label>
                        <input type="date" name="from" class="form-control" value="{{ $filters['from'] ?? '' }}">
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="form-group">
                        <label>To</label>
                        <input type="date" name="to" class="form-control" value="{{ $filters['to'] ?? '' }}">
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="form-group">
                        <label>Cashier</label>
                        <select name="user_id" class="form-control" @if(auth()->user()->seesOwnSalesOnly()) disabled @endif>
                            <option value="">All cashiers</option>
                            @foreach($cashiers as $cashier)
                                <option value="{{ $cashier->id }}" @if((string) ($filters['user_id'] ?? '') === (string) $cashier->id) selected @endif>{{ $cashier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="form-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="">All branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @if((string) ($filters['branch_id'] ?? '') === (string) $branch->id) selected @endif>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="form-group">
                        <label>Shift status</label>
                        <select name="status" class="form-control">
                            <option value="">Any</option>
                            <option value="open" @if(($filters['status'] ?? '') === 'open') selected @endif>Open</option>
                            <option value="closed_any" @if(($filters['status'] ?? '') === 'closed_any') selected @endif>Closed</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="form-group">
                        <label>Approval</label>
                        <select name="approval" class="form-control">
                            <option value="">Any</option>
                            <option value="pending" @if(($filters['approval'] ?? '') === 'pending') selected @endif>Pending</option>
                            <option value="approved" @if(($filters['approval'] ?? '') === 'approved') selected @endif>Approved</option>
                            <option value="rejected" @if(($filters['approval'] ?? '') === 'rejected') selected @endif>Rejected</option>
                            <option value="none" @if(($filters['approval'] ?? '') === 'none') selected @endif>No variance review</option>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('shifts.index') }}" class="btn btn-default">Reset</a>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-sm-4">
        <div class="sx-shift-figure">
            <span>Cash variance</span>
            <strong class="{{ $varianceClass($cashVarianceTotal) }}">{{ $varianceText($cashVarianceTotal) }}</strong>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="sx-shift-figure">
            <span>M-Pesa variance</span>
            <strong class="{{ $varianceClass($mpesaVarianceTotal) }}">{{ $varianceText($mpesaVarianceTotal) }}</strong>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="sx-shift-figure">
            <span>Total variance</span>
            <strong class="sx-shift-total {{ $varianceClass($varianceTotal) }}">{{ $varianceText($varianceTotal) }}</strong>
        </div>
    </div>
</div>

<div class="sx-acc-card">
    <div class="sx-acc-card-head">Shifts</div>
    <div class="sx-acc-card-body table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Shift</th>
                    <th>Cashier</th>
                    <th>Branch</th>
                    <th>Opened</th>
                    <th>Closed</th>
                    <th class="text-right">Opening cash</th>
                    <th class="text-right">Opening M-Pesa</th>
                    <th class="text-right">Cash sales</th>
                    <th class="text-right">M-Pesa sales</th>
                    <th class="text-right">Expected</th>
                    <th class="text-right">Actual</th>
                    <th>Cash variance</th>
                    <th>M-Pesa variance</th>
                    <th>Total variance</th>
                    <th>Approval</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shifts as $shift)
                    @php $report = $shift->report; @endphp
                    <tr>
                        <td>{{ $shift->number }}</td>
                        <td>{{ $shift->cashier->name ?? '—' }}</td>
                        <td>{{ $shift->branch->name ?? '—' }}</td>
                        <td>{{ optional($shift->opened_at)->format('d/m/Y H:i') }}</td>
                        <td>{{ optional($shift->closed_at)->format('d/m/Y H:i') ?: '—' }}</td>
                        <td class="text-right">{{ format_kes($report['opening_cash']) }}</td>
                        <td class="text-right">{{ format_kes($report['opening_mpesa']) }}</td>
                        <td class="text-right">{{ format_kes($report['cash_sales']) }}</td>
                        <td class="text-right">{{ format_kes($report['mpesa_sales']) }}</td>
                        <td class="text-right">{{ $report['expected_total'] === null ? '—' : format_kes($report['expected_total']) }}</td>
                        <td class="text-right">{{ $report['actual_total'] === null ? '—' : format_kes($report['actual_total']) }}</td>
                        <td class="{{ $varianceClass($report['cash_variance']) }}">{{ $varianceText($report['cash_variance']) }}</td>
                        <td class="{{ $varianceClass($report['mpesa_variance']) }}">{{ $varianceText($report['mpesa_variance']) }}</td>
                        <td class="{{ $varianceClass($report['total_variance']) }}">{{ $varianceText($report['total_variance']) }}</td>
                        <td><span class="sx-shift-badge sx-shift-{{ $shift->status }}">{{ $shift->statusLabel() }}</span></td>
                        <td style="white-space:nowrap;">
                            @if($canApprove && $shift->status === \App\Models\PosShift::STATUS_PENDING)
                                <a href="{{ route('shifts.show', $shift) }}#sx-shift-review" class="btn btn-xs btn-success">Approve</a>
                            @else
                                <a href="{{ route('shifts.show', $shift) }}" class="btn btn-xs btn-default">View</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="16">No shifts for this filter.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="11" class="text-right">Total variance</th>
                    <th class="{{ $varianceClass($cashVarianceTotal) }}">{{ $varianceText($cashVarianceTotal) }}</th>
                    <th class="{{ $varianceClass($mpesaVarianceTotal) }}">{{ $varianceText($mpesaVarianceTotal) }}</th>
                    <th class="{{ $varianceClass($varianceTotal) }}">{{ $varianceText($varianceTotal) }}</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
        {{ $shifts->links() }}
    </div>
</div>
@endsection
